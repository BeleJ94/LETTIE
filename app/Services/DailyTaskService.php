<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ScheduledRunRepository;
use DateTimeZone;
use Throwable;

/**
 * The daily scheduled task (bin/send-reminders.php): deadline reminders,
 * retention rules, housekeeping. Each run is recorded in scheduled_runs.
 * Build it with SiteScope::system(): it works on every site.
 */
final class DailyTaskService
{
    public const JOB = 'send-reminders';
    public const PARTS = ['reminders', 'retention', 'housekeeping'];
    /** Read notifications and login attempts are kept this long. */
    public const HOUSEKEEPING_DAYS = 90;

    public function __construct(
        private readonly ReminderService $reminders,
        private readonly RetentionService $retention,
        private readonly NotificationRepository $notifications,
        private readonly LoginAttemptRepository $loginAttempts,
        private readonly ScheduledRunRepository $runs,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param ?string $today "Y-m-d"; default: today in APP_TIMEZONE
     * @param list<string> $parts subset of PARTS
     * @return array{ok: bool, today: string, dry_run: bool, reminders?: array<string, int>, retention?: array<string, int|bool>, housekeeping?: array<string, int>, error?: string}
     */
    public function run(bool $dryRun = false, ?string $today = null, array $parts = self::PARTS): array
    {
        $today ??= $this->clock->now()->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
        $runId = $this->runs->start(self::JOB, $dryRun, $this->clock->now());
        $summary = ['ok' => true, 'today' => $today, 'dry_run' => $dryRun];

        try {
            if (in_array('reminders', $parts, true)) {
                $summary['reminders'] = $this->reminders->run($today, $dryRun);
            }
            if (in_array('retention', $parts, true)) {
                $summary['retention'] = $this->retention->apply($dryRun);
                $summary['ok'] = $summary['retention']['errors'] === 0;
            }
            if (in_array('housekeeping', $parts, true)) {
                $before = $this->clock->now()->modify('-' . self::HOUSEKEEPING_DAYS . ' days');
                $summary['housekeeping'] = $dryRun ? ['notifications' => 0, 'login_attempts' => 0] : [
                    'notifications' => $this->notifications->purgeReadBefore($before),
                    'login_attempts' => $this->loginAttempts->purgeOlderThan($before),
                ];
            }
        } catch (Throwable $e) {
            $summary['ok'] = false;
            $summary['error'] = $e->getMessage();
            error_log((string) $e);
        }

        $this->runs->finish($runId, $summary['ok'], $summary, $this->clock->now());
        return $summary;
    }

    /** @return list<array{started_at: \DateTimeImmutable, finished_at: ?\DateTimeImmutable, status: string, dry_run: bool, summary: array<string, mixed>}> */
    public function latestRuns(int $limit = 10): array
    {
        return $this->runs->latest(self::JOB, $limit);
    }
}
