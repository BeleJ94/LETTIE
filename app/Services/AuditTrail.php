<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Domain\Audit\ActivityDiff;
use App\Domain\Audit\Actor;
use App\Repositories\ActivityLogRepository;

/**
 * Writes activity_log entries. Call it inside the service transaction so the
 * change and its trace are committed (or rolled back) together.
 */
final class AuditTrail
{
    public function __construct(
        private readonly ActivityLogRepository $log,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string, mixed> $values */
    public function created(Actor $actor, string $entityType, int $entityId, ?int $siteId, array $values): void
    {
        $this->write($actor, $entityType, $entityId, 'create', $siteId, null, $values);
    }

    /**
     * Records only the fields that changed; nothing is written when nothing changed.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function updated(Actor $actor, string $entityType, int $entityId, ?int $siteId, array $before, array $after, string $action = 'update'): bool
    {
        [$old, $new] = ActivityDiff::diff($before, $after);
        if ($new === [] && $old === []) {
            return false;
        }
        $this->write($actor, $entityType, $entityId, $action, $siteId, $old, $new);
        return true;
    }

    /**
     * Free-form event (e.g. attachment added to a mail).
     *
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function event(Actor $actor, string $entityType, int $entityId, string $action, ?int $siteId, ?array $old, ?array $new): void
    {
        $this->write($actor, $entityType, $entityId, $action, $siteId, $old, $new);
    }

    /**
     * Change made by a scheduled task (no user): user_id is NULL, the task name is the user agent.
     *
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function system(string $task, string $entityType, int $entityId, string $action, ?int $siteId, ?array $old, ?array $new): void
    {
        $this->log->record($siteId, null, $entityType, $entityId, $action, $old, $new, null, 'task:' . $task, $this->clock->now());
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function write(Actor $actor, string $entityType, int $entityId, string $action, ?int $siteId, ?array $old, ?array $new): void
    {
        $this->log->record(
            $siteId,
            $actor->user->id,
            $entityType,
            $entityId,
            $action,
            $old,
            $new,
            $actor->ip,
            $actor->userAgent,
            $this->clock->now(),
        );
    }
}
