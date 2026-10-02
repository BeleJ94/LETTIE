<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\TableRequest;
use App\Domain\Audit\Actor;
use App\Domain\Auth\Permission;
use App\Domain\Mail\Direction;
use App\Domain\Mail\MailFilter;
use App\Domain\Mail\MailStatus;
use App\Domain\Overview\Indicator;
use App\Domain\Stats\Granularity;
use App\Domain\Stats\StatsCalculator;
use App\Repositories\DeadlineRepository;
use App\Repositories\MailRepository;
use App\Repositories\StatsRepository;
use DateTimeImmutable;
use DateTimeZone;

/** Overview Page of the steering roles (reports.view): indicators, short work lists, simple charts. */
final class OverviewService
{
    /** Indicators are measured on this sliding period (days). */
    public const KPI_DAYS = 30;
    /** The volume chart shows this many weeks. */
    public const CHART_WEEKS = 8;
    /** A list card shows at most this many rows. */
    public const LIST_ROWS = 5;

    public function __construct(
        private readonly DeadlineService $deadlineService,
        private readonly DeadlineRepository $deadlines,
        private readonly MailRepository $mails,
        private readonly StatsRepository $stats,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *   today: string,
     *   kpis: list<array{key: string, value: int|float|null, unit: ?string, state: string, href: string}>,
     *   lists: list<array{key: string, href: string, total: int, rows: list<array<string, mixed>>}>,
     *   charts: array{volumes: array{labels: list<string>, incoming: list<int>, outgoing: list<int>},
     *                 overdue: list<array{name: ?string, count: int}>}
     * }
     */
    public function page(Actor $actor): array
    {
        $zoneName = date_default_timezone_get();
        $zone = new DateTimeZone($zoneName);
        $now = $this->clock->now();
        $today = $now->setTimezone($zone)->format('Y-m-d');
        $canAssign = $actor->user->can(Permission::MailAssign);

        $dashboard = $this->deadlineService->dashboard($actor);
        $scope = $dashboard['scope'] ?? ['overdue' => 0, 'today' => 0, 'week' => 0];
        $workload = $this->deadlines->workload((new DateTimeImmutable($today, $zone))->setTimezone(new DateTimeZone('UTC')));
        $processing = StatsCalculator::processing(
            $this->stats->closedIncoming($now->modify('-' . self::KPI_DAYS . ' days'), $now->modify('+1 second'), null),
            $zoneName,
        );
        $overdue = StatsCalculator::overdue($this->stats->overduePending($today, null), $today);

        $kpis = [
            ['key' => 'overdue', 'value' => $scope['overdue'], 'unit' => null, 'state' => Indicator::overdue($scope['overdue']), 'href' => '/mails?overdue=1'],
        ];
        if ($canAssign) {
            $kpis[] = ['key' => 'to_assign', 'value' => $workload['unassigned'], 'unit' => null, 'state' => Indicator::pending($workload['unassigned']), 'href' => '/mails?direction=incoming&status=registered'];
        }
        $kpis[] = ['key' => 'processing', 'value' => $processing['average_days'], 'unit' => 'days', 'state' => Indicator::processing($processing['average_days']), 'href' => '/statistics'];
        $kpis[] = ['key' => 'late_rate', 'value' => $processing['late_rate'], 'unit' => 'percent', 'state' => Indicator::lateRate($processing['late_rate']), 'href' => '/statistics'];

        $lists = [$this->listCard('overdue', '/mails?overdue=1', new MailFilter(overdueBefore: $today), 'due_date', 'asc')];
        if ($canAssign) {
            $lists[] = $this->listCard(
                'to_assign',
                '/mails?direction=incoming&status=registered',
                new MailFilter(direction: Direction::Incoming, status: MailStatus::Registered),
                'mail_date',
                'asc',
            );
        }

        $from = (new DateTimeImmutable($today))->modify('monday this week')->modify('-' . (self::CHART_WEEKS - 1) . ' weeks')->format('Y-m-d');
        [$fromUtc, $toUtc] = StatsService::bounds($from, $today, $zoneName);

        return [
            'today' => $today,
            'kpis' => $kpis,
            'lists' => $lists,
            'charts' => [
                'volumes' => StatsCalculator::volumes($this->stats->mailDates($fromUtc, $toUtc, null), $from, $today, Granularity::Week, $zoneName),
                'overdue' => array_slice($overdue['by_department'], 0, 8),
            ],
        ];
    }

    /** @return array{key: string, href: string, total: int, rows: list<array<string, mixed>>} */
    private function listCard(string $key, string $href, MailFilter $filter, string $sort, string $dir): array
    {
        $page = $this->mails->page($filter, TableRequest::first(self::LIST_ROWS, $sort, $dir));
        return ['key' => $key, 'href' => $href, 'total' => $page->filtered, 'rows' => $page->rows];
    }
}
