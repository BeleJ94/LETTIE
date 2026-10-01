<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\RuleViolation;
use DateTimeImmutable;

/**
 * Status transition rules (pure). Each action lists the statuses it can be
 * applied from and the resulting status.
 *
 *   registered ─assign→ assigned ─start→ in_progress ─await_reply→ awaiting_reply
 *        │                  │  ↺ reassign     │   ↑ start ─────────────┘
 *        └──── answer / close (from any pending status) ──→ answered / closed
 *   answered ─close→ closed ─archive→ archived (final)
 *   answered, closed ─reopen→ in_progress
 */
final class MailWorkflow
{
    /** @var array<string, array<string, string>> action => [from => to] */
    private const TRANSITIONS = [
        'assign' => [
            'registered' => 'assigned',
            // Adding someone to a mail already being handled keeps its status.
            'assigned' => 'assigned',
            'in_progress' => 'in_progress',
            'awaiting_reply' => 'awaiting_reply',
        ],
        'reassign' => [
            'assigned' => 'assigned',
            'in_progress' => 'assigned',
            'awaiting_reply' => 'assigned',
        ],
        'start' => [
            'assigned' => 'in_progress',
            'awaiting_reply' => 'in_progress',
        ],
        'await_reply' => [
            'in_progress' => 'awaiting_reply',
        ],
        'answer' => [
            'registered' => 'answered',
            'assigned' => 'answered',
            'in_progress' => 'answered',
            'awaiting_reply' => 'answered',
        ],
        'close' => [
            'registered' => 'closed',
            'assigned' => 'closed',
            'in_progress' => 'closed',
            'awaiting_reply' => 'closed',
            'answered' => 'closed',
        ],
        'reopen' => [
            'answered' => 'in_progress',
            'closed' => 'in_progress',
        ],
        'archive' => [
            'closed' => 'archived',
        ],
    ];

    public static function can(MailStatus $from, MailAction $action): bool
    {
        return isset(self::TRANSITIONS[$action->value][$from->value]);
    }

    /** @throws RuleViolation when the action is not allowed from $from */
    public static function apply(MailStatus $from, MailAction $action): MailStatus
    {
        $to = self::TRANSITIONS[$action->value][$from->value] ?? null;
        if ($to === null) {
            throw RuleViolation::single('status', 'rules.workflow.not_allowed', [
                'action' => $action->value,
                'status' => $from->value,
            ]);
        }
        return MailStatus::from($to);
    }

    /** @return list<MailAction> actions available from $status, in display order */
    public static function availableActions(MailStatus $status): array
    {
        return array_values(array_filter(MailAction::cases(), static fn (MailAction $a): bool => self::can($status, $a)));
    }

    /** True when at least one action leads from $from to $to. */
    public static function canTransition(MailStatus $from, MailStatus $to): bool
    {
        foreach (self::TRANSITIONS as $map) {
            if (($map[$from->value] ?? null) === $to->value) {
                return true;
            }
        }
        return false;
    }

    /** closed_at after a transition: set when entering "closed", kept while archived, cleared on reopen. */
    public static function closedAt(MailStatus $from, MailStatus $to, ?DateTimeImmutable $current, DateTimeImmutable $now): ?DateTimeImmutable
    {
        return match (true) {
            $to === MailStatus::Closed && $from !== MailStatus::Closed => $now,
            $to === MailStatus::Closed, $to === MailStatus::Archived => $current,
            default => null,
        };
    }
}
