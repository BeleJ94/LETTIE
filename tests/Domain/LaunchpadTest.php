<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Auth\Permission;
use App\Domain\Auth\Role;
use App\Domain\Launchpad\Launchpad;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LaunchpadTest extends TestCase
{
    private const FIGURES = [
        'mine_overdue' => 0, 'mine_today' => 0, 'mine_week' => 0, 'scope_overdue' => 0, 'scope_week' => 0,
        'unread' => 0, 'unassigned' => 0, 'pending' => 0, 'registered_today' => 0,
        'average_days' => null, 'late_rate' => null, 'absences' => 0, 'retention_rules' => 0,
    ];

    /** @return array<string, array{Role, array<string, list<string>>}> role => group => tile keys (docs/FIORI_DESIGN.md §11) */
    public static function rolesProvider(): array
    {
        return [
            'agent' => [Role::Agent, [
                'todo' => ['my_overdue', 'my_today', 'unread'],
                'mail' => ['mails', 'my_week', 'register'],
                'workspace' => ['delegations'],
            ]],
            'secretariat' => [Role::Secretariat, [
                'todo' => ['my_overdue', 'to_assign', 'scope_overdue', 'my_today', 'unread'],
                'mail' => ['new_incoming', 'new_outgoing', 'mails', 'my_week', 'register', 'correspondents'],
                'workspace' => ['delegations'],
            ]],
            'head of department' => [Role::HeadOfDepartment, [
                'todo' => ['my_overdue', 'to_assign', 'scope_overdue', 'my_today', 'unread'],
                'mail' => ['mails', 'my_week', 'register'],
                'steering' => ['overview', 'processing', 'late_rate', 'scope_week'],
                'workspace' => ['delegations'],
            ]],
            'management' => [Role::Management, [
                'todo' => ['scope_overdue', 'unread'],
                'mail' => ['mails', 'register'],
                'steering' => ['overview', 'processing', 'late_rate', 'scope_week'],
                'workspace' => ['delegations'],
            ]],
            'admin' => [Role::Admin, [
                'todo' => ['my_overdue', 'to_assign', 'scope_overdue', 'my_today', 'unread'],
                'mail' => ['new_incoming', 'new_outgoing', 'mails', 'my_week', 'register', 'correspondents'],
                'steering' => ['overview', 'processing', 'late_rate', 'scope_week'],
                'workspace' => ['delegations'],
                'administration' => ['retention'],
            ]],
        ];
    }

    /** @param array<string, list<string>> $expected */
    #[DataProvider('rolesProvider')]
    public function testEachRoleSeesItsGroupsAndTiles(Role $role, array $expected): void
    {
        $groups = Launchpad::build($role->can(...), self::FIGURES);

        $actual = [];
        foreach ($groups as $group) {
            $actual[$group['key']] = array_column($group['tiles'], 'key');
        }
        self::assertSame($expected, $actual);
    }

    public function testGroupsFollowTheDeclaredOrderAndEmptyGroupsAreDropped(): void
    {
        $groups = Launchpad::build(Role::Admin->can(...), self::FIGURES);
        self::assertSame(Launchpad::GROUPS, array_column($groups, 'key'));

        $none = Launchpad::build(static fn (Permission $p): bool => false, self::FIGURES);
        self::assertSame([], $none);
    }

    public function testExceptionsComeFirstMostSevereFirst(): void
    {
        $figures = ['unread' => 4, 'unassigned' => 2, 'scope_overdue' => 7, 'mine_today' => 1] + self::FIGURES;
        $todo = Launchpad::build(Role::Secretariat->can(...), $figures)[0];

        self::assertSame(Launchpad::GROUP_TODO, $todo['key']);
        // Negative, then Critical in declared order, then Information, then the counters at zero.
        self::assertSame(['scope_overdue', 'to_assign', 'my_today', 'unread', 'my_overdue'], array_column($todo['tiles'], 'key'));
        self::assertSame(
            [Launchpad::STATE_NEGATIVE, Launchpad::STATE_CRITICAL, Launchpad::STATE_CRITICAL, Launchpad::STATE_INFORMATION, Launchpad::STATE_NONE],
            array_column($todo['tiles'], 'state'),
        );
    }

    public function testACounterAtZeroOrWithoutDataRaisesNoAlert(): void
    {
        $groups = Launchpad::build(Role::Admin->can(...), ['scope_overdue' => null] + self::FIGURES);
        foreach ($groups as $group) {
            foreach ($group['tiles'] as $tile) {
                self::assertSame(Launchpad::STATE_NONE, $tile['state'], $tile['key']);
            }
        }
    }

    public function testOnlyTheTodoGroupRaisesAlerts(): void
    {
        $figures = array_map(static fn (): int => 9, self::FIGURES);
        foreach (Launchpad::build(Role::Admin->can(...), $figures) as $group) {
            foreach ($group['tiles'] as $tile) {
                self::assertSame($group['key'] === Launchpad::GROUP_TODO, $tile['state'] !== Launchpad::STATE_NONE, $tile['key']);
            }
        }
    }

    public function testStaticTilesHaveNoValue(): void
    {
        $mail = Launchpad::build(Role::Secretariat->can(...), self::FIGURES)[1];
        $register = array_values(array_filter($mail['tiles'], static fn (array $t): bool => $t['key'] === 'register'))[0];
        self::assertFalse($register['dynamic']);
        self::assertNull($register['value']);
        self::assertSame('/register', $register['href']);
    }

    public function testFiguresAreLimitedToWhatTheRoleSees(): void
    {
        $agent = Launchpad::figuresFor(Role::Agent->can(...));
        self::assertContains('mine_overdue', $agent);
        self::assertContains('pending', $agent);
        self::assertNotContains('unassigned', $agent);
        self::assertNotContains('average_days', $agent);
        self::assertNotContains('retention_rules', $agent);

        $management = Launchpad::figuresFor(Role::Management->can(...));
        self::assertContains('average_days', $management);
        self::assertNotContains('mine_overdue', $management);
    }
}
