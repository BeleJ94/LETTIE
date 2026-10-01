<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Deadline\DueDatePolicy;
use App\Domain\Deadline\DueStatus;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Priority;
use App\Domain\Notification\NotificationType;
use App\Domain\Notification\ReminderPlanner;
use App\Domain\Retention\RetentionAction;
use App\Domain\Retention\RetentionPolicy;
use App\Domain\Retention\RetentionRule;
use App\Domain\RuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeadlineAndRetentionTest extends TestCase
{
    /* -------------------------------------------------------------- deadlines */

    /** @return iterable<array{int, string}> */
    public static function easter(): iterable
    {
        yield [2024, '2024-03-31'];
        yield [2025, '2025-04-20'];
        yield [2026, '2026-04-05'];
        yield [2027, '2027-03-28'];
        yield [2038, '2038-04-25'];
    }

    #[DataProvider('easter')]
    public function testEasterSunday(int $year, string $expected): void
    {
        self::assertSame($expected, DueDatePolicy::easterSunday($year)->format('Y-m-d'));
    }

    public function testPublicHolidays2026(): void
    {
        $holidays = DueDatePolicy::publicHolidays(2026);
        foreach (['2026-01-01', '2026-04-06', '2026-05-01', '2026-05-08', '2026-05-14', '2026-05-25', '2026-07-14', '2026-08-15', '2026-11-01', '2026-11-11', '2026-12-25'] as $day) {
            self::assertContains($day, $holidays);
        }
        self::assertFalse(DueDatePolicy::isWorkingDay('2026-05-14'), 'Ascension');
        self::assertFalse(DueDatePolicy::isWorkingDay('2026-10-03'), 'Saturday');
        self::assertTrue(DueDatePolicy::isWorkingDay('2026-10-05'));
    }

    public function testWorkingDaysSkipWeekendsAndHolidays(): void
    {
        self::assertSame('2026-10-05', DueDatePolicy::addWorkingDays('2026-10-02', 1), 'Friday + 1 → Monday');
        self::assertSame('2026-10-16', DueDatePolicy::addWorkingDays('2026-10-02', 10));
        // Wednesday 11/11 (Armistice) is skipped.
        self::assertSame('2026-11-12', DueDatePolicy::addWorkingDays('2026-11-10', 1));
        // Christmas and New Year.
        self::assertSame('2027-01-04', DueDatePolicy::addWorkingDays('2026-12-30', 2));
    }

    public function testDefaultDueDateByPriority(): void
    {
        self::assertSame('2026-10-02', DueDatePolicy::defaultDueDate(Priority::Urgent, '2026-09-30'));
        self::assertSame('2026-10-07', DueDatePolicy::defaultDueDate(Priority::High, '2026-09-30'));
        self::assertSame('2026-10-14', DueDatePolicy::defaultDueDate(Priority::Normal, '2026-09-30'));
        self::assertSame('2026-10-28', DueDatePolicy::defaultDueDate(Priority::Low, '2026-09-30'));
    }

    public function testDueStatus(): void
    {
        self::assertSame(DueStatus::None, DueDatePolicy::status(null, '2026-09-30', false));
        self::assertSame(DueStatus::None, DueDatePolicy::status('2026-09-01', '2026-09-30', true), 'finished mail is never late');
        self::assertSame(DueStatus::Overdue, DueDatePolicy::status('2026-09-29', '2026-09-30', false));
        self::assertSame(DueStatus::Today, DueDatePolicy::status('2026-09-30', '2026-09-30', false));
        self::assertSame(DueStatus::Soon, DueDatePolicy::status('2026-10-03', '2026-09-30', false));
        self::assertSame(DueStatus::Ok, DueDatePolicy::status('2026-10-04', '2026-09-30', false));
    }

    /* -------------------------------------------------------------- reminders */

    public function testReminderPlanning(): void
    {
        $mails = [
            ['mail_id' => 1, 'reference' => 'ENT-1', 'subject' => 'A', 'due_date' => '2026-09-28', 'recipients' => [5, 6, 5]],
            ['mail_id' => 2, 'reference' => 'ENT-2', 'subject' => 'B', 'due_date' => '2026-09-30', 'recipients' => [5]],
            ['mail_id' => 3, 'reference' => 'ENT-3', 'subject' => 'C', 'due_date' => '2026-10-02', 'recipients' => [7]],
            ['mail_id' => 4, 'reference' => 'ENT-4', 'subject' => 'D', 'due_date' => '2026-12-01', 'recipients' => [7]],
            ['mail_id' => 5, 'reference' => 'ENT-5', 'subject' => 'E', 'due_date' => '2026-09-01', 'recipients' => []],
        ];
        $plan = ReminderPlanner::plan($mails, '2026-09-30');

        self::assertSame([
            ['overdue', 5, 1, 'overdue:1:2026-09-30'],
            ['overdue', 6, 1, 'overdue:1:2026-09-30'],
            ['due_soon', 5, 2, 'due_soon:2:2026-09-30'],
            ['due_soon', 7, 3, 'due_soon:3:2026-10-02'],
        ], array_map(static fn (array $p): array => [$p['type']->value, $p['user_id'], $p['mail_id'], $p['dedupe_key']], $plan));
        self::assertSame(['reference' => 'ENT-1', 'subject' => 'A', 'due_date' => '2026-09-28'], $plan[0]['data']);

        // The next day, overdue reminders get a new key (daily), "due soon" keeps its key (once).
        $tomorrow = ReminderPlanner::plan([$mails[0], $mails[2]], '2026-10-01');
        self::assertSame('overdue:1:2026-10-01', $tomorrow[0]['dedupe_key']);
        self::assertSame('due_soon:3:2026-10-02', $tomorrow[2]['dedupe_key']);
        self::assertSame(NotificationType::Overdue, $tomorrow[0]['type']);
    }

    /* -------------------------------------------------------------- retention */

    private static function rule(int $id, ?int $site, ?Direction $direction, int $months, RetentionAction $action = RetentionAction::Archive, bool $active = true): RetentionRule
    {
        return new RetentionRule($id, $site, "R{$id}", $direction, $months, $action, $active);
    }

    public function testMostSpecificRuleWins(): void
    {
        $rules = [
            self::rule(1, null, null, 24),
            self::rule(2, null, Direction::Incoming, 36),
            self::rule(3, 7, null, 12),
            self::rule(4, 7, Direction::Outgoing, 60),
            self::rule(5, 7, Direction::Outgoing, 6, active: false),
            self::rule(6, null, null, 1, RetentionAction::Review),
        ];
        $pick = static fn (int $site, Direction $d, RetentionAction $a = RetentionAction::Archive): ?int => RetentionPolicy::ruleFor($site, $d, $a, $rules)?->id;

        self::assertSame(1, $pick(1, Direction::Outgoing), 'global');
        self::assertSame(2, $pick(1, Direction::Incoming), 'direction beats global');
        self::assertSame(3, $pick(7, Direction::Incoming), 'site beats direction');
        self::assertSame(4, $pick(7, Direction::Outgoing), 'site + direction, inactive ignored');
        self::assertSame(6, $pick(7, Direction::Outgoing, RetentionAction::Review), 'per action');
        self::assertNull($pick(1, Direction::Incoming, RetentionAction::PurgeAttachments));

        // Same specificity: the shortest retention applies.
        self::assertSame(9, RetentionPolicy::ruleFor(1, Direction::Incoming, RetentionAction::Archive, [self::rule(8, null, null, 24), self::rule(9, null, null, 12)])?->id);
    }

    public function testCutoffAndValidation(): void
    {
        $rule = self::rule(1, null, null, 18);
        self::assertSame('2025-03-30', $rule->cutoff(new DateTimeImmutable('2026-09-30'))->format('Y-m-d'));

        try {
            RetentionPolicy::checkNewRule(' ', 0);
            self::fail('Expected RuleViolation');
        } catch (RuleViolation $e) {
            self::assertSame(['name', 'retention_months'], array_keys($e->violations()));
        }
    }
}
