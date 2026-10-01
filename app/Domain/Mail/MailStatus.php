<?php

declare(strict_types=1);

namespace App\Domain\Mail;

enum MailStatus: string
{
    case Registered = 'registered';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case AwaitingReply = 'awaiting_reply';
    case Answered = 'answered';
    case Closed = 'closed';
    case Archived = 'archived';

    /** See MailWorkflow for the transition rules. */
    public function canTransitionTo(self $next): bool
    {
        return MailWorkflow::canTransition($this, $next);
    }

    /** Statuses that no longer count as pending (no overdue alert). */
    public function isFinished(): bool
    {
        return in_array($this, [self::Answered, self::Closed, self::Archived], true);
    }

    /** @return list<string> */
    public static function finishedValues(): array
    {
        return array_values(array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s->isFinished()),
        ));
    }
}
