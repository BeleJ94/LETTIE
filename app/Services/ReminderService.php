<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Domain\Deadline\DueDatePolicy;
use App\Domain\Delegation\DelegationResolver;
use App\Domain\Notification\ReminderPlanner;
use App\Repositories\DeadlineRepository;
use App\Repositories\DelegationRepository;
use App\Repositories\NotificationRepository;
use DateTimeImmutable;

/**
 * Daily deadline reminders (run by bin/send-reminders.php, system scope).
 * Idempotent: running it twice the same day creates nothing new.
 */
final class ReminderService
{
    public function __construct(
        private readonly DeadlineRepository $deadlines,
        private readonly DelegationRepository $delegations,
        private readonly NotificationRepository $notifications,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param string $today "Y-m-d" in the application time zone
     * @return array{mails: int, overdue: int, due_soon: int, created: int, already_sent: int}
     */
    public function run(string $today, bool $dryRun = false): array
    {
        $until = (new DateTimeImmutable($today))->modify('+' . DueDatePolicy::SOON_DAYS . ' days')->format('Y-m-d');
        $mails = $this->deadlines->pendingDueBy($until);
        $responsible = $this->deadlines->responsibleUsers(array_column($mails, 'mail_id'));

        $delegationsBySite = [];
        $input = [];
        foreach ($mails as $mail) {
            $site = $mail['site_id'];
            $delegationsBySite[$site] ??= $this->delegations->activeMap($site, $today);
            $recipients = [];
            foreach ($responsible[$mail['mail_id']] ?? [] as $userId) {
                $recipients[] = $userId;
                // An absent recipient's delegate is told as well.
                $recipients[] = DelegationResolver::resolve($userId, $delegationsBySite[$site])['user_id'];
            }
            $input[] = $mail + ['recipients' => array_values(array_unique($recipients))];
        }

        $summary = ['mails' => count($mails), 'overdue' => 0, 'due_soon' => 0, 'created' => 0, 'already_sent' => 0];
        $countedMails = [];
        foreach (ReminderPlanner::plan($input, $today) as $item) {
            if (!isset($countedMails[$item['mail_id']])) {
                $countedMails[$item['mail_id']] = true;
                $summary[$item['type']->value]++;
            }
            $isNew = $dryRun
                ? !$this->notifications->exists($item['user_id'], $item['dedupe_key'])
                : $this->notifications->createOnce($item['user_id'], $item['type'], $item['mail_id'], $item['data'], $item['dedupe_key'], $this->clock->now());
            $summary[$isNew ? 'created' : 'already_sent']++;
        }
        return $summary;
    }
}
