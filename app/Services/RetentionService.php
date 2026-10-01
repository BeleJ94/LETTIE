<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\FileStorage;
use App\Core\Transaction;
use App\Domain\Audit\Actor;
use App\Domain\Auth\Permission;
use App\Domain\Mail\Direction;
use App\Domain\Mail\MailStatus;
use App\Domain\NotFoundException;
use App\Domain\Notification\NotificationType;
use App\Domain\Retention\RetentionAction;
use App\Domain\Retention\RetentionPolicy;
use App\Domain\Retention\RetentionRule;
use App\Repositories\MailRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\RetentionRepository;
use Throwable;

/**
 * Retention rules: administration, and daily application (bin/send-reminders.php).
 * Never destroys a mail record: the strongest automatic action deletes attachment
 * files and keeps their name, size and SHA-256; destruction is left to a human
 * after a "review" notification.
 */
final class RetentionService
{
    public const ENTITY = 'retention_rule';
    private const TASK = 'send-reminders';

    public function __construct(
        private readonly RetentionRepository $retention,
        private readonly MailRepository $mails,
        private readonly NotificationRepository $notifications,
        private readonly FileStorage $storage,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
    ) {
    }

    /* ------------------------------------------------------------ administration */

    /** @return list<RetentionRule> */
    public function rules(): array
    {
        return $this->retention->rules();
    }

    /** @param ?int $siteId null = every site (only for users with access to all sites) */
    public function create(Actor $actor, ?int $siteId, string $name, ?Direction $direction, int $months, RetentionAction $action): RetentionRule
    {
        RetentionPolicy::checkNewRule($name, $months);
        if ($siteId === null && !$actor->user->can(Permission::SitesAll)) {
            $siteId = $actor->user->siteId;
        }
        return $this->transaction->run(function () use ($actor, $siteId, $name, $direction, $months, $action): RetentionRule {
            $id = $this->retention->createRule($siteId, trim($name), $direction, $months, $action, $actor->user->id);
            $rule = $this->retention->findRule($id) ?? throw NotFoundException::of('RetentionRule', $id);
            $this->audit->created($actor, self::ENTITY, $id, $siteId, $rule->auditValues() + ['site_id' => $siteId, 'direction' => $direction?->value]);
            return $rule;
        });
    }

    public function setActive(Actor $actor, int $id, bool $active): void
    {
        $this->transaction->run(function () use ($actor, $id, $active): void {
            $rule = $this->retention->findRule($id) ?? throw NotFoundException::of('RetentionRule', $id);
            $this->retention->setActive($rule, $active);
            $this->audit->updated($actor, self::ENTITY, $id, $rule->siteId, ['is_active' => $rule->isActive], ['is_active' => $active]);
        });
    }

    /* ---------------------------------------------------------------- application */

    /**
     * @return array{archived: int, purged_mails: int, purged_files: int, review_notifications: int, errors: int, limited: bool}
     */
    public function apply(bool $dryRun = false, int $limitPerRule = 500): array
    {
        $now = $this->clock->now();
        $rules = $this->retention->rules(activeOnly: true);
        $summary = ['archived' => 0, 'purged_mails' => 0, 'purged_files' => 0, 'review_notifications' => 0, 'errors' => 0, 'limited' => false];

        // Archive before purge: mail archived today can be purged by a shorter purge rule in the same run.
        foreach ([RetentionAction::Archive, RetentionAction::PurgeAttachments, RetentionAction::Review] as $action) {
            foreach ($rules as $rule) {
                if ($rule->action !== $action) {
                    continue;
                }
                $candidates = $this->retention->candidates($rule, $rule->cutoff($now), $limitPerRule);
                $summary['limited'] = $summary['limited'] || count($candidates) >= $limitPerRule;
                foreach ($candidates as $mail) {
                    // A more specific rule for the same action takes precedence.
                    if (RetentionPolicy::ruleFor($mail['site_id'], $mail['direction'], $action, $rules)?->id !== $rule->id) {
                        continue;
                    }
                    try {
                        $this->applyTo($rule, $mail, $dryRun, $summary);
                    } catch (Throwable $e) {
                        $summary['errors']++;
                        error_log("Retention rule #{$rule->id} on mail #{$mail['id']}: " . $e->getMessage());
                    }
                }
            }
        }
        return $summary;
    }

    /**
     * @param array{id: int, site_id: int, direction: Direction, reference: string, subject: string, status: string} $mail
     * @param array<string, int|bool> $summary
     */
    private function applyTo(RetentionRule $rule, array $mail, bool $dryRun, array &$summary): void
    {
        switch ($rule->action) {
            case RetentionAction::Archive:
                if (!$dryRun) {
                    $this->transaction->run(function () use ($rule, $mail): void {
                        $current = $this->mails->findById($mail['id']) ?? throw NotFoundException::of('Mail', $mail['id']);
                        // updated_by NULL: done by the scheduled task, recorded as such in activity_log.
                        $this->mails->updateStatus($current->id, MailStatus::Archived, $current->closedAt, null);
                        $this->audit->system(self::TASK, MailService::ENTITY, $current->id, 'retention_archive', $current->siteId,
                            ['status' => $current->status->value], ['status' => MailStatus::Archived->value] + $rule->auditValues());
                    });
                }
                $summary['archived']++;
                return;

            case RetentionAction::PurgeAttachments:
                $files = $this->retention->unpurgedAttachments($mail['id']);
                if (!$dryRun) {
                    $this->transaction->run(function () use ($rule, $mail, $files): void {
                        $now = $this->clock->now();
                        foreach ($files as $file) {
                            // File first: if the database step fails, the next run finishes the job.
                            $this->storage->delete($file['stored_path']);
                            $this->retention->markPurged($file['id'], $rule->id, $now);
                        }
                        $this->audit->system(self::TASK, MailService::ENTITY, $mail['id'], 'retention_purge', $mail['site_id'], null, $rule->auditValues() + [
                            'files' => array_map(static fn (array $f): array => ['name' => $f['original_name'], 'sha256' => $f['sha256']], $files),
                        ]);
                    });
                }
                $summary['purged_mails']++;
                $summary['purged_files'] += count($files);
                return;

            case RetentionAction::Review:
                foreach ($this->retention->administrators() as $adminId) {
                    $key = "retention_review:{$mail['id']}:{$rule->id}";
                    $created = $dryRun
                        ? !$this->notifications->exists($adminId, $key)
                        : $this->notifications->createOnce($adminId, NotificationType::RetentionReview, $mail['id'], [
                            'reference' => $mail['reference'],
                            'subject' => $mail['subject'],
                            'rule' => $rule->name,
                            'months' => $rule->retentionMonths,
                        ], $key, $this->clock->now());
                    if ($created) {
                        $summary['review_notifications']++;
                    }
                }
                return;
        }
    }
}
