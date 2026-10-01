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

/** Dashboard, notifications and retention screens through the Kernel. */
final class NotificationHttpTest extends TestCase
{
    private PDO $pdo;
    private Session $session;
    private int $correspondent;
    private User $secretary;
    private User $agent;
    private User $admin;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $site = TestDatabase::insertSite('A');
        $this->correspondent = TestDatabase::insertCorrespondent($site, 'Mairie');
        $users = new UserRepository($this->pdo, SiteScope::system());
        $this->secretary = $users->findById(TestDatabase::insertUser($site, 'sec@example.org', 'password-123456', 'secretariat'));
        $this->agent = $users->findById(TestDatabase::insertUser($site, 'agent@example.org', 'password-123456', 'agent'));
        $this->admin = $users->findById(TestDatabase::insertUser($site, 'admin@example.org', 'password-123456', 'admin'));
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

    private function get(User $user, string $path): Response
    {
        return $this->kernel($user)->handle(new Request('GET', $path, server: ['HTTP_ACCEPT' => str_ends_with($path, '/count') ? 'application/json' : 'text/html']));
    }

    private function post(User $user, string $path, array $data = []): Response
    {
        $kernel = $this->kernel($user);
        $data[Csrf::FIELD] = (new Csrf($this->session))->token();
        return $kernel->handle(new Request('POST', $path, post: $data));
    }

    /** Registers an overdue incoming mail assigned to the agent; returns its id. */
    private function overdueMailForAgent(): int
    {
        $created = $this->post($this->secretary, '/mails', [
            'direction' => 'incoming', 'subject' => 'Relance', 'correspondent_id' => (string) $this->correspondent,
            'received_at' => date('Y-m-d', strtotime('-10 days')) . 'T09:00', 'document_date' => date('Y-m-d', strtotime('-12 days')),
            'channel' => 'postal', 'priority' => 'normal', 'confidentiality' => 'internal',
            'due_date' => date('Y-m-d', strtotime('-11 days')),
        ]);
        self::assertSame(302, $created->status(), $created->body());
        $id = (int) substr((string) $created->header('Location'), strlen('/mails/'));
        $this->post($this->secretary, "/mails/{$id}/assign", ['user_id' => (string) $this->agent->id, 'role' => 'for_action']);
        return $id;
    }

    public function testDashboardShowsDeadlines(): void
    {
        $id = $this->overdueMailForAgent();

        $agentHome = $this->get($this->agent, '/')->body();
        self::assertStringContainsString('Mes échéances', $agentHome);
        self::assertStringContainsString('lt-stat--overdue is-set', $agentHome);
        self::assertStringContainsString("/mails/{$id}", $agentHome);
        self::assertStringNotContainsString('Toutes les échéances', $agentHome, 'agents only see their own');

        self::assertStringContainsString('Toutes les échéances', $this->get($this->secretary, '/')->body());
    }

    public function testNotificationsListCountAndRead(): void
    {
        $id = $this->overdueMailForAgent();

        $count = json_decode($this->get($this->agent, '/notifications/count')->body(), true);
        self::assertSame(['unread' => 1], $count);

        $page = $this->get($this->agent, '/notifications')->body();
        self::assertStringContainsString('vous a affecté', $page);
        $notificationId = (int) $this->pdo->query('SELECT id FROM notifications')->fetchColumn();

        // Someone else's notification: 404; own: marked read and redirected to the mail.
        self::assertSame(404, $this->post($this->secretary, "/notifications/{$notificationId}/read")->status());
        $read = $this->post($this->agent, "/notifications/{$notificationId}/read");
        self::assertSame("/mails/{$id}", $read->header('Location'));
        self::assertSame(['unread' => 0], json_decode($this->get($this->agent, '/notifications/count')->body(), true));

        $this->pdo->exec("UPDATE notifications SET read_at = NULL");
        $this->post($this->agent, '/notifications/read-all');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn());
    }

    public function testRetentionScreenIsForAdministrators(): void
    {
        self::assertSame(403, $this->get($this->secretary, '/retention-rules')->status());

        $page = $this->get($this->admin, '/retention-rules')->body();
        self::assertStringContainsString('La tâche quotidienne ne s&#039;est encore jamais exécutée', $page);
        self::assertStringContainsString('data-lt-confirm-danger', $page);

        $created = $this->post($this->admin, '/retention-rules', ['name' => 'Archivage', 'retention_months' => '12', 'action' => 'archive', 'all_sites' => '1']);
        self::assertSame(302, $created->status(), $created->body());
        $ruleId = (int) $this->pdo->query('SELECT id FROM retention_rules')->fetchColumn();
        self::assertNull($this->pdo->query('SELECT site_id FROM retention_rules')->fetchColumn() ?: null);

        $this->post($this->admin, "/retention-rules/{$ruleId}/toggle", ['active' => '0']);
        self::assertSame(0, (int) $this->pdo->query('SELECT is_active FROM retention_rules')->fetchColumn());

        $invalid = $this->post($this->admin, '/retention-rules', ['name' => '', 'retention_months' => '0', 'action' => 'archive']);
        self::assertSame(422, $invalid->status());
    }
}
