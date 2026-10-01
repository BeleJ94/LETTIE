<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\FileStorage;
use App\Domain\Assignment\AssignmentRequest;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Audit\Actor;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailAction;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\Priority;
use App\Domain\Retention\RetentionAction;
use App\Domain\SiteScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;
use Tests\Support\ServiceFactory;
use Tests\Support\TestDatabase;

/** Reminders, retention and the send-reminders CLI against MariaDB. */
final class DailyTaskTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private ServiceFactory $factory;
    private FileStorage $storage;
    private string $root;
    private int $site;
    private int $correspondent;
    private Actor $secretary;
    private Actor $agentA;
    private Actor $agentB;
    private Actor $admin;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Paris');
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-09-30 08:00:00');
        $this->factory = new ServiceFactory($this->pdo, $this->clock);
        $this->root = sys_get_temp_dir() . '/lettie_daily_' . bin2hex(random_bytes(4));
        $this->storage = new FileStorage($this->root, uploadsOnly: false);

        $this->site = TestDatabase::insertSite('A');
        $this->correspondent = TestDatabase::insertCorrespondent($this->site, 'Mairie');
        $this->secretary = $this->factory->actor(TestDatabase::insertUser($this->site, 'sec@example.org', 'password-123456', 'secretariat'));
        $this->agentA = $this->factory->actor(TestDatabase::insertUser($this->site, 'a@example.org', 'password-123456', 'agent'));
        $this->agentB = $this->factory->actor(TestDatabase::insertUser($this->site, 'b@example.org', 'password-123456', 'agent'));
        $this->admin = $this->factory->actor(TestDatabase::insertUser($this->site, 'admin@example.org', 'password-123456', 'admin'));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->root);
        }
    }

    private function mail(?string $due, Priority $priority = Priority::Normal): Mail
    {
        return $this->factory->mail(SiteScope::forUser($this->secretary->user))->create($this->secretary, Direction::Incoming, new MailInput(
            'Objet', null, $this->correspondent, null, Channel::Postal, $priority, Confidentiality::Internal, null,
            new DateTimeImmutable('2026-09-30 07:00:00', new DateTimeZone('UTC')), null, $due, null,
        ));
    }

    private function assign(Mail $mail, Actor $to): void
    {
        $this->factory->workflow(SiteScope::forUser($this->secretary->user))
            ->assign($this->secretary, $mail->id, new AssignmentRequest($to->user->id, null, AssignmentRole::ForAction));
    }

    /** @return list<array{user_id: int, type: string, mail_id: int}> */
    private function notifications(string $type = null): array
    {
        $sql = 'SELECT user_id, type, mail_id FROM notifications' . ($type !== null ? " WHERE type = '{$type}'" : '') . ' ORDER BY id';
        return array_map(static fn (array $r): array => ['user_id' => (int) $r['user_id'], 'type' => $r['type'], 'mail_id' => (int) $r['mail_id']], $this->pdo->query($sql)->fetchAll());
    }

    public function testDefaultDueDateIsSetFromPriority(): void
    {
        self::assertSame('2026-10-02', $this->mail(null, Priority::Urgent)->dueDate);
        self::assertSame('2026-10-20', $this->mail('2026-10-20')->dueDate, 'an explicit date is kept');
    }

    public function testAssignmentNotifiesTheAssignee(): void
    {
        $mail = $this->mail('2026-10-20');
        $this->assign($mail, $this->agentA);
        self::assertSame([['user_id' => $this->agentA->user->id, 'type' => 'assigned', 'mail_id' => $mail->id]], $this->notifications());

        $service = $this->factory->notifications(SiteScope::forUser($this->agentA->user));
        self::assertSame(1, $service->unreadCount($this->agentA));
        $note = $service->forUser($this->agentA)[0];
        self::assertSame($mail->reference, $note->data['reference']);

        // Another user cannot mark it read.
        self::assertNull($this->factory->notifications(SiteScope::forUser($this->agentB->user))->markRead($this->agentB, $note->id));
        self::assertSame($mail->id, $service->markRead($this->agentA, $note->id));
        self::assertSame(0, $service->unreadCount($this->agentA));
    }

    public function testRemindersGoToOwnersDepartmentsDispatchersAndDelegates(): void
    {
        $overdue = $this->mail('2026-09-29');
        $this->assign($overdue, $this->agentA);
        $soon = $this->mail('2026-10-02');
        $dept = TestDatabase::insertDepartment($this->site, 'RH');
        $this->pdo->exec("UPDATE users SET department_id = {$dept} WHERE id = {$this->agentB->user->id}");
        $this->factory->workflow(SiteScope::forUser($this->secretary->user))
            ->assign($this->secretary, $soon->id, new AssignmentRequest(null, $dept, AssignmentRole::ForAction));
        $unassigned = $this->mail('2026-09-28');
        $this->mail('2026-11-30'); // not due yet
        $this->pdo->exec('DELETE FROM notifications');

        $summary = $this->factory->dailyTask($this->storage)->run(false, '2026-09-30', ['reminders']);
        self::assertTrue($summary['ok']);
        self::assertSame(['mails' => 3, 'overdue' => 2, 'due_soon' => 1, 'created' => 3, 'already_sent' => 0], $summary['reminders']);

        // In due date order: owner of an overdue mail; department member; dispatcher of an unassigned mail.
        self::assertSame([
            ['user_id' => $this->secretary->user->id, 'type' => 'overdue', 'mail_id' => $unassigned->id],
            ['user_id' => $this->agentA->user->id, 'type' => 'overdue', 'mail_id' => $overdue->id],
            ['user_id' => $this->agentB->user->id, 'type' => 'due_soon', 'mail_id' => $soon->id],
        ], $this->notifications());

        // Idempotent the same day.
        $again = $this->factory->dailyTask($this->storage)->run(false, '2026-09-30', ['reminders']);
        self::assertSame(['created' => 0, 'already_sent' => 3], array_intersect_key($again['reminders'], ['created' => 0, 'already_sent' => 0]));

        // Next day, A is absent: A and B (delegate) get the overdue reminder; "due soon" is not repeated.
        $this->factory->delegation(SiteScope::forUser($this->agentA->user))->create($this->agentA, null, $this->agentB->user->id, '2026-09-30', '2026-10-05', null);
        $this->pdo->exec('DELETE FROM notifications');
        $this->pdo->exec("INSERT INTO notifications (user_id, type, mail_id, dedupe_key, created_at) VALUES ({$this->agentB->user->id}, 'due_soon', {$soon->id}, 'due_soon:{$soon->id}:2026-10-02', NOW())");
        $this->factory->dailyTask($this->storage)->run(false, '2026-10-01', ['reminders']);
        $overdueFor = array_column($this->notifications('overdue'), 'user_id', null);
        self::assertContains($this->agentA->user->id, $overdueFor);
        self::assertContains($this->agentB->user->id, $overdueFor);
        self::assertCount(1, $this->notifications('due_soon'));
    }

    public function testDryRunChangesNothing(): void
    {
        $this->assign($this->mail('2026-09-29'), $this->agentA);
        $this->pdo->exec('DELETE FROM notifications');
        $summary = $this->factory->dailyTask($this->storage)->run(true, '2026-09-30');
        self::assertSame(1, $summary['reminders']['created']);
        self::assertSame([], $this->notifications());
        $run = $this->pdo->query('SELECT status, dry_run FROM scheduled_runs')->fetch();
        self::assertSame(['status' => 'success', 'dry_run' => 1], ['status' => $run['status'], 'dry_run' => (int) $run['dry_run']]);
    }

    public function testRetentionArchivesPurgesAndAsksForReview(): void
    {
        // Two mails closed long ago, one recently.
        $old = $this->mail('2026-10-10');
        $oldOutgoing = $this->factory->mail(SiteScope::system())->create($this->secretary, Direction::Outgoing, new MailInput(
            'Sortant', null, $this->correspondent, null, Channel::Postal, Priority::Normal, Confidentiality::Internal, null,
            null, new DateTimeImmutable('2026-09-30 07:00:00', new DateTimeZone('UTC')), null, null,
        ));
        $recent = $this->mail('2026-10-10');
        $wf = $this->factory->workflow(SiteScope::forUser($this->secretary->user));
        foreach ([$old, $oldOutgoing, $recent] as $m) {
            $wf->perform($this->secretary, $m->id, MailAction::Close);
        }
        $this->pdo->exec("UPDATE mails SET closed_at = '2024-01-15 10:00:00' WHERE id IN ({$old->id}, {$oldOutgoing->id})");

        // An attachment on the old incoming mail.
        $tmp = (string) tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmp, "%PDF-1.4\n%%EOF\n");
        $this->storage->storeUpload($tmp, "{$this->site}/2024/01/file.pdf");
        $this->pdo->exec("INSERT INTO attachments (mail_id, original_name, stored_path, mime_type, size_bytes, sha256, uploaded_by)
                          VALUES ({$old->id}, 'lettre.pdf', '{$this->site}/2024/01/file.pdf', 'application/pdf', 14, '" . str_repeat('a', 64) . "', {$this->secretary->user->id})");

        $retention = $this->factory->retention(SiteScope::forUser($this->admin->user), $this->storage);
        $retention->create($this->admin, null, 'Archivage 12 mois', null, 12, RetentionAction::Archive);
        $retention->create($this->admin, null, 'Fichiers entrants 18 mois', Direction::Incoming, 18, RetentionAction::PurgeAttachments);
        $retention->create($this->admin, null, 'Revue 24 mois', null, 24, RetentionAction::Review);

        $dry = $this->factory->dailyTask($this->storage)->run(true, '2026-09-30', ['retention']);
        self::assertSame(2, $dry['retention']['archived']);
        self::assertSame('closed', $this->pdo->query("SELECT status FROM mails WHERE id = {$old->id}")->fetchColumn(), 'dry run');

        $summary = $this->factory->dailyTask($this->storage)->run(false, '2026-09-30', ['retention']);
        self::assertSame(['archived' => 2, 'purged_mails' => 1, 'purged_files' => 1, 'review_notifications' => 2, 'errors' => 0, 'limited' => false], $summary['retention']);

        $statuses = $this->pdo->query("SELECT id, status FROM mails ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('archived', $statuses[$old->id]);
        self::assertSame('archived', $statuses[$oldOutgoing->id]);
        self::assertSame('closed', $statuses[$recent->id], 'closed recently: untouched');

        // File deleted, row kept with its fingerprint; download refused.
        self::assertFalse($this->storage->exists("{$this->site}/2024/01/file.pdf"));
        $attachment = $this->pdo->query('SELECT * FROM attachments')->fetch();
        self::assertNotNull($attachment['purged_at']);
        self::assertSame(str_repeat('a', 64), $attachment['sha256']);

        // Traced as done by the task, without a user.
        $log = $this->pdo->query("SELECT user_id, user_agent, new_values FROM activity_log WHERE action = 'retention_purge'")->fetch();
        self::assertNull($log['user_id']);
        self::assertSame('task:send-reminders', $log['user_agent']);
        self::assertSame('lettre.pdf', json_decode($log['new_values'], true)['files'][0]['name']);
        self::assertNull($this->pdo->query("SELECT updated_by FROM mails WHERE id = {$old->id}")->fetchColumn());

        // Admin asked to review both old mails, once.
        self::assertCount(2, $this->notifications('retention_review'));
        $this->factory->dailyTask($this->storage)->run(false, '2026-10-01', ['retention']);
        self::assertCount(2, $this->notifications('retention_review'));
    }

    public function testCommandLineScript(): void
    {
        $this->assign($this->mail('2026-09-29'), $this->agentA);
        $php = PHP_BINARY;
        $script = dirname(__DIR__, 2) . '/bin/send-reminders.php';
        // Real environment variables win over any .env file: the script targets the test database.
        $env = [
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) getenv('TEST_DB_PORT'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) getenv('TEST_DB_USER'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'APP_TIMEZONE' => 'Europe/Paris',
            'STORAGE_PATH' => $this->root,
            'SystemRoot' => (string) getenv('SystemRoot'),
            'PATH' => (string) getenv('PATH'),
        ];
        $run = static function (array $args) use ($php, $script, $env): array {
            $process = proc_open([$php, $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            return [proc_close($process), (string) $out, (string) $err];
        };

        [$code, $out, $err] = $run(['--dry-run', '--date=2026-09-30']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('dry run: nothing changed', $out);
        self::assertStringContainsString('1 overdue', $out);

        [$code, , $err] = $run(['--date=2026-02-30']);
        self::assertSame(64, $code);
        self::assertStringContainsString('Invalid --date', $err);

        [$code, $out] = $run(['--date=2026-09-30', '--only=reminders']);
        self::assertSame(0, $code);
        self::assertStringNotContainsString('retention:', $out);
        self::assertCount(1, $this->notifications('overdue'));
    }
}
