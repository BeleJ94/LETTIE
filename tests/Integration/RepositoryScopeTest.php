<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\FileStorage;
use App\Core\Request;
use App\Core\TableRequest;
use App\Domain\Assignment\AssignmentRequest;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Assignment\AssignmentStatus;
use App\Domain\Audit\Actor;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailFilter;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;
use App\Domain\OutOfScopeException;
use App\Domain\Retention\RetentionAction;
use App\Domain\SiteScope;
use App\Repositories;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\FrozenClock;
use Tests\Support\ServiceFactory;
use Tests\Support\TestDatabase;

/**
 * CLAUDE.md: "SiteScope obligatoire". Two sites with the same kinds of data;
 * every repository, scoped to site A, must neither read nor change site B.
 */
final class RepositoryScopeTest extends TestCase
{
    /** Repositories whose table has no site (documented in the class). */
    private const GLOBAL_TABLES = [
        Repositories\LoginAttemptRepository::class => 'login attempts are per email/IP',
        Repositories\NotificationRepository::class => 'filtered by recipient user id',
        Repositories\PasswordResetRepository::class => 'used before sign-in; refuses any scope but the system one',
        Repositories\ScheduledRunRepository::class => 'technical task log',
    ];

    private static PDO $pdo;
    /** @var array<string, array<string, int>> site => kind => id */
    private static array $ids = [];
    private static SiteScope $scopeA;
    private static FileStorage $storage;

    public static function setUpBeforeClass(): void
    {
        date_default_timezone_set('Europe/Paris');
        self::$pdo = TestDatabase::reset();
        $clock = new FrozenClock('2026-09-30 10:00:00');
        $factory = new ServiceFactory(self::$pdo, $clock);
        self::$storage = new FileStorage(sys_get_temp_dir() . '/lettie_scope_' . bin2hex(random_bytes(4)), uploadsOnly: false);

        foreach (['A', 'B'] as $code) {
            $site = TestDatabase::insertSite($code);
            $dept = TestDatabase::insertDepartment($site, 'D' . $code);
            $correspondent = TestDatabase::insertCorrespondent($site, 'Mairie ' . $code);
            $sec = $factory->actor(TestDatabase::insertUser($site, "sec{$code}@example.org", 'password-123456', 'secretariat'));
            $agent = $factory->actor(TestDatabase::insertUser($site, "agent{$code}@example.org", 'password-123456', 'agent'));
            $other = $factory->actor(TestDatabase::insertUser($site, "other{$code}@example.org", 'password-123456', 'agent'));
            $scope = SiteScope::sites($site);

            $mails = $factory->mail($scope);
            $in = $mails->create($sec, Direction::Incoming, self::input($correspondent, $dept, true));
            $out = $mails->create($sec, Direction::Outgoing, self::input($correspondent, $dept, false));
            $wf = $factory->workflow($scope);
            $wf->assign($sec, $in->id, new AssignmentRequest($agent->user->id, null, AssignmentRole::ForAction));
            $wf->annotate($sec, $in->id, 'Note ' . $code, false);
            $wf->linkReply($sec, $out->id, $in->id);

            $pdf = (string) tempnam(sys_get_temp_dir(), 'pdf');
            file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
            $attachment = $factory->attachment($scope, self::$storage)->upload($sec, $in->id, ['name' => 'a.pdf', 'tmp_name' => $pdf, 'error' => UPLOAD_ERR_OK]);
            $delegation = $factory->delegation($scope)->create($agent, null, $other->user->id, '2026-09-30', '2026-10-05', null);
            $rule = (new Repositories\RetentionRepository(self::$pdo, $scope))->createRule($site, 'Règle ' . $code, null, 12, RetentionAction::Archive, $sec->user->id);

            self::$ids[$code] = [
                'site' => $site, 'dept' => $dept, 'correspondent' => $correspondent, 'user' => $sec->user->id,
                'in' => $in->id, 'out' => $out->id, 'attachment' => $attachment->id, 'delegation' => $delegation->id, 'rule' => $rule,
                'assignment' => (int) self::$pdo->query("SELECT id FROM assignments WHERE mail_id = {$in->id}")->fetchColumn(),
            ];
        }
        self::$pdo->exec("UPDATE mails SET due_date = '2026-09-01', status = 'in_progress'");
        self::$scopeA = SiteScope::sites(self::$ids['A']['site']);
    }

    private static function input(int $correspondent, int $dept, bool $incoming): MailInput
    {
        $at = new DateTimeImmutable('2026-09-29 08:00:00', new DateTimeZone('UTC'));
        return new MailInput('Objet', null, $correspondent, $dept, Channel::Postal, Priority::Normal, Confidentiality::Internal,
            null, $incoming ? $at : null, $incoming ? null : $at, '2026-10-30', null);
    }

    /** @template T of Repositories\Repository @param class-string<T> $class @return T */
    private static function repo(string $class): object
    {
        return new $class(self::$pdo, self::$scopeA);
    }

    private static function b(string $kind): int
    {
        return self::$ids['B'][$kind];
    }

    private static function table(array $query = []): TableRequest
    {
        return TableRequest::fromRequest(new Request('GET', '/', $query), ['reference', 'mail_date', 'name'], 'reference');
    }

    public function testMailRepository(): void
    {
        $r = self::repo(Repositories\MailRepository::class);
        self::assertNull($r->findById(self::b('in')));
        self::assertNull($r->findByReference(self::b('site'), 'ENT-2026-00001'));
        self::assertNotNull($r->findByReference(self::$ids['A']['site'], 'ENT-2026-00001'));
        $page = $r->page(new MailFilter(), self::table());
        self::assertSame([2, 2], [$page->total, $page->filtered]);
        self::assertCount(2, $r->exportRows(new MailFilter(), self::table(), 100));
        self::assertCount(1, $r->register(Direction::Incoming, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01'), 100));

        $r->updateStatus(self::b('in'), MailStatus::Closed, null, null);
        self::assertSame('in_progress', self::$pdo->query('SELECT status FROM mails WHERE id = ' . self::b('in'))->fetchColumn(), 'no write across sites');
    }

    public function testCorrespondentRepository(): void
    {
        $r = self::repo(Repositories\CorrespondentRepository::class);
        self::assertNull($r->findById(self::b('correspondent')));
        self::assertSame(['Mairie A'], array_column($r->search('Mairie'), 'name'));
        self::assertSame(1, $r->page(self::table())->total);
    }

    public function testAttachmentAssignmentAnnotationAndLinkRepositories(): void
    {
        self::assertNull(self::repo(Repositories\AttachmentRepository::class)->findById(self::b('attachment')));
        self::assertSame([], self::repo(Repositories\AttachmentRepository::class)->listForMail(self::b('in')));

        $assignments = self::repo(Repositories\AssignmentRepository::class);
        self::assertSame([], $assignments->listForMail(self::b('in')));
        self::assertNull($assignments->activeForAction(self::b('in')));
        self::assertNull($assignments->findById(self::b('assignment')));
        $statusOfB = static fn (): string => (string) self::$pdo->query('SELECT status FROM assignments WHERE id = ' . self::b('assignment'))->fetchColumn();
        $before = $statusOfB();
        $assignments->end(self::b('assignment'), AssignmentStatus::Cancelled, new DateTimeImmutable(), self::$ids['A']['user']);
        self::assertSame(0, $assignments->endAllActive(self::b('in'), AssignmentStatus::Cancelled, new DateTimeImmutable(), self::$ids['A']['user']));
        self::assertSame($before, $statusOfB(), 'no write across sites');

        self::assertSame([], self::repo(Repositories\AnnotationRepository::class)->listVisible(self::b('in'), self::b('user')));
        self::assertSame([], self::repo(Repositories\MailLinkRepository::class)->linksFor(self::b('in')));
    }

    public function testDelegationDepartmentUserAndSiteRepositories(): void
    {
        $delegations = self::repo(Repositories\DelegationRepository::class);
        self::assertNull($delegations->findById(self::b('delegation')));
        self::assertCount(1, $delegations->listFrom('2026-09-30'));
        $this->assertOutOfScope(fn () => $delegations->activeMap(self::b('site'), '2026-09-30'));

        $departments = self::repo(Repositories\DepartmentRepository::class);
        self::assertSame(['Dept DA'], array_column($departments->listActive(), 'name'));
        self::assertNull($departments->findName(self::b('dept')));
        self::assertNull($departments->findSiteId(self::b('dept')));
        self::assertSame(['Dept DA'], array_column($departments->rows(), 'name'));
        self::assertNull($departments->find(self::b('dept')));
        self::assertFalse($departments->codeTaken(self::b('site'), 'DB'), 'codes of another site are not visible');
        $this->assertOutOfScope(fn () => $departments->create(self::b('site'), 'X', 'X'));
        $departments->update(self::b('dept'), 'ZZ', 'Renamed');
        $departments->setActive(self::b('dept'), false);
        self::assertSame(['DB', '1'], array_map('strval', TestDatabase::pdo()->query('SELECT code, is_active FROM departments WHERE id = ' . self::b('dept'))->fetch(\PDO::FETCH_NUM)), 'a department out of scope is not modified');

        self::assertSame([self::$ids['A']['site']], array_values(array_unique(array_column(self::repo(Repositories\UserRepository::class)->listActive(), 'site_id'))));
        self::assertNull(self::repo(Repositories\UserRepository::class)->findById(self::b('user')));
        $users = self::repo(Repositories\UserRepository::class);
        $page = $users->page(\App\Core\TableRequest::first(100, 'name'), null, null, true);
        self::assertSame($users->countActive(), $page->toArray()['meta']['total'], 'only the accounts of site A are listed and counted');
        self::assertSame([], $users->page(\App\Core\TableRequest::first(100, 'name'), null, self::b('site'), true)->toArray()['data'], 'the site filter cannot widen the scope');
        $foreign = new \App\Domain\Auth\UserInput('X', 'Y', 'x@example.org', \App\Domain\Auth\Role::Agent, self::b('site'), null);
        $this->assertOutOfScope(fn () => $users->update(self::$ids['A']['user'], $foreign));
        $own = new \App\Domain\Auth\UserInput('X', 'Y', 'x@example.org', \App\Domain\Auth\Role::Agent, self::$ids['A']['site'], null);
        $users->update(self::b('user'), $own);
        self::assertNotSame('x@example.org', TestDatabase::pdo()->query('SELECT email FROM users WHERE id = ' . self::b('user'))->fetchColumn(), 'an account of another site is not modified');

        $sites = self::repo(Repositories\SiteRepository::class);
        self::assertSame([self::$ids['A']['site']], array_column($sites->listAll(), 'id'));
        self::assertSame([self::$ids['A']['site']], array_column($sites->rows(), 'id'));
        self::assertNull($sites->find(self::b('site')));
        $sites->update(self::b('site'), 'ZZ', 'Renamed');
        self::assertSame('B', TestDatabase::pdo()->query('SELECT code FROM sites WHERE id = ' . self::b('site'))->fetchColumn(), 'a site out of scope is not modified');
        $this->assertOutOfScope(fn () => $sites->setActive(self::b('site'), false));
        self::assertNull($sites->findName(self::b('site')));
        self::assertNull($sites->findIdByCode('B'));
        $this->assertOutOfScope(fn () => $sites->create('C', 'Site C'));
    }

    public function testReadModelRepositories(): void
    {
        $deadlines = self::repo(Repositories\DeadlineRepository::class);
        self::assertSame([self::$ids['A']['site']], array_values(array_unique(array_column($deadlines->pendingDueBy('2026-12-31'), 'site_id'))));
        self::assertSame(2, $deadlines->counters(null, '2026-09-30', '2026-10-07')['overdue']);
        self::assertCount(2, $deadlines->upcoming(null, '2026-12-31'));
        // Home page counters: only site A's mail (site B holds as many).
        $count = static fn (string $where): int => (int) self::$pdo->query(
            'SELECT COUNT(*) FROM mails WHERE site_id = ' . self::$ids['A']['site'] . ' AND ' . $where
        )->fetchColumn();
        self::assertGreaterThan($count('1 = 1'), (int) self::$pdo->query('SELECT COUNT(*) FROM mails')->fetchColumn());
        self::assertSame([
            'unassigned' => $count("status = 'registered' AND direction = 'incoming'"),
            'pending' => $count("status NOT IN ('answered', 'closed', 'archived')"),
            'registered_today' => $count('1 = 1'),
        ], $deadlines->workload(new DateTimeImmutable('2000-01-01')));

        $stats = self::repo(Repositories\StatsRepository::class);
        [$from, $to] = [new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01')];
        self::assertCount(2, $stats->mailDates($from, $to, null));
        self::assertSame(['Mairie A'], array_column($stats->topCorrespondents($from, $to, null), 'name'));
        self::assertCount(2, $stats->overduePending('2026-09-30', null));
        self::assertSame(2, array_sum(array_map(static fn (array $d): int => $d['incoming'] + $d['outgoing'], $stats->byDepartment($from, $to, '2026-09-30', null))));
    }

    public function testRetentionAndActivityRepositories(): void
    {
        $retention = self::repo(Repositories\RetentionRepository::class);
        self::assertSame(['Règle A'], array_map(static fn ($r) => $r->name, $retention->rules()));
        self::assertNull($retention->findRule(self::b('rule')));
        $this->assertOutOfScope(fn () => $retention->createRule(self::b('site'), 'x', null, 12, RetentionAction::Archive, self::$ids['A']['user']));
        $this->assertOutOfScope(fn () => $retention->createRule(null, 'x', null, 12, RetentionAction::Archive, self::$ids['A']['user']));
        self::assertSame([], $retention->unpurgedAttachments(self::b('in')));

        $log = self::repo(Repositories\ActivityLogRepository::class);
        self::assertSame([], $log->forEntity('mail', self::b('in')));
        self::assertNotSame([], $log->forEntity('mail', self::$ids['A']['in']));
        $this->assertOutOfScope(fn () => $log->record(self::b('site'), null, 'mail', 1, 'x', null, null, null, null, new DateTimeImmutable()));
    }

    public function testSequenceRepositoryRefusesOtherSites(): void
    {
        self::$pdo->beginTransaction();
        try {
            $this->assertOutOfScope(fn () => self::repo(Repositories\MailSequenceRepository::class)->next(self::b('site'), Direction::Incoming, 2026));
        } finally {
            self::$pdo->rollBack();
        }
    }

    /** Every repository is either checked above or explicitly global. */
    public function testEveryRepositoryIsCovered(): void
    {
        $source = (string) file_get_contents(__FILE__);
        foreach (glob(dirname(__DIR__, 2) . '/app/Repositories/*.php') ?: [] as $file) {
            $class = 'App\\Repositories\\' . basename($file, '.php');
            if ((new ReflectionClass($class))->isAbstract() || isset(self::GLOBAL_TABLES[$class])) {
                continue;
            }
            self::assertStringContainsString('Repositories\\' . basename($file, '.php') . '::class', $source, "{$class} has no site-isolation check");
        }
    }

    private function assertOutOfScope(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected OutOfScopeException');
        } catch (OutOfScopeException) {
            $this->addToAssertionCount(1);
        }
    }
}
