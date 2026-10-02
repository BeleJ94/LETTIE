<?php

declare(strict_types=1);

namespace App\Domain\Launchpad;

use App\Domain\Auth\Permission;

/**
 * Home page (Fiori Launchpad): which tiles a user sees, in which group, and in which order.
 *
 * - Groups and tiles are filtered by permission (role-based): a tile is listed when the
 *   user holds at least one of its permissions.
 * - Management by exception: the first group ("todo") holds the counters that call for an
 *   action; inside it, tiles whose counter is not zero come first, most severe first.
 * - A tile is dynamic when it has a figure (counter or KPI); otherwise it only launches a screen.
 *
 * docs/FIORI_DESIGN.md §11 documents the roles → groups mapping produced by this class.
 */
final class Launchpad
{
    public const STATE_NEGATIVE = 'Negative';
    public const STATE_CRITICAL = 'Critical';
    public const STATE_INFORMATION = 'Information';
    public const STATE_NONE = 'None';

    /** Group shown first: its tiles are sorted by exception. */
    public const GROUP_TODO = 'todo';

    /** Display order of the groups. */
    public const GROUPS = [self::GROUP_TODO, 'mail', 'steering', 'workspace', 'administration'];

    /** Severity order inside the "todo" group (lower comes first). */
    private const RANK = [self::STATE_NEGATIVE => 0, self::STATE_CRITICAL => 1, self::STATE_INFORMATION => 2, self::STATE_NONE => 3];

    /**
     * key => [group, permissions (any of), figure, state when the figure is > 0, unit, icon, href]
     *
     * @var array<string, array{0: string, 1: list<Permission>, 2: ?string, 3: string, 4: ?string, 5: string, 6: string}>
     */
    private const TILES = [
        // What calls for an action
        'my_overdue' => [self::GROUP_TODO, [Permission::MailUpdate], 'mine_overdue', self::STATE_NEGATIVE, null, 'lateness', '/mails?mine=1&overdue=1'],
        'to_assign' => [self::GROUP_TODO, [Permission::MailAssign], 'unassigned', self::STATE_CRITICAL, null, 'inbox', '/mails?direction=incoming&status=registered'],
        'scope_overdue' => [self::GROUP_TODO, [Permission::MailAssign, Permission::ReportsView], 'scope_overdue', self::STATE_NEGATIVE, null, 'alert', '/mails?overdue=1'],
        'my_today' => [self::GROUP_TODO, [Permission::MailUpdate], 'mine_today', self::STATE_CRITICAL, null, 'date-time', '/mails?mine=1'],
        'unread' => [self::GROUP_TODO, [Permission::MailView], 'unread', self::STATE_INFORMATION, null, 'bell', '/notifications'],
        // Mail
        'new_incoming' => ['mail', [Permission::MailCreate], 'registered_today', self::STATE_NONE, 'today', 'add-document', '/mails/new'],
        'new_outgoing' => ['mail', [Permission::MailCreate], null, self::STATE_NONE, null, 'paper-plane', '/mails/new?direction=outgoing'],
        'mails' => ['mail', [Permission::MailView], 'pending', self::STATE_NONE, 'pending', 'email', '/mails'],
        'my_week' => ['mail', [Permission::MailUpdate], 'mine_week', self::STATE_NONE, 'week', 'calendar', '/mails?mine=1'],
        'register' => ['mail', [Permission::MailView], null, self::STATE_NONE, null, 'course-book', '/register'],
        'correspondents' => ['mail', [Permission::CorrespondentsManage], null, self::STATE_NONE, null, 'business-card', '/correspondents'],
        // Steering
        'overview' => ['steering', [Permission::ReportsView], null, self::STATE_NONE, null, 'activities', '/overview'],
        'processing' => ['steering', [Permission::ReportsView], 'average_days', self::STATE_NONE, 'days', 'bar-chart', '/statistics'],
        'late_rate' => ['steering', [Permission::ReportsView], 'late_rate', self::STATE_NONE, 'percent', 'line-chart', '/statistics'],
        'scope_week' => ['steering', [Permission::ReportsView], 'scope_week', self::STATE_NONE, 'week', 'calendar', '/mails'],
        // Personal workspace
        'delegations' => ['workspace', [Permission::MailView], 'absences', self::STATE_NONE, 'planned', 'away', '/delegations'],
        // Administration
        'retention' => ['administration', [Permission::SettingsManage], 'retention_rules', self::STATE_NONE, 'active_rules', 'history', '/retention-rules'],
    ];

    /**
     * Figures to compute for a user (the service skips the queries nobody will see).
     *
     * @param callable(Permission): bool $can
     * @return list<string>
     */
    public static function figuresFor(callable $can): array
    {
        $figures = [];
        foreach (self::TILES as $tile) {
            if ($tile[2] !== null && self::allowed($tile[1], $can)) {
                $figures[] = $tile[2];
            }
        }
        return array_values(array_unique($figures));
    }

    /**
     * @param callable(Permission): bool $can
     * @param array<string, int|float|null> $figures figure name => value (null: no data yet)
     * @return list<array{key: string, tiles: list<array{key: string, icon: string, href: string,
     *               value: int|float|null, unit: ?string, state: string, dynamic: bool}>}>
     */
    public static function build(callable $can, array $figures): array
    {
        $groups = array_fill_keys(self::GROUPS, []);
        foreach (self::TILES as $key => [$group, $permissions, $figure, $alert, $unit, $icon, $href]) {
            if (!self::allowed($permissions, $can)) {
                continue;
            }
            $value = $figure !== null ? ($figures[$figure] ?? null) : null;
            $groups[$group][] = [
                'key' => $key,
                'icon' => $icon,
                'href' => $href,
                'value' => $value,
                'unit' => $unit,
                'state' => $value !== null && $value > 0 ? $alert : self::STATE_NONE,
                'dynamic' => $figure !== null,
            ];
        }

        // Exceptions first: stable sort by severity, so tiles of equal severity keep their declared order.
        usort($groups[self::GROUP_TODO], static fn (array $a, array $b): int => self::RANK[$a['state']] <=> self::RANK[$b['state']]);

        $result = [];
        foreach ($groups as $key => $tiles) {
            if ($tiles !== []) {
                $result[] = ['key' => $key, 'tiles' => $tiles];
            }
        }
        return $result;
    }

    /**
     * @param list<Permission> $permissions
     * @param callable(Permission): bool $can
     */
    private static function allowed(array $permissions, callable $can): bool
    {
        foreach ($permissions as $permission) {
            if ($can($permission)) {
                return true;
            }
        }
        return false;
    }
}
