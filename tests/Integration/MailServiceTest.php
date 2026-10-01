<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Request;
use App\Core\TableRequest;
use App\Domain\Audit\Actor;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\MailFilter;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\Priority;
use App\Domain\NotFoundException;
use App\Domain\RuleViolation;
use App\Domain\SiteScope;
use App\Services\MailService;
use DateTimeImmutable;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;
use Tests\Support\ServiceFactory;
use Tests\Support\TestDatabase;

final class MailServiceTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private ServiceFactory $factory;
    private int $siteA;
    private int $siteB;
    private int $corrA;
    private int $corrB;
    private Actor $secretaryA;
    private Actor $secretaryB;
    private Actor $admin;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Paris');
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-09-30 10:00:00');
        $this->factory = new ServiceFactory($this->pdo, $this->clock);

        $this->siteA = TestDatabase::insertSite('A');
        $this->siteB = TestDatabase::insertSite('B');
        $this->corrA = TestDatabase::insertCorrespondent($this->siteA, 'Mairie de Lyon');
        $this->corrB = TestDatabase::insertCorrespondent($this->siteB, 'Préfecture');
        $this->secretaryA = $this->factory->actor(TestDatabase::insertUser($this->siteA, 'sa@example.org', 'password-123456', 'secretariat'));
        $this->secretaryB = $this->factory->actor(TestDatabase::insertUser($this->siteB, 'sb@example.org', 'password-123456', 'secretariat'));
        $this->admin = $this->factory->actor(TestDatabase::insertUser($this->siteA, 'admin@example.org', 'password-123456', 'admin'));
    }

    private function service(Actor $actor): MailService
    {
        return $this->factory->mail(SiteScope::forUser($actor->user));
    }

    private function input(int $correspondentId, array $over = []): MailInput
    {
        return new MailInput(...array_merge([
            'subject' => 'Demande de subvention',
            'summary' => null,
            'correspondentId' => $correspondentId,
            'departmentId' => null,
            'channel' => Channel::Postal,
            'priority' => Priority::Normal,
            'confidentiality' => Confidentiality::Internal,
            'documentDate' => '2026-09-25',
            'receivedAt' => new DateTimeImmutable('2026-09-30 08:00:00', new \DateTimeZone('UTC')),
            'sentAt' => null,
            'dueDate' => '2026-10-15',
            'externalReference' => null,
        ], $over));
    }

    private function outgoing(int $correspondentId): MailInput
    {
        return $this->input($correspondentId, ['receivedAt' => null, 'sentAt' => new DateTimeImmutable('2026-09-30 09:00:00', new \DateTimeZone('UTC'))]);
    }

    public function testNumberingPerSiteDirectionAndYear(): void
    {
        $a = $this->service($this->secretaryA);
        $b = $this->service($this->secretaryB);

        self::assertSame('ENT-2026-00001', $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA))->reference);
        self::assertSame('ENT-2026-00002', $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA))->reference);
        self::assertSame('SOR-2026-00001', $a->create($this->secretaryA, Direction::Outgoing, $this->outgoing($this->corrA))->reference);
        // Another site has its own counter.
        self::assertSame('ENT-2026-00001', $b->create($this->secretaryB, Direction::Incoming, $this->input($this->corrB))->reference);

        // New year (Paris time): the counter restarts. 23:30 UTC on 31/12 is already 2027 in Paris.
        $this->clock->advance('+3 months +1 day +13 hours +30 minutes'); // 2026-12-31 23:30 UTC
        $mail = $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA, ['receivedAt' => $this->clock->now()]));
        self::assertSame('ENT-2027-00001', $mail->reference);
        self::assertSame(2027, $mail->sequenceYear);
    }

    public function testRejectedCreationDoesNotConsumeANumber(): void
    {
        $a = $this->service($this->secretaryA);
        try {
            // Correspondent of another site: rejected inside the transaction.
            $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrB));
            self::fail('Expected RuleViolation');
        } catch (RuleViolation $e) {
            self::assertArrayHasKey('correspondent_id', $e->violations());
        }
        self::assertSame('ENT-2026-00001', $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA))->reference);
    }

    public function testNumberIsReleasedWhenTheInsertFails(): void
    {
        $a = $this->service($this->secretaryA);
        $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA));
        try {
            // Subject longer than the column in strict mode: the INSERT fails after next() reserved #2.
            $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA, ['subject' => str_repeat('x', 300)]));
            self::fail('Expected a database error');
        } catch (PDOException) {
        }
        self::assertSame('ENT-2026-00002', $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA))->reference);
    }

    public function testCreationIsLoggedWithNewValues(): void
    {
        $mail = $this->service($this->secretaryA)->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA));

        $row = $this->pdo->query('SELECT * FROM activity_log')->fetch();
        self::assertSame('mail', $row['entity_type']);
        self::assertSame($mail->id, (int) $row['entity_id']);
        self::assertSame('create', $row['action']);
        self::assertSame($this->secretaryA->user->id, (int) $row['user_id']);
        self::assertSame($this->siteA, (int) $row['site_id']);
        self::assertNull($row['old_values']);
        $new = json_decode($row['new_values'], true);
        self::assertSame('ENT-2026-00001', $new['reference']);
        self::assertSame('Demande de subvention', $new['subject']);
        self::assertSame('2026-09-30 08:00:00', $new['received_at']);
        self::assertSame('192.0.2.10', inet_ntop($row['ip_address']));
    }

    public function testUpdateLogsOnlyChangedFieldsBeforeAndAfter(): void
    {
        $service = $this->service($this->secretaryA);
        $mail = $service->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA));

        $service->update($this->secretaryA, $mail->id, $this->input($this->corrA, [
            'subject' => 'Demande de subvention 2027',
            'priority' => Priority::Urgent,
        ]));

        $row = $this->pdo->query("SELECT * FROM activity_log WHERE action = 'update'")->fetch();
        self::assertSame(['subject' => 'Demande de subvention', 'priority' => 'normal'], json_decode($row['old_values'], true));
        self::assertSame(['subject' => 'Demande de subvention 2027', 'priority' => 'urgent'], json_decode($row['new_values'], true));

        // No change: no log entry.
        $service->update($this->secretaryA, $mail->id, $this->input($this->corrA, [
            'subject' => 'Demande de subvention 2027',
            'priority' => Priority::Urgent,
        ]));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn());
    }

    public function testOtherSitesMailIsInvisibleAndCannotBeChanged(): void
    {
        $mailB = $this->service($this->secretaryB)->create($this->secretaryB, Direction::Incoming, $this->input($this->corrB));

        $this->expectException(NotFoundException::class);
        $this->service($this->secretaryA)->update($this->secretaryA, $mailB->id, $this->input($this->corrA));
    }

    public function testOnlyUnrestrictedUsersChooseTheSite(): void
    {
        $viaAdmin = $this->service($this->admin)->create($this->admin, Direction::Incoming, $this->input($this->corrB), $this->siteB);
        self::assertSame($this->siteB, $viaAdmin->siteId);

        // A secretary asking for site B still registers on site A (and B's correspondent is refused).
        $this->expectException(RuleViolation::class);
        $this->service($this->secretaryA)->create($this->secretaryA, Direction::Incoming, $this->input($this->corrB), $this->siteB);
    }

    public function testInactiveCorrespondentAndForeignDepartmentAreRejected(): void
    {
        $inactive = TestDatabase::insertCorrespondent($this->siteA, 'Ancien', active: false);
        $deptB = TestDatabase::insertDepartment($this->siteB, 'RH');
        try {
            $this->service($this->secretaryA)->create($this->secretaryA, Direction::Incoming, $this->input($inactive, ['departmentId' => $deptB]));
            self::fail('Expected RuleViolation');
        } catch (RuleViolation $e) {
            self::assertSame(['correspondent_id', 'department_id'], array_keys($e->violations()));
        }
    }

    public function testPageFiltersSearchAndScope(): void
    {
        $a = $this->service($this->secretaryA);
        $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA, ['subject' => 'Facture 100%']));
        $a->create($this->secretaryA, Direction::Outgoing, $this->outgoing($this->corrA));
        $a->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA, ['dueDate' => '2026-09-29', 'documentDate' => null]));
        $this->service($this->secretaryB)->create($this->secretaryB, Direction::Incoming, $this->input($this->corrB));

        $table = static fn (array $q = []) => TableRequest::fromRequest(new Request('GET', '/', $q), MailService::sortKeys(), 'mail_date', 'desc');

        $all = $a->page(new MailFilter(), $table());
        self::assertSame(3, $all->total, 'site B is not counted');
        self::assertSame(3, $all->filtered);

        $incoming = $a->page(new MailFilter(direction: Direction::Incoming), $table());
        self::assertSame(2, $incoming->filtered);

        $search = $a->page(new MailFilter(), $table(['q' => '100%']));
        self::assertSame(1, $search->filtered);
        self::assertSame('Facture 100%', $search->rows[0]['subject']);
        self::assertSame('Mairie de Lyon', $search->rows[0]['correspondent_name']);

        self::assertSame(1, $a->page(new MailFilter(), $table(['q' => '%']))->filtered, '% is a literal character, not a wildcard');
        self::assertSame(0, $a->page(new MailFilter(), $table(['q' => '_']))->filtered, '_ is a literal character, not a wildcard');

        $overdue = $a->page(new MailFilter(overdueBefore: '2026-09-30'), $table());
        self::assertSame(1, $overdue->filtered);

        $byRef = $a->page(new MailFilter(), $table(['sort' => 'reference', 'dir' => 'asc']));
        self::assertSame(['ENT-2026-00001', 'ENT-2026-00002', 'SOR-2026-00001'], array_column($byRef->rows, 'reference'));

        $paged = $a->page(new MailFilter(), $table(['per_page' => '2', 'page' => '2']));
        self::assertCount(1, $paged->rows);
    }

    public function testActivityLogIsAppendOnly(): void
    {
        $this->service($this->secretaryA)->create($this->secretaryA, Direction::Incoming, $this->input($this->corrA));
        foreach (['UPDATE activity_log SET action = \'x\'', 'DELETE FROM activity_log'] as $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail("{$sql} should be rejected");
            } catch (PDOException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }
}
