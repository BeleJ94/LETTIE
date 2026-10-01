<?php

declare(strict_types=1);

namespace Tools\Demo;

use App\Core\FileStorage;
use App\Core\Request;
use App\Core\TableRequest;
use App\Domain\Mail\MailFilter;
use App\Domain\Mail\MailStatus;
use App\Domain\SiteScope;
use App\Repositories\DeadlineRepository;
use App\Repositories\MailRepository;
use App\Services\DailyTaskService;
use App\Services\StatsService;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Verifies generated data: business invariants (what the application itself
 * guarantees), edge-case expectations, observed proportions vs the profile,
 * and, for larger datasets, timings of the heavy screens.
 */
final class Checks
{
    /** @var list<array{0: string, 1: bool, 2: string}> */
    private array $results = [];
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly FileStorage $storage,
    ) {
    }

    private function count(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function check(string $name, bool $ok, string $detail = ''): void
    {
        $this->results[] = [$name, $ok, $detail];
    }

    /** @return list<array{0: string, 1: bool, 2: string}> */
    public function invariants(): array
    {
        $this->results = [];

        // Numbering: per site, direction and year, numbers 1..n without gap, sequence table in sync.
        $gaps = $this->pdo->query(
            'SELECT m.site_id, m.direction, m.sequence_year, COUNT(*) AS n, MIN(m.sequence_number) AS lo, MAX(m.sequence_number) AS hi, s.last_number
             FROM mails m LEFT JOIN mail_sequences s ON s.site_id = m.site_id AND s.direction = m.direction AND s.year = m.sequence_year
             GROUP BY m.site_id, m.direction, m.sequence_year, s.last_number
             HAVING n <> hi OR lo <> 1 OR last_number IS NULL OR last_number <> hi'
        )->fetchAll();
        $this->check('numbering without gaps', $gaps === [], $gaps === [] ? '' : json_encode($gaps[0]));

        $badRef = $this->count("SELECT COUNT(*) FROM mails WHERE reference <> CONCAT(IF(direction = 'incoming', 'ENT', 'SOR'), '-', sequence_year, '-', LPAD(sequence_number, 5, '0'))");
        $this->check('references match their sequence', $badRef === 0, "{$badRef} mismatched");

        $orphanAssigned = $this->count("SELECT COUNT(*) FROM mails m WHERE m.status = 'assigned'
            AND NOT EXISTS (SELECT 1 FROM assignments a WHERE a.mail_id = m.id AND a.status = 'active' AND a.role = 'for_action')");
        $this->check('"assigned" mail has an active owner', $orphanAssigned === 0, "{$orphanAssigned} without owner");

        $finished = "'" . implode("', '", MailStatus::finishedValues()) . "'";
        $activeOnFinished = $this->count("SELECT COUNT(*) FROM assignments a JOIN mails m ON m.id = a.mail_id
            WHERE a.status = 'active' AND m.status IN ('closed', 'archived')");
        $this->check('no active assignment on closed mail', $activeOnFinished === 0, "{$activeOnFinished} found");

        $closedAt = $this->count("SELECT COUNT(*) FROM mails WHERE (status IN ('closed', 'archived') AND closed_at IS NULL)
            OR (status NOT IN ('closed', 'archived') AND closed_at IS NOT NULL)");
        $this->check('closed_at set exactly on closed mail', $closedAt === 0, "{$closedAt} inconsistent");

        $noLog = $this->count("SELECT COUNT(*) FROM mails m WHERE NOT EXISTS
            (SELECT 1 FROM activity_log l WHERE l.entity_type = 'mail' AND l.entity_id = m.id AND l.action = 'create')");
        $this->check('every mail has its creation in the history', $noLog === 0, "{$noLog} without");

        $crossSite = $this->count('SELECT COUNT(*) FROM mails m JOIN correspondents c ON c.id = m.correspondent_id
            LEFT JOIN departments d ON d.id = m.department_id WHERE c.site_id <> m.site_id OR (d.id IS NOT NULL AND d.site_id <> m.site_id)');
        $this->check('correspondent and department on the mail\'s site', $crossSite === 0, "{$crossSite} across sites");

        $badReplies = $this->count("SELECT COUNT(*) FROM mail_links l JOIN mails s ON s.id = l.source_mail_id JOIN mails t ON t.id = l.target_mail_id
            WHERE l.type = 'reply_to' AND (s.direction <> 'outgoing' OR t.direction <> 'incoming' OR t.status NOT IN ({$finished}))");
        $this->check('replies link outgoing → incoming, incoming closed', $badReplies === 0, "{$badReplies} invalid");

        // The dashboard figure equals a direct count.
        $tz = new DateTimeZone(date_default_timezone_get());
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $dashboard = (new DeadlineRepository($this->pdo, SiteScope::system()))->counters(null, $today, $today)['overdue'];
        $direct = $this->count("SELECT COUNT(*) FROM mails WHERE due_date < ? AND status NOT IN ({$finished})", [$today]);
        $this->check('dashboard overdue = direct count', $dashboard === $direct, "{$dashboard} / {$direct}");

        // Files: present unless purged; purged ones really gone.
        $missing = $kept = 0;
        foreach ($this->pdo->query('SELECT stored_path, purged_at FROM attachments')->fetchAll() as $row) {
            $exists = $this->storage->exists((string) $row['stored_path']);
            $row['purged_at'] === null ? ($missing += $exists ? 0 : 1) : ($kept += $exists ? 1 : 0);
        }
        $this->check('attachment files present (or purged)', $missing === 0 && $kept === 0, "{$missing} missing, {$kept} purged but present");

        return $this->results;
    }

    /**
     * @param array<string, Closure(): array{0: bool, 1: string}> $expectations
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public function edgeCases(array $expectations): array
    {
        $this->results = [];
        foreach ($expectations as $name => $expect) {
            try {
                [$ok, $detail] = $expect();
            } catch (\Throwable $e) {
                [$ok, $detail] = [false, $e->getMessage()];
            }
            $this->check($name, $ok, $detail);
        }
        return $this->results;
    }

    /**
     * Observed shares vs the profile (warnings only: samples are random).
     *
     * @param array<string, mixed> $profile
     * @return list<string>
     */
    public function proportions(array $profile): array
    {
        $this->warnings = [];
        $incoming = $this->count("SELECT COUNT(*) FROM mails WHERE direction = 'incoming'");
        if ($incoming < 30) {
            return ['too few mails to compare proportions'];
        }
        foreach (['priority', 'channel', 'confidentiality'] as $field) {
            $observed = $this->pdo->query("SELECT {$field} AS k, COUNT(*) AS n FROM mails WHERE direction = 'incoming' GROUP BY {$field}")->fetchAll(PDO::FETCH_KEY_PAIR);
            $total = array_sum($profile[$field]);
            foreach ($profile[$field] as $key => $weight) {
                $expected = $weight / $total;
                $actual = ($observed[$key] ?? 0) / $incoming;
                // Edge cases add a few deliberate values: allow 3 standard deviations + 2 points.
                $tolerance = 3 * sqrt($expected * (1 - $expected) / $incoming) + 0.02;
                if (abs($actual - $expected) > $tolerance) {
                    $this->warnings[] = sprintf('%s=%s: %.1f %% observed, %.1f %% expected', $field, $key, $actual * 100, $expected * 100);
                }
            }
        }
        return $this->warnings;
    }

    /** @return array<string, int|string> headline figures of the generated data */
    public function summary(): array
    {
        $byStatus = $this->pdo->query('SELECT status, COUNT(*) FROM mails GROUP BY status ORDER BY COUNT(*) DESC')->fetchAll(PDO::FETCH_KEY_PAIR);
        $perSite = $this->pdo->query('SELECT s.code, COUNT(m.id) FROM sites s LEFT JOIN mails m ON m.site_id = s.id GROUP BY s.code')->fetchAll(PDO::FETCH_KEY_PAIR);
        $list = static fn (array $pairs): string => implode(', ', array_map(static fn ($k, $v): string => "{$k} {$v}", array_keys($pairs), $pairs));
        $today =(new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
        return [
            'mails' => $this->count('SELECT COUNT(*) FROM mails'),
            'incoming / outgoing' => $this->count("SELECT COUNT(*) FROM mails WHERE direction = 'incoming'") . ' / ' . $this->count("SELECT COUNT(*) FROM mails WHERE direction = 'outgoing'"),
            'by status' => $list($byStatus),
            'overdue today' => $this->count("SELECT COUNT(*) FROM mails WHERE due_date < ? AND status NOT IN ('answered', 'closed', 'archived')", [$today]),
            'replies' => $this->count("SELECT COUNT(*) FROM mail_links WHERE type = 'reply_to'"),
            'attachments (purged)' => $this->count('SELECT COUNT(*) FROM attachments') . ' (' . $this->count('SELECT COUNT(*) FROM attachments WHERE purged_at IS NOT NULL') . ')',
            'notes (private)' => $this->count('SELECT COUNT(*) FROM annotations') . ' (' . $this->count('SELECT COUNT(*) FROM annotations WHERE is_private = 1') . ')',
            'notifications' => $this->count('SELECT COUNT(*) FROM notifications'),
            'history entries' => $this->count('SELECT COUNT(*) FROM activity_log'),
            'per site' => $list($perSite),
        ];
    }

    /**
     * Timings of the heavy operations, in milliseconds.
     *
     * @return array<string, float>
     */
    public function timings(StatsService $stats, DailyTaskService $daily): array
    {
        $measure = static function (Closure $fn): float {
            $start = hrtime(true);
            $fn();
            return round((hrtime(true) - $start) / 1e6, 1);
        };
        $mails = new MailRepository($this->pdo, SiteScope::system());
        $table = static fn (array $q): TableRequest => TableRequest::fromRequest(new Request('GET', '/', $q), array_keys(MailRepository::SORTABLE), 'mail_date', 'desc');
        $today = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
        return [
            'mail list, first page' => $measure(fn () => $mails->page(new MailFilter(), $table([]))),
            'mail list, search "facture"' => $measure(fn () => $mails->page(new MailFilter(), $table(['q' => 'facture']))),
            'mail list, overdue filter' => $measure(fn () => $mails->page(new MailFilter(overdueBefore: $today), $table([]))),
            'statistics, 12 months' => $measure(fn () => $stats->dashboard(...$stats->defaultRange())),
            'daily task (dry run)' => $measure(fn () => $daily->run(true)),
        ];
    }
}
