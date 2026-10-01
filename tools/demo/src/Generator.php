<?php

declare(strict_types=1);

namespace Tools\Demo;

use App\Core\Container;
use App\Domain\Assignment\AssignmentRequest;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Deadline\DueDatePolicy;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailAction;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\MailWorkflow;
use App\Domain\Mail\Priority;
use App\Services\AttachmentService;
use App\Services\MailService;
use App\Services\WorkflowService;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use SplPriorityQueue;
use Throwable;

/**
 * Event-driven simulation of mail activity through the real services.
 * Every incoming mail plans its own future (take charge, notes,
 * reassignment, outcome); events run in chronological order with the clock
 * set to their date, so numbering, history and deadlines stay consistent.
 */
final class Generator
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private SplPriorityQueue $queue;
    private int $sequence = 0;
    private readonly DateTimeZone $zone;

    /** @var array<string, int> */
    public array $stats = [
        'incoming' => 0, 'outgoing' => 0, 'replies' => 0, 'closed' => 0, 'forgotten' => 0,
        'reassigned' => 0, 'annotations' => 0, 'attachments' => 0, 'skipped_actions' => 0,
    ];

    /**
     * @param array<string, mixed> $profile
     * @param (Closure(string): void)|null $progress
     */
    public function __construct(
        private readonly Container $container,
        public readonly Organisation $org,
        private readonly DemoClock $clock,
        private readonly Random $random,
        private readonly array $profile,
        private readonly ?Closure $progress = null,
    ) {
        $this->queue = new SplPriorityQueue();
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $this->zone = new DateTimeZone('Europe/Paris');
    }

    public function random(): Random
    {
        return $this->random;
    }

    /** Runs $action with the clock at $at (events run in date order; ties keep insertion order). */
    public function schedule(DateTimeImmutable $at, Closure $action): void
    {
        $this->queue->insert([$at, $action], [-$at->getTimestamp(), -$this->sequence++]);
    }

    /**
     * Plans arrivals for every working day of [from, now), then plays all events up to now.
     *
     * @param float $dailyMean incoming mail per average working day
     */
    public function run(DateTimeImmutable $from, DateTimeImmutable $now, float $dailyMean): void
    {
        $day = $from->setTimezone($this->zone)->setTime(0, 0);
        $end = $now->setTimezone($this->zone);
        while ($day < $end) {
            $date = $day->format('Y-m-d');
            if (DueDatePolicy::isWorkingDay($date)) {
                $mean = $dailyMean * $this->profile['weekday'][(int) $day->format('N')] * $this->profile['month'][(int) $day->format('n')];
                for ($i = $this->random->poisson($mean); $i > 0; $i--) {
                    $this->schedule($this->officeTime($day), fn () => $this->arrival());
                }
                for ($i = $this->random->poisson($mean * $this->profile['outgoing_share']); $i > 0; $i--) {
                    $this->schedule($this->officeTime($day), fn () => $this->outgoing($this->site()));
                }
            }
            $day = $day->modify('+1 day');
        }

        $done = 0;
        while (!$this->queue->isEmpty()) {
            [$at, $action] = $this->queue->extract();
            if ($at > $now) {
                continue;
            }
            $this->clock->set($at);
            try {
                $action();
            } catch (Throwable $e) {
                // A planned action may no longer apply (e.g. mail closed by a reply meanwhile): count it, keep going.
                $this->stats['skipped_actions']++;
                if (getenv('DEMO_DEBUG')) {
                    fwrite(STDERR, '  skipped: ' . $e->getMessage() . "\n");
                }
            }
            if (++$done % 500 === 0 && $this->progress !== null) {
                ($this->progress)("  … {$done} events (" . $at->setTimezone($this->zone)->format('Y-m-d') . ')');
            }
        }
        $this->clock->set($now);
    }

    /* ------------------------------------------------------------------ arrivals */

    private function arrival(): void
    {
        $site = $this->site();
        $mail = $this->incoming($site);
        $this->planProcessing($mail, $site);
    }

    private function site(): string
    {
        return (string) $this->random->weighted($this->profile['sites']);
    }

    /**
     * Registers an incoming mail now (defaults drawn from the profile; $o overrides any field).
     *
     * @param array<string, mixed> $o subject, summary, correspondent, department, priority, channel,
     *                                confidentiality, due, document_date, external_reference, attachments (list of [name, kind])
     */
    public function incoming(string $site, array $o = []): Mail
    {
        $now = $this->clock->now();
        $department = $o['department'] ?? (string) $this->random->weighted($this->org->departmentWeights[$site]);
        $correspondents = $this->org->correspondents[$site];
        $input = new MailInput(
            $o['subject'] ?? $this->random->pick($this->profile['subjects']),
            array_key_exists('summary', $o) ? $o['summary'] : ($this->random->chance($this->profile['summary_share']) ? $this->random->pick($this->profile['summaries']) : null),
            $o['correspondent'] ?? $correspondents[$this->random->zipf(count($correspondents), $this->profile['correspondent_zipf'])],
            $this->org->departments[$site][$department],
            $o['channel'] ?? Channel::from((string) $this->random->weighted($this->profile['channel'])),
            $o['priority'] ?? Priority::from((string) $this->random->weighted($this->profile['priority'])),
            $o['confidentiality'] ?? Confidentiality::from((string) $this->random->weighted($this->profile['confidentiality'])),
            $o['document_date'] ?? $now->setTimezone($this->zone)->modify('-' . $this->random->int(1, 6) . ' days')->format('Y-m-d'),
            $now,
            null,
            $o['due'] ?? null,
            array_key_exists('external_reference', $o) ? $o['external_reference']
                : ($this->random->chance($this->profile['external_reference_share']) ? 'REF-' . $this->random->int(1000, 99999) : null),
        );
        $mail = $this->container->get(MailService::class)->create($this->org->actor($this->org->secretariatOf($site)), Direction::Incoming, $input, $this->org->sites[$site]);
        $this->stats['incoming']++;

        $files = $o['attachments'] ?? $this->defaultAttachments($mail);
        foreach ($files as [$name, $kind]) {
            $this->attach($mail, $site, $name, $kind);
        }
        return $mail;
    }

    public function outgoing(string $site, array $o = []): Mail
    {
        $now = $this->clock->now();
        $correspondents = $this->org->correspondents[$site];
        $actor = $this->org->secretariatOf($site);
        $mail = $this->container->get(MailService::class)->create($this->org->actor($actor), Direction::Outgoing, new MailInput(
            $o['subject'] ?? $this->random->pick($this->profile['subjects']),
            null,
            $o['correspondent'] ?? $correspondents[$this->random->zipf(count($correspondents), $this->profile['correspondent_zipf'])],
            $this->org->departments[$site][(string) $this->random->weighted($this->org->departmentWeights[$site])],
            $o['channel'] ?? Channel::from((string) $this->random->weighted($this->profile['channel'])),
            Priority::Normal,
            Confidentiality::Internal,
            null,
            null,
            $now,
            null,
            null,
        ), $this->org->sites[$site]);
        $this->stats['outgoing']++;
        if ($o['close'] ?? $this->random->chance($this->profile['outgoing_closed'])) {
            $this->schedule($now->modify('+' . $this->random->int(5, 90) . ' minutes'), fn () => $this->act($mail->id, $actor, MailAction::Close));
        }
        return $mail;
    }

    /* ---------------------------------------------------------------- processing */

    /** Plans what happens to an incoming mail: assignment now, the rest later. */
    public function planProcessing(Mail $mail, string $site, ?string $outcome = null): void
    {
        $workflow = $this->container->get(WorkflowService::class);
        $secretariat = $this->org->secretariatOf($site);
        $departmentCode = array_search($mail->departmentId, $this->org->departments[$site], true);
        $handlers = $this->org->handlersOf($site, (string) $departmentCode);
        $departmentOnly = $handlers === [] || $this->random->chance($this->profile['department_only_assignment']);
        $handler = $departmentOnly ? $secretariat : $this->random->pick($handlers);

        $workflow->assign($this->org->actor($secretariat), $mail->id, new AssignmentRequest(
            $departmentOnly ? null : $this->org->users[$handler],
            $departmentOnly ? $mail->departmentId : null,
            AssignmentRole::ForAction,
            $this->random->chance(0.4) ? 'Merci de traiter et de préparer une réponse.' : null,
        ));
        if ($this->random->chance($this->profile['information_copy']) && isset($this->org->users['management']) && $site === 'SIEGE') {
            $workflow->assign($this->org->actor($secretariat), $mail->id, new AssignmentRequest($this->org->users['management'], null, AssignmentRole::ForInformation));
        }

        $state = (object) ['handler' => $handler];
        $now = $this->clock->now();
        $start = $this->workMoment($now->modify('+' . (int) round(max(0.02, $this->random->logNormal($this->profile['start_delay_days']['median'], $this->profile['start_delay_days']['sigma'])) * 1440) . ' minutes'));
        $finish = $this->workMoment($now->modify('+' . (int) round(max(0.2, $this->random->logNormal($this->profile['processing_days']['median'], $this->profile['processing_days']['sigma'])) * 1440) . ' minutes'));
        if ($finish <= $start) {
            $finish = $this->workMoment($start->modify('+2 hours'));
        }

        $this->schedule($start, fn () => $this->act($mail->id, $state->handler, MailAction::Start));
        if ($this->random->chance($this->profile['annotation'])) {
            $at = $start->modify('+' . (int) (($finish->getTimestamp() - $start->getTimestamp()) / 2 / 60) . ' minutes');
            $private = $this->random->chance($this->profile['private_annotation']);
            $this->schedule($at, function () use ($mail, $state, $private): void {
                $this->container->get(WorkflowService::class)->annotate($this->org->actor($state->handler), $mail->id, $this->random->pick($this->profile['annotations']), $private);
                $this->stats['annotations']++;
            });
        }
        if (!$departmentOnly && $this->random->chance($this->profile['reassignment'])) {
            $others = array_values(array_diff($this->org->agentsOf($site), [$handler]));
            if ($others !== []) {
                $to = $this->random->pick($others);
                $this->schedule($start->modify('+1 hour'), function () use ($mail, $state, $to, $secretariat): void {
                    $this->container->get(WorkflowService::class)->reassign($this->org->actor($secretariat), $mail->id,
                        new AssignmentRequest($this->org->users[$to], null, AssignmentRole::ForAction), 'Plan de charge');
                    $state->handler = $to;
                    $this->stats['reassigned']++;
                });
            }
        }

        // An outcome planned after "now" simply does not happen: the mail stays open.
        $outcome ??= (string) $this->random->weighted($this->profile['outcome']);
        $this->schedule($finish, function () use ($mail, $state, $outcome, $site, $secretariat): void {
            match ($outcome) {
                'close' => $this->act($mail->id, $state->handler, MailAction::Close, 'Traité.'),
                'reply' => $this->reply($mail, $site, $secretariat),
                default => $this->stats['forgotten']++,
            };
        });
    }

    /** Applies a workflow action if it is still possible (it may not be: mail closed meanwhile). */
    public function act(int $mailId, string $actorKey, MailAction $action, ?string $comment = null): void
    {
        $mail = $this->container->get(MailService::class)->find($mailId);
        if (!MailWorkflow::can($mail->status, $action)) {
            $this->stats['skipped_actions']++;
            return;
        }
        $this->container->get(WorkflowService::class)->perform($this->org->actor($actorKey), $mailId, $action, $comment);
        if ($action === MailAction::Close) {
            $this->stats['closed']++;
        }
    }

    public function reply(Mail $incoming, string $site, string $actorKey): ?Mail
    {
        $current = $this->container->get(MailService::class)->find($incoming->id);
        if (!MailWorkflow::can($current->status, MailAction::Close)) {
            $this->stats['skipped_actions']++;
            return null;
        }
        $reply = $this->container->get(WorkflowService::class)->createReply($this->org->actor($actorKey), $incoming->id, new MailInput(
            mb_substr('Re: ' . $incoming->subject, 0, 255), null, $incoming->correspondentId, $incoming->departmentId,
            $this->random->chance(0.6) ? Channel::Email : Channel::Postal, Priority::Normal,
            $incoming->confidentiality === Confidentiality::Secret ? Confidentiality::Secret : Confidentiality::Internal,
            null, null, $this->clock->now(), null, null,
        ));
        $this->stats['replies']++;
        // A reply once sent is usually closed, like any outgoing mail.
        if ($this->random->chance($this->profile['outgoing_closed'])) {
            $this->schedule($this->clock->now()->modify('+' . $this->random->int(5, 90) . ' minutes'), fn () => $this->act($reply->id, $actorKey, \App\Domain\Mail\MailAction::Close));
        }
        return $reply;
    }

    /* ---------------------------------------------------------------- attachments */

    /** @return list<array{0: string, 1: string}> */
    private function defaultAttachments(Mail $mail): array
    {
        $files = [];
        $count = (int) $this->random->weighted($this->profile['attachments']);
        for ($i = 1; $i <= $count; $i++) {
            $image = $this->random->chance($this->profile['image_attachment']);
            $files[] = [($i === 1 ? 'Scan ' . $mail->reference : 'Annexe ' . $i) . ($image ? '.png' : '.pdf'), $image ? 'png' : 'pdf'];
        }
        return $files;
    }

    public function attach(Mail $mail, string $site, string $name, string $kind): void
    {
        $content = match ($kind) {
            'png' => (string) base64_decode(self::PNG),
            'tiff' => "II*\x00\x08\x00\x00\x00\x00\x00\x00\x00",
            default => self::PDF,
        };
        $tmp = (string) tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($tmp, $content);
        $this->container->get(AttachmentService::class)->upload($this->org->actor($this->org->secretariatOf($site)), $mail->id, [
            'name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK,
        ]);
        $this->stats['attachments']++;
    }

    /* ---------------------------------------------------------------------- time */

    /** A random registration time during office hours on a given local day. */
    private function officeTime(DateTimeImmutable $day): DateTimeImmutable
    {
        [$open, $close] = $this->profile['office_hours'];
        $minutes = (int) round(($open + $this->random->float() * ($close - $open)) * 60);
        return $day->setTime(intdiv($minutes, 60), $minutes % 60);
    }

    /** Moves an instant into office hours of a working day (people do not work at night). */
    public function workMoment(DateTimeImmutable $at): DateTimeImmutable
    {
        $local = $at->setTimezone($this->zone);
        [$open, $close] = $this->profile['office_hours'];
        $hour = (int) $local->format('G') + (int) $local->format('i') / 60;
        if ($hour >= $close) {
            $local = $local->modify('+1 day')->setTime((int) $open, 30);
        } elseif ($hour < $open) {
            $local = $local->setTime((int) $open, 30);
        }
        while (!DueDatePolicy::isWorkingDay($local->format('Y-m-d'))) {
            $local = $local->modify('+1 day')->setTime((int) $open, 30);
        }
        return $local;
    }

    public function statusOf(int $mailId): MailStatus
    {
        return $this->container->get(MailService::class)->find($mailId)->status;
    }
}
