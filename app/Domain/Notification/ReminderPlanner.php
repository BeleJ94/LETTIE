<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Deadline\DueDatePolicy;
use App\Domain\Deadline\DueStatus;

/**
 * Decides which deadline notifications the daily task creates.
 *
 * - overdue: one per recipient per day ("overdue:{mail}:{today}") until the mail is dealt with;
 * - due soon / today: once per recipient and due date ("due_soon:{mail}:{due}"),
 *   sent again only if the due date changes.
 */
final class ReminderPlanner
{
    /**
     * @param list<array{mail_id: int, reference: string, subject: string, due_date: string, recipients: list<int>}> $mails pending mail
     * @return list<array{type: NotificationType, user_id: int, mail_id: int, dedupe_key: string, data: array<string, string>}>
     */
    public static function plan(array $mails, string $today, int $soonDays = DueDatePolicy::SOON_DAYS): array
    {
        $planned = [];
        foreach ($mails as $mail) {
            $status = DueDatePolicy::status($mail['due_date'], $today, false, $soonDays);
            [$type, $key] = match ($status) {
                DueStatus::Overdue => [NotificationType::Overdue, "overdue:{$mail['mail_id']}:{$today}"],
                DueStatus::Today, DueStatus::Soon => [NotificationType::DueSoon, "due_soon:{$mail['mail_id']}:{$mail['due_date']}"],
                default => [null, null],
            };
            if ($type === null) {
                continue;
            }
            foreach (array_unique($mail['recipients']) as $userId) {
                $planned[] = [
                    'type' => $type,
                    'user_id' => $userId,
                    'mail_id' => $mail['mail_id'],
                    'dedupe_key' => $key,
                    'data' => ['reference' => $mail['reference'], 'subject' => $mail['subject'], 'due_date' => $mail['due_date']],
                ];
            }
        }
        return $planned;
    }
}
