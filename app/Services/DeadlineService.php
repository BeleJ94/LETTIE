<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Domain\Audit\Actor;
use App\Domain\Auth\Permission;
use App\Repositories\DeadlineRepository;
use DateTimeImmutable;
use DateTimeZone;

/** Dashboard: deadline counters and upcoming deadlines. */
final class DeadlineService
{
    public function __construct(
        private readonly DeadlineRepository $deadlines,
        private readonly WorkflowService $workflow,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *   today: string,
     *   mine: array{overdue: int, today: int, week: int},
     *   scope: ?array{overdue: int, today: int, week: int},
     *   upcoming: list<array{id: int, reference: string, subject: string, due_date: string, status: string, priority: string}>
     * }
     */
    public function dashboard(Actor $actor): array
    {
        $today = $this->today();
        $weekEnd = (new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
        $mine = $this->workflow->coveredUserIds($actor);
        // Dispatchers also see the totals of their whole scope.
        $isDispatcher = $actor->user->can(Permission::MailAssign);

        return [
            'today' => $today,
            'mine' => $this->deadlines->counters($mine, $today, $weekEnd),
            'scope' => $isDispatcher || $actor->user->can(Permission::ReportsView) ? $this->deadlines->counters(null, $today, $weekEnd) : null,
            'upcoming' => $this->deadlines->upcoming($isDispatcher ? null : $mine, $weekEnd),
        ];
    }

    /** Overdue mail of the user (and of the absent colleagues they replace): counter of the side navigation. */
    public function mineOverdue(Actor $actor): int
    {
        $today = $this->today();
        return $this->deadlines->counters($this->workflow->coveredUserIds($actor), $today, $today)['overdue'];
    }

    public function today(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }
}
