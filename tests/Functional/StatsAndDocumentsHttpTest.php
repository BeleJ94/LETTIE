<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Csrf;
use App\Core\Env;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\User;
use App\Domain\SiteScope;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/** Statistics, list export, register and slip through the Kernel with MariaDB. */
final class StatsAndDocumentsHttpTest extends TestCase
{
    private PDO $pdo;
    private Session $session;
    private int $site;
    private int $correspondent;
    private int $dept;
    private User $secretary;
    private User $agent;
    private User $director;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->site = TestDatabase::insertSite('A');
        $this->correspondent = TestDatabase::insertCorrespondent($this->site, 'Mairie de Lyon');
        $this->dept = TestDatabase::insertDepartment($this->site, 'RH');
        $users = new UserRepository($this->pdo, SiteScope::system());
        $this->secretary = $users->findById(TestDatabase::insertUser($this->site, 'sec@example.org', 'password-123456', 'secretariat'));
        $this->agent = $users->findById(TestDatabase::insertUser($this->site, 'agent@example.org', 'password-123456', 'agent'));
        $this->director = $users->findById(TestDatabase::insertUser($this->site, 'dir@example.org', 'password-123456', 'management'));
    }

    private function kernel(User $user): Kernel
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), new Env(['APP_TIMEZONE' => 'Europe/Paris', 'APP_LOCALE' => 'fr', 'DB_NAME' => 'unused']));
        $c = $kernel->container();
        $c->instance(PDO::class, $this->pdo);
        $this->session = Session::inMemory();
        $c->instance(Session::class, $this->session);
        $auth = $this->createStub(AuthService::class);
        $auth->method('user')->willReturn($user);
        $c->instance(AuthService::class, $auth);
        return $kernel;
    }

    private function get(User $user, string $path, array $query = []): Response
    {
        return $this->kernel($user)->handle(new Request('GET', $path, $query, server: ['HTTP_ACCEPT' => 'application/json, text/html']));
    }

    private function json(User $user, string $path, array $query = []): array
    {
        $response = $this->kernel($user)->handle(new Request('GET', $path, $query, server: ['HTTP_ACCEPT' => 'application/json']));
        self::assertSame(200, $response->status(), $response->body());
        return json_decode($response->body(), true);
    }

    private function createMail(string $direction, string $subject, string $confidentiality = 'internal'): int
    {
        $kernel = $this->kernel($this->secretary);
        $response = $kernel->handle(new Request('POST', '/mails', post: [
            Csrf::FIELD => (new Csrf($this->session))->token(),
            'direction' => $direction,
            'subject' => $subject,
            'correspondent_id' => (string) $this->correspondent,
            'department_id' => (string) $this->dept,
            'received_at' => $direction === 'incoming' ? date('Y-m-d', strtotime('-3 days')) . 'T09:00' : '',
            'sent_at' => $direction === 'outgoing' ? date('Y-m-d', strtotime('-1 day')) . 'T09:00' : '',
            'channel' => 'postal',
            'priority' => 'normal',
            'confidentiality' => $confidentiality,
        ]));
        self::assertSame(302, $response->status(), $response->body());
        return (int) substr((string) $response->header('Location'), strlen('/mails/'));
    }

    public function testStatisticsData(): void
    {
        $closedId = $this->createMail('incoming', 'Demande A');
        $this->createMail('incoming', 'Demande B');
        $this->createMail('outgoing', 'Réponse');
        // One closed after 2 days, one pending and overdue.
        $this->pdo->exec("UPDATE mails SET status = 'closed', closed_at = DATE_ADD(received_at, INTERVAL 2 DAY) WHERE id = {$closedId}");
        $this->pdo->exec("UPDATE mails SET due_date = '" . date('Y-m-d', strtotime('-2 days')) . "' WHERE subject = 'Demande B'");

        self::assertSame(403, $this->get($this->agent, '/statistics')->status(), 'agents have no reports.view');
        self::assertSame(200, $this->get($this->director, '/statistics')->status());

        $from = date('Y-m-d', strtotime('-30 days'));
        $data = $this->json($this->director, '/statistics/data', ['from' => $from, 'to' => date('Y-m-d')]);

        self::assertSame('day', $data['period']['granularity']);
        self::assertSame(['incoming' => 2, 'outgoing' => 1], $data['totals']);
        self::assertCount(31, $data['volumes']['labels']);
        self::assertSame([['name' => 'Dept RH', 'incoming' => 2, 'outgoing' => 1, 'pending' => 2, 'overdue' => 1]], $data['departments']);
        self::assertSame(1, $data['processing']['count']);
        self::assertSame(2.0, (float) $data['processing']['average_days']);
        self::assertSame(1, $data['overdue']['total']);
        self::assertSame(['1-7' => 1, '8-30' => 0, '31+' => 0], $data['overdue']['buckets']);
        self::assertSame([['name' => 'Mairie de Lyon', 'incoming' => 2, 'outgoing' => 1, 'total' => 3]], $data['correspondents']);

        $invalid = $this->kernel($this->director)->handle(new Request('GET', '/statistics/data', ['from' => '2026-09-30', 'to' => '2026-01-01'], server: ['HTTP_ACCEPT' => 'application/json']));
        self::assertSame(422, $invalid->status());
    }

    public function testListExportFollowsFiltersAndMasksSecretSubjects(): void
    {
        $this->createMail('incoming', 'Dossier public');
        $this->createMail('incoming', 'Enquête interne', 'secret');
        $this->createMail('outgoing', 'Courrier sortant');

        $all = $this->json($this->agent, '/mails/export', ['sort' => 'reference', 'dir' => 'asc']);
        self::assertSame(['count' => 3, 'truncated' => false, 'limit' => 5000], $all['meta']);
        self::assertSame(['ENT-' . date('Y') . '-00001', 'ENT-' . date('Y') . '-00002', 'SOR-' . date('Y') . '-00001'], array_column($all['data'], 'reference'));
        self::assertNull($all['data'][1]['subject'], 'secret subject never leaves the application');
        self::assertTrue($all['data'][1]['masked']);
        self::assertSame('Dossier public', $all['data'][0]['subject']);

        $incoming = $this->json($this->agent, '/mails/export', ['direction' => 'incoming', 'q' => 'Dossier']);
        self::assertSame(1, $incoming['meta']['count']);
        self::assertSame('Dept RH', $incoming['data'][0]['department_name']);
    }

    public function testRegister(): void
    {
        $this->createMail('incoming', 'Premier');
        $this->createMail('incoming', 'Second');
        $this->createMail('outgoing', 'Sortant');

        self::assertStringContainsString('data-set="register_{direction}"', $this->get($this->agent, '/register')->body());
        $register = $this->json($this->agent, '/register/data', ['direction' => 'incoming', 'from' => date('Y-m-d', strtotime('-10 days')), 'to' => date('Y-m-d')]);
        self::assertSame(['Premier', 'Second'], array_column($register['data'], 'subject'));
        self::assertNotNull($register['data'][0]['received_at']);

        $bad = $this->kernel($this->agent)->handle(new Request('GET', '/register/data', ['direction' => 'sideways', 'from' => 'x', 'to' => 'y'], server: ['HTTP_ACCEPT' => 'application/json']));
        self::assertSame(422, $bad->status());
    }

    public function testRegistrationSlip(): void
    {
        $id = $this->createMail('incoming', 'Demande <b>urgente</b>');
        $secret = $this->createMail('incoming', 'Sujet sensible', 'secret');

        $slip = $this->get($this->agent, "/mails/{$id}/slip")->body();
        $reference = 'ENT-' . date('Y') . '-00001';
        self::assertStringContainsString('Bordereau d&#039;enregistrement — courrier entrant', $slip);
        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg" class="lt-barcode"', $slip);
        self::assertStringContainsString('aria-label="' . $reference . '"', $slip);
        self::assertStringContainsString('Demande &lt;b&gt;urgente&lt;/b&gt;', $slip);
        self::assertStringContainsString('Mairie de Lyon', $slip);
        self::assertStringContainsString('data-lt-print', $slip);
        self::assertStringNotContainsString('lt-sidebar', $slip, 'print layout: no navigation');

        $secretSlip = $this->get($this->agent, "/mails/{$secret}/slip")->body();
        self::assertStringNotContainsString('Sujet sensible', $secretSlip);
        self::assertStringContainsString('Objet non imprimé (courrier secret)', $secretSlip);

        self::assertSame(404, $this->get($this->agent, '/mails/999/slip')->status());
    }
}
