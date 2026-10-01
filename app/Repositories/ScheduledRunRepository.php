<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;

/** Technical table (no site): execution log of scheduled tasks. */
final class ScheduledRunRepository extends Repository
{
    public function start(string $job, bool $dryRun, DateTimeImmutable $at): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO scheduled_runs (job, started_at, dry_run) VALUES (:job, :at, :dry)');
        $stmt->execute(['job' => $job, 'at' => self::sqlDateTime($at), 'dry' => $dryRun ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $summary */
    public function finish(int $id, bool $success, array $summary, DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE scheduled_runs SET finished_at = :at, status = :status, summary = :summary WHERE id = :id');
        $stmt->execute([
            'at' => self::sqlDateTime($at),
            'status' => $success ? 'success' : 'failed',
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'id' => $id,
        ]);
    }

    /** @return list<array{started_at: DateTimeImmutable, finished_at: ?DateTimeImmutable, status: string, dry_run: bool, summary: array<string, mixed>}> */
    public function latest(string $job, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_runs WHERE job = :job ORDER BY started_at DESC, id DESC LIMIT ' . max(1, min($limit, 100)));
        $stmt->execute(['job' => $job]);
        return array_map(static fn (array $r): array => [
            'started_at' => self::utc($r['started_at']) ?? new DateTimeImmutable('@0'),
            'finished_at' => self::utc($r['finished_at']),
            'status' => (string) $r['status'],
            'dry_run' => (bool) $r['dry_run'],
            'summary' => $r['summary'] !== null ? (array) json_decode((string) $r['summary'], true) : [],
        ], $stmt->fetchAll());
    }
}
