<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Domain\Audit\Actor;
use App\Domain\Auth\Permission;
use App\Domain\Launchpad\Launchpad;
use App\Domain\Stats\StatsCalculator;
use App\Repositories\DeadlineRepository;
use App\Repositories\DelegationRepository;
use App\Repositories\RetentionRepository;
use App\Repositories\StatsRepository;
use DateTimeImmutable;
use DateTimeZone;

/** Home page: figures of the tiles the user may see, and the upcoming deadlines. */
final class LaunchpadService
{
    /** KPIs of the "steering" group are measured on this sliding period. */
    public const KPI_DAYS = 30;

    public function __construct(
        private readonly DeadlineService $deadlineService,
        private readonly DeadlineRepository $deadlines,
        private readonly NotificationService $notifications,
        private readonly StatsRepository $stats,
        private readonly DelegationRepository $delegations,
        private readonly RetentionRepository $retention,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Counters of the side navigation, refreshed by the browser on every screen.
     *
     * @return array{mine_overdue?: int, unassigned?: int} only the counters the user may see
     */
    public function navigationCounts(Actor $actor): array
    {
        $counts = [];
        if ($actor->user->can(Permission::MailUpdate)) {
            $counts['mine_overdue'] = $this->deadlineService->mineOverdue($actor);
        }
        if ($actor->user->can(Permission::MailAssign)) {
            $dayStart = (new DateTimeImmutable($this->deadlineService->today(), new DateTimeZone(date_default_timezone_get())))
                ->setTimezone(new DateTimeZone('UTC'));
            $counts['unassigned'] = $this->deadlines->workload($dayStart)['unassigned'];
        }
        return $counts;
    }

    /**
     * @return array{
     *   today: string,
     *   groups: list<array{key: string, tiles: list<array<string, mixed>>}>,
     *   upcoming: list<array{id: int, reference: string, subject: string, due_date: string, status: string, priority: string}>
     * }
     */
    public function home(Actor $actor): array
    {
        $can = static fn (Permission $permission): bool => $actor->user->can($permission);
        $needed = array_flip(Launchpad::figuresFor($can));
        $dashboard = $this->deadlineService->dashboard($actor);
        $today = $dashboard['today'];
        $zone = new DateTimeZone(date_default_timezone_get());
        $utc = new DateTimeZone('UTC');

        $figures = [
            'mine_overdue' => $dashboard['mine']['overdue'],
            'mine_today' => $dashboard['mine']['today'],
            'mine_week' => $dashboard['mine']['week'],
            'scope_overdue' => $dashboard['scope']['overdue'] ?? null,
            'scope_week' => $dashboard['scope']['week'] ?? null,
        ];
        if (isset($needed['unread'])) {
            $figures['unread'] = $this->notifications->unreadCount($actor);
        }
        if (isset($needed['unassigned']) || isset($needed['pending']) || isset($needed['registered_today'])) {
            $figures += $this->deadlines->workload((new DateTimeImmutable($today, $zone))->setTimezone($utc));
        }
        if (isset($needed['average_days']) || isset($needed['late_rate'])) {
            $now = $this->clock->now();
            $processing = StatsCalculator::processing(
                $this->stats->closedIncoming($now->modify('-' . self::KPI_DAYS . ' days'), $now->modify('+1 second'), null),
                $zone->getName(),
            );
            $figures['average_days'] = $processing['average_days'];
            $figures['late_rate'] = $processing['late_rate'];
        }
        if (isset($needed['absences'])) {
            $figures['absences'] = count(array_filter(
                $this->delegations->forDelegator($actor->user->id),
                static fn ($delegation): bool => $delegation->endsOn >= $today,
            ));
        }
        if (isset($needed['retention_rules'])) {
            $figures['retention_rules'] = count($this->retention->rules(activeOnly: true));
        }

        return [
            'today' => $today,
            'groups' => Launchpad::build($can, $figures),
            'upcoming' => $dashboard['upcoming'],
        ];
    }
}
