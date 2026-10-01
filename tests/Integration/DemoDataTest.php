<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Env;
use App\Core\FileStorage;
use App\Core\Kernel;
use App\Domain\SiteScope;
use App\Services\DailyTaskService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;
use Tools\Demo\Checks;
use Tools\Demo\DemoClock;
use Tools\Demo\EdgeCases;
use Tools\Demo\Generator;
use Tools\Demo\Organisation;
use Tools\Demo\Random;

/**
 * The demo data generator (tools/demo) on the test database: a short
 * simulation passes every invariant and edge-case check, the checks catch a
 * corrupted dataset, and the same seed gives the same data.
 */
final class DemoDataTest extends TestCase
{
    private PDO $pdo;
    private string $storageRoot;
    private FileStorage $storage;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Paris');
        $this->pdo = TestDatabase::reset();
        $this->storageRoot = sys_get_temp_dir() . '/lettie_demo_test_' . bin2hex(random_bytes(4));
        $this->storage = new FileStorage($this->storageRoot, uploadsOnly: false);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->storageRoot);
        }
    }

    /** Runs a short simulation; returns [edge cases, generator]. */
    private function generate(int $seed, int $days = 45): array
    {
        $profile = require dirname(__DIR__, 2) . '/tools/demo/profile.php';
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $from = $now->modify("-{$days} days");
        $clock = new DemoClock($from->modify('-1 day'));
        $container = Kernel::boot(dirname(__DIR__, 2), new Env(['DB_NAME' => 'unused', 'APP_TIMEZONE' => 'Europe/Paris']))->container();
        $container->instance(PDO::class, $this->pdo);
        $container->instance(Clock::class, $clock);
        $container->instance(SiteScope::class, SiteScope::system());
        $container->instance(FileStorage::class, $this->storage);

        $org = Organisation::create($container, $this->pdo, $clock, $profile);
        $generator = new Generator($container, $org, $clock, new Random($seed), $profile);
        $cases = new EdgeCases($generator, $container, $this->pdo, $org);
        $cases->register($from, $now);
        $generator->run($from, $now, 2.0);
        $cases->finalize($now);
        $clock->reset();
        $container->get(DailyTaskService::class)->run();
        return [$cases, $generator];
    }

    public function testGeneratedDataPassesEveryCheck(): void
    {
        [$cases, $generator] = $this->generate(2026);
        $checks = new Checks($this->pdo, $this->storage);

        foreach ($checks->invariants() as [$name, $ok, $detail]) {
            self::assertTrue($ok, "invariant {$name}: {$detail}");
        }
        foreach ($checks->edgeCases($cases->expectations()) as [$name, $ok, $detail]) {
            self::assertTrue($ok, "edge case {$name}: {$detail}");
        }
        self::assertGreaterThan(40, $generator->stats['incoming']);
        self::assertSame(0, $generator->stats['skipped_actions'], 'every planned action was still valid when it ran');
        self::assertContains('retention (window shorter than 230 days)', $cases->skipped);
        self::assertGreaterThan(0, $checks->summary()['notifications']);
    }

    public function testChecksCatchCorruptedData(): void
    {
        $this->generate(7, 20);
        $checks = new Checks($this->pdo, $this->storage);
        $failed = static fn (): array => array_column(array_filter($checks->invariants(), static fn (array $r): bool => !$r[1]), 0);
        self::assertSame([], $failed());

        // A gap in the numbering.
        $this->pdo->exec('UPDATE mail_sequences SET last_number = last_number + 1 LIMIT 1');
        self::assertContains('numbering without gaps', $failed());

        // An "assigned" mail without owner, and a missing attachment file.
        $this->pdo->exec("UPDATE assignments SET status = 'cancelled' WHERE role = 'for_action' AND status = 'active' LIMIT 1");
        $this->pdo->exec("UPDATE mails m SET status = 'assigned' WHERE EXISTS (SELECT 1 FROM assignments a WHERE a.mail_id = m.id AND a.status = 'cancelled')");
        $path = (string) $this->pdo->query('SELECT stored_path FROM attachments WHERE purged_at IS NULL LIMIT 1')->fetchColumn();
        $this->storage->delete($path);

        $failures = $failed();
        self::assertContains('"assigned" mail has an active owner', $failures);
        self::assertContains('attachment files present (or purged)', $failures);
    }

    public function testSameSeedSameData(): void
    {
        $fingerprint = function (): string {
            $rows = $this->pdo->query('SELECT reference, subject, status, priority, channel, confidentiality, due_date FROM mails ORDER BY site_id, direction, sequence_year, sequence_number')->fetchAll(PDO::FETCH_NUM);
            return hash('sha256', json_encode($rows));
        };
        $this->generate(99, 15);
        $first = $fingerprint();

        $this->pdo = TestDatabase::reset();
        $this->generate(99, 15);
        self::assertSame($first, $fingerprint(), 'same seed, same mail');

        $this->pdo = TestDatabase::reset();
        $this->generate(100, 15);
        self::assertNotSame($first, $fingerprint(), 'another seed, other mail');
    }
}
