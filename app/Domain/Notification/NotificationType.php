<?php

declare(strict_types=1);

namespace App\Domain\Notification;

enum NotificationType: string
{
    /** A mail was assigned to you (or to you as a delegate). */
    case Assigned = 'assigned';
    /** A mail you handle is due today or within a few days (once per due date). */
    case DueSoon = 'due_soon';
    /** A mail you handle is overdue (every day until it is dealt with). */
    case Overdue = 'overdue';
    /** Administrators: a mail reached the end of its retention period and must be reviewed. */
    case RetentionReview = 'retention_review';

    public function icon(): string
    {
        return match ($this) {
            self::Assigned => 'inbox',
            self::DueSoon => 'clock',
            self::Overdue => 'alarm-clock',
            self::RetentionReview => 'archive',
        };
    }
}
