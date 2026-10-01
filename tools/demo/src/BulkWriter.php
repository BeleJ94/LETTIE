<?php

declare(strict_types=1);

namespace Tools\Demo;

use App\Domain\Deadline\DueDatePolicy;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Large datasets only: writes years of history in batched SQL, BEFORE the
 * simulated window, in the shape the services produce (verified by Checks):
 * gapless numbering, closed_at on closed mail, an assignment per incoming
 * mail, a "create" (and status) entry in activity_log, mail_sequences in sync.
 * No attachment files and no notifications: this layer measures volume.
 */
final class BulkWriter
{
    private const BATCH = 500;

    /** @var array<string, int> "site|direction|year" => last number */
    private array $sequences = [];

    /** @param array<string, mixed> $profile */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Organisation $org,
        private readonly Random $random,
        private readonly array $profile,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $progress
     * @return int mails written
     */
    public function write(DateTimeImmutable $from, DateTimeImmutable $to, int $target, ?\Closure $progress = null): int
    {
        $zone = new DateTimeZone('Europe/Paris');
        $utc = new DateTimeZone('UTC');

        // Spread the target over working days with the calendar weights.
        $days = [];
        $totalWeight = 0.0;
        for ($d = $from->setTimezone($zone)->setTime(0, 0); $d < $to; $d = $d->modify('+1 day')) {
            if (DueDatePolicy::isWorkingDay($d->format('Y-m-d'))) {
                $w = $this->profile['weekday'][(int) $d->format('N')] * $this->profile['month'][(int) $d->format('n')];
                $days[] = [$d, $w];
                $totalWeight += $w;
            }
        }
        $perWeight = $target / max($totalWeight, 1);

        $mails = $assignments = $logs = [];
        $written = 0;
        $handlersBySite = [];
        foreach ($this->profile['organisation'] as $site => $_) {
            foreach (array_keys($this->org->departments[$site]) as $dept) {
                $handlersBySite[$site][$dept] = $this->org->handlersOf($site, $dept) ?: [$this->org->secretariatOf($site)];
            }
        }

        foreach ($days as [$day, $weight]) {
            $count = $this->random->poisson($weight * $perWeight);
            $times = [];
            for ($i = 0; $i < $count; $i++) {
                [$open, $close] = $this->profile['office_hours'];
                $times[] = (int) round(($open + $this->random->float() * ($close - $open)) * 60);
            }
            sort($times);
            foreach ($times as $minutes) {
                $at = $day->setTime(intdiv($minutes, 60), $minutes % 60);
                $site = (string) $this->random->weighted($this->profile['sites']);
                $incoming = !$this->random->chance($this->profile['outgoing_share']);
                $direction = $incoming ? 'incoming' : 'outgoing';
                $year = (int) $at->format('Y');
                $key = "{$site}|{$direction}|{$year}";
                $number = $this->sequences[$key] = ($this->sequences[$key] ?? 0) + 1;
                $dept = (string) $this->random->weighted($this->org->departmentWeights[$site]);
                $handler = $this->random->pick($handlersBySite[$site][$dept]);
                $priority = (string) $this->random->weighted($this->profile['priority']);
                $receivedUtc = $at->setTimezone($utc)->format('Y-m-d H:i:s');
                $due = $incoming ? DueDatePolicy::defaultDueDate(\App\Domain\Mail\Priority::from($priority), $at->format('Y-m-d')) : null;
                // "Forgotten" mail is eventually dealt with, late; only recent forgotten mail is still open.
                $forgotten = $incoming && $this->random->chance($this->profile['outcome']['forgotten'] / array_sum($this->profile['outcome']));
                $days = max(0.2, $this->random->logNormal($this->profile['processing_days']['median'], $this->profile['processing_days']['sigma'])) * ($forgotten ? 6 : 1);
                $stillOpen = $forgotten && $to->getTimestamp() - $at->getTimestamp() < 120 * 86400;
                $closedAt = $stillOpen ? null : $at->modify('+' . (int) round($days * 1440) . ' minutes');
                if ($closedAt !== null && $closedAt >= $to) {
                    $closedAt = null; // would close after the history ends: still open when the simulation starts
                }
                $status = $closedAt === null ? 'in_progress' : 'closed';
                $correspondents = $this->org->correspondents[$site];

                $mails[] = [
                    $this->org->sites[$site], $direction, sprintf('%s-%04d-%05d', $incoming ? 'ENT' : 'SOR', $year, $number), $year, $number,
                    $this->random->pick($this->profile['subjects']), $correspondents[$this->random->zipf(count($correspondents), $this->profile['correspondent_zipf'])],
                    $this->org->departments[$site][$dept], (string) $this->random->weighted($this->profile['channel']), $priority,
                    (string) $this->random->weighted($this->profile['confidentiality']), $status,
                    $incoming ? $receivedUtc : null, $incoming ? null : $receivedUtc, $due,
                    $closedAt?->setTimezone($utc)->format('Y-m-d H:i:s'),
                    $this->org->users[$this->org->secretariatOf($site)], $this->org->users[$handler], $receivedUtc,
                    // Kept for the dependent rows below.
                    'incoming_flag' => $incoming, 'handler' => $handler,
                ];
                if (count($mails) >= self::BATCH) {
                    $written += $this->flush($mails);
                    $mails = [];
                    if ($progress !== null && $written % 5000 === 0) {
                        $progress("  … {$written} historical mails (" . $day->format('Y-m') . ')');
                    }
                }
            }
        }
        $written += $this->flush($mails);
        $this->syncSequences();
        return $written;
    }

    /** @param list<array<int|string, mixed>> $mails */
    private function flush(array $mails): int
    {
        if ($mails === []) {
            return 0;
        }
        $this->pdo->beginTransaction();
        $columns = '(site_id, direction, reference, sequence_year, sequence_number, subject, correspondent_id, department_id, channel, priority,
                     confidentiality, status, received_at, sent_at, due_date, closed_at, created_by, updated_by, created_at)';
        $rowSql = '(' . implode(', ', array_fill(0, 19, '?')) . ')';
        $values = [];
        foreach ($mails as $m) {
            array_push($values, ...array_slice(array_values(array_filter($m, 'is_int', ARRAY_FILTER_USE_KEY)), 0, 19));
        }
        $this->pdo->prepare("INSERT INTO mails {$columns} VALUES " . implode(', ', array_fill(0, count($mails), $rowSql)))->execute($values);
        $firstId = (int) $this->pdo->lastInsertId(); // InnoDB multi-row insert: consecutive ids from here

        $assignments = [];
        $logs = [];
        foreach ($mails as $i => $m) {
            $id = $firstId + $i;
            $closed = $m[11] === 'closed';
            if ($m['incoming_flag']) {
                array_push($assignments, $id, $this->org->users[$m['handler']], $closed ? 'completed' : 'active', $m[16], $m[18], $closed ? $m[15] : null, $closed ? $this->org->users[$m['handler']] : null);
            }
            $new = json_encode(['reference' => $m[2], 'direction' => $m[1], 'subject' => $m[5], 'status' => 'registered', 'source' => 'bulk history'], JSON_UNESCAPED_UNICODE);
            array_push($logs, $m[0], $m[16], $id, 'create', null, $new, $m[18]);
            if ($closed) {
                array_push($logs, $m[0], $m[17], $id, 'status_change', '{"status":"in_progress"}', '{"status":"closed"}', $m[15]);
            }
        }
        if ($assignments !== []) {
            $n = count($assignments) / 7;
            $this->pdo->prepare('INSERT INTO assignments (mail_id, user_id, status, assigned_by, created_at, ended_at, ended_by, role) VALUES '
                . implode(', ', array_fill(0, $n, "(?, ?, ?, ?, ?, ?, ?, 'for_action')")))->execute($assignments);
        }
        $n = count($logs) / 7;
        $this->pdo->prepare("INSERT INTO activity_log (site_id, user_id, entity_id, action, old_values, new_values, created_at, entity_type, user_agent) VALUES "
            . implode(', ', array_fill(0, $n, "(?, ?, ?, ?, ?, ?, ?, 'mail', 'task:demo-bulk')")))->execute($logs);
        $this->pdo->commit();
        return count($mails);
    }

    private function syncSequences(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO mail_sequences (site_id, direction, year, last_number) VALUES (?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE last_number = VALUES(last_number)');
        foreach ($this->sequences as $key => $last) {
            [$site, $direction, $year] = explode('|', $key);
            $stmt->execute([$this->org->sites[$site], $direction, (int) $year, $last]);
        }
    }
}
