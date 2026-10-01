<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Page;
use App\Core\TableRequest;
use App\Core\Transaction;
use App\Domain\Mail\MailFilter;
use App\Repositories\ActivityLogRepository;
use App\Domain\Audit\Actor;
use App\Domain\Deadline\DueDatePolicy;
use App\Domain\Auth\Permission;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\MailReference;
use App\Domain\Mail\MailRules;
use App\Domain\NotFoundException;
use App\Domain\RuleViolation;
use App\Repositories\CorrespondentRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\MailRepository;
use App\Repositories\MailSequenceRepository;
use DateTimeImmutable;
use DateTimeZone;

final class MailService
{
    public const ENTITY = 'mail';

    public function __construct(
        private readonly MailRepository $mails,
        private readonly MailSequenceRepository $sequences,
        private readonly CorrespondentRepository $correspondents,
        private readonly DepartmentRepository $departments,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
        private readonly ActivityLogRepository $activity,
    ) {
    }

    /**
     * Registers a mail and gives it the next reference of its site and year.
     *
     * @param ?int $siteId only honoured for users with access to every site
     * @throws RuleViolation
     */
    public function create(Actor $actor, Direction $direction, MailInput $input, ?int $siteId = null): Mail
    {
        $site = $siteId !== null && $actor->user->can(Permission::SitesAll) ? $siteId : $actor->user->siteId;
        $now = $this->clock->now();
        $local = $now->setTimezone(self::localZone());
        if ($direction === Direction::Incoming && $input->dueDate === null) {
            // No due date given: default processing time by priority, in working days.
            $input = $input->withDueDate(DueDatePolicy::defaultDueDate($input->priority, $local->format('Y-m-d')));
        }
        MailRules::check($direction, $input, $now, $local->format('Y-m-d'));

        return $this->transaction->run(function () use ($actor, $direction, $input, $site, $local): Mail {
            $this->assertReferences($site, $input, null);

            $year = (int) $local->format('Y');
            $number = $this->sequences->next($site, $direction, $year);
            $reference = MailReference::format($direction, $year, $number);

            $id = $this->mails->create($site, $direction, $reference, $year, $number, $input, $actor->user->id);
            $mail = $this->mails->findById($id) ?? throw NotFoundException::of('Mail', $id);

            $this->audit->created($actor, self::ENTITY, $id, $site, [
                'reference' => $reference,
                'direction' => $direction->value,
                'site_id' => $site,
            ] + $mail->auditValues());

            return $mail;
        });
    }

    /**
     * @throws NotFoundException
     * @throws RuleViolation
     */
    public function update(Actor $actor, int $id, MailInput $input): Mail
    {
        $now = $this->clock->now();
        $today = $now->setTimezone(self::localZone())->format('Y-m-d');

        return $this->transaction->run(function () use ($actor, $id, $input, $now, $today): Mail {
            $current = $this->mails->findById($id) ?? throw NotFoundException::of('Mail', $id);
            MailRules::check($current->direction, $input, $now, $today, $current);
            $this->assertReferences($current->siteId, $input, $current);

            // The status is untouched here: see WorkflowService.
            $this->mails->update($id, $input, $current->status, $current->closedAt, $actor->user->id);
            $updated = $this->mails->findById($id) ?? throw NotFoundException::of('Mail', $id);

            $this->audit->updated($actor, self::ENTITY, $id, $current->siteId, $current->auditValues(), $updated->auditValues());

            return $updated;
        });
    }

    public function find(int $id): Mail
    {
        return $this->mails->findById($id) ?? throw NotFoundException::of('Mail', $id);
    }

    public function page(MailFilter $filter, TableRequest $table): Page
    {
        return $this->mails->page($filter, $table);
    }

    /** @return list<string> */
    public static function sortKeys(): array
    {
        return array_keys(MailRepository::SORTABLE);
    }

    /** @return list<array{created_at: DateTimeImmutable, action: string, user_name: ?string, old_values: array<string, mixed>, new_values: array<string, mixed>}> */
    public function history(int $mailId): array
    {
        return $this->activity->forEntity(self::ENTITY, $mailId);
    }

    /** Correspondent and department must exist in the mail's site (an inactive correspondent may be kept, not chosen). */
    private function assertReferences(int $siteId, MailInput $input, ?Mail $current): void
    {
        $errors = [];
        $correspondent = $this->correspondents->findById($input->correspondentId);
        $keepsCurrent = $current !== null && $current->correspondentId === $input->correspondentId;
        if ($correspondent === null || $correspondent->siteId !== $siteId || (!$correspondent->isActive && !$keepsCurrent)) {
            $errors['correspondent_id'][] = ['rules.mail.correspondent_invalid', []];
        }
        if ($input->departmentId !== null && $this->departments->findSiteId($input->departmentId) !== $siteId) {
            $errors['department_id'][] = ['rules.mail.department_invalid', []];
        }
        if ($errors !== []) {
            throw new RuleViolation($errors);
        }
    }

    private static function localZone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }
}
