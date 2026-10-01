<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Domain\Mail\MailAccess;
use App\Domain\Mail\MailAction;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\MailWorkflow;
use App\Domain\RuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailWorkflowTest extends TestCase
{
    /**
     * Complete expected matrix: status => action => resulting status (absent = forbidden).
     * Any change to the workflow must be reflected here on purpose.
     */
    private const EXPECTED = [
        'registered' => ['assign' => 'assigned', 'answer' => 'answered', 'close' => 'closed'],
        'assigned' => ['assign' => 'assigned', 'reassign' => 'assigned', 'start' => 'in_progress', 'answer' => 'answered', 'close' => 'closed'],
        'in_progress' => ['assign' => 'in_progress', 'reassign' => 'assigned', 'await_reply' => 'awaiting_reply', 'answer' => 'answered', 'close' => 'closed'],
        'awaiting_reply' => ['assign' => 'awaiting_reply', 'reassign' => 'assigned', 'start' => 'in_progress', 'answer' => 'answered', 'close' => 'closed'],
        'answered' => ['close' => 'closed', 'reopen' => 'in_progress'],
        'closed' => ['reopen' => 'in_progress', 'archive' => 'archived'],
        'archived' => [],
    ];

    /** @return iterable<string, array{MailStatus, MailAction, ?MailStatus}> */
    public static function matrix(): iterable
    {
        foreach (MailStatus::cases() as $status) {
            foreach (MailAction::cases() as $action) {
                $to = self::EXPECTED[$status->value][$action->value] ?? null;
                yield "{$status->value} + {$action->value}" => [$status, $action, $to !== null ? MailStatus::from($to) : null];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testTransitionMatrix(MailStatus $from, MailAction $action, ?MailStatus $expected): void
    {
        self::assertSame($expected !== null, MailWorkflow::can($from, $action));
        if ($expected !== null) {
            self::assertSame($expected, MailWorkflow::apply($from, $action));
            return;
        }
        try {
            MailWorkflow::apply($from, $action);
            self::fail('Expected RuleViolation');
        } catch (RuleViolation $e) {
            self::assertSame('rules.workflow.not_allowed', $e->violations()['status'][0][0]);
            self::assertSame(['action' => $action->value, 'status' => $from->value], $e->violations()['status'][0][1]);
        }
    }

    public function testArchivedIsFinal(): void
    {
        self::assertSame([], MailWorkflow::availableActions(MailStatus::Archived));
        foreach (MailStatus::cases() as $to) {
            self::assertFalse(MailWorkflow::canTransition(MailStatus::Archived, $to));
        }
    }

    public function testAvailableActionsInDisplayOrder(): void
    {
        self::assertSame(
            [MailAction::Assign, MailAction::Reassign, MailAction::Start, MailAction::Answer, MailAction::Close],
            MailWorkflow::availableActions(MailStatus::Assigned),
        );
    }

    public function testCanTransition(): void
    {
        self::assertTrue(MailWorkflow::canTransition(MailStatus::Registered, MailStatus::Assigned));
        self::assertFalse(MailWorkflow::canTransition(MailStatus::Registered, MailStatus::InProgress), 'must be assigned first');
        self::assertFalse(MailWorkflow::canTransition(MailStatus::Registered, MailStatus::Archived), 'must be closed first');
        self::assertTrue(MailStatus::Closed->canTransitionTo(MailStatus::Archived));
    }

    public function testClosedAt(): void
    {
        $now = new DateTimeImmutable('2026-09-30 10:00:00');
        $before = new DateTimeImmutable('2026-09-01 10:00:00');
        self::assertEquals($now, MailWorkflow::closedAt(MailStatus::InProgress, MailStatus::Closed, null, $now));
        self::assertEquals($before, MailWorkflow::closedAt(MailStatus::Closed, MailStatus::Archived, $before, $now), 'kept when archived');
        self::assertNull(MailWorkflow::closedAt(MailStatus::Closed, MailStatus::InProgress, $before, $now), 'cleared on reopen');
    }

    private static function user(Role $role): User
    {
        return new User(1, 1, null, $role, 'a@b.fr', 'x', 'A', 'B');
    }

    public function testAccess(): void
    {
        // Dispatchers act on any mail of their scope.
        foreach ([Role::Admin, Role::Secretariat, Role::HeadOfDepartment] as $role) {
            self::assertTrue(MailAccess::canPerform(self::user($role), MailAction::Close, false), $role->value);
            self::assertTrue(MailAccess::canPerform(self::user($role), MailAction::Reassign, false), $role->value);
        }
        // Agents only on mail assigned to them, and never assign/reassign/archive.
        $agent = self::user(Role::Agent);
        self::assertFalse(MailAccess::canPerform($agent, MailAction::Start, false));
        self::assertTrue(MailAccess::canPerform($agent, MailAction::Start, true));
        self::assertTrue(MailAccess::canPerform($agent, MailAction::Close, true));
        self::assertFalse(MailAccess::canPerform($agent, MailAction::Reassign, true));
        self::assertFalse(MailAccess::canPerform($agent, MailAction::Archive, true));
        // Management reads and annotates but does not process.
        self::assertFalse(MailAccess::canPerform(self::user(Role::Management), MailAction::Close, true));
    }
}
