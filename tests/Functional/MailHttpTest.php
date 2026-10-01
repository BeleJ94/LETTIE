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

/** End-to-end through the Kernel: routes, middleware, controllers, views, services, database. */
final class MailHttpTest extends TestCase
{
    private PDO $pdo;
    private Session $session;
    private int $siteId;
    private int $correspondentId;
    private User $secretary;
    private User $agent;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->siteId = TestDatabase::insertSite('A');
        $this->correspondentId = TestDatabase::insertCorrespondent($this->siteId, 'Mairie <de> Lyon');
        $users = new UserRepository($this->pdo, SiteScope::system());
        $this->secretary = $users->findById(TestDatabase::insertUser($this->siteId, 'sec@example.org', 'password-123456', 'secretariat'));
        $this->agent = $users->findById(TestDatabase::insertUser($this->siteId, 'agent@example.org', 'password-123456', 'agent'));
    }

    private function kernel(User $user): Kernel
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), new Env([
            'APP_TIMEZONE' => 'Europe/Paris',
            'APP_LOCALE' => 'fr',
            'DB_NAME' => 'unused',
            'STORAGE_PATH' => sys_get_temp_dir() . '/lettie_http_storage',
        ]));
        $container = $kernel->container();
        $container->instance(PDO::class, $this->pdo);
        $this->session = Session::inMemory();
        $container->instance(Session::class, $this->session);
        $auth = $this->createStub(AuthService::class);
        $auth->method('user')->willReturn($user);
        $container->instance(AuthService::class, $auth);
        return $kernel;
    }

    private function post(Kernel $kernel, string $path, array $data): Response
    {
        $data[Csrf::FIELD] = (new Csrf($this->session))->token();
        return $kernel->handle(new Request('POST', $path, post: $data));
    }

    private function mailForm(array $over = []): array
    {
        return array_merge([
            'direction' => 'incoming',
            'subject' => 'Demande <b>urgente</b>',
            'correspondent_id' => (string) $this->correspondentId,
            'received_at' => '2026-09-01T09:30',
            'document_date' => '2026-08-30',
            'channel' => 'postal',
            'priority' => 'high',
            'confidentiality' => 'internal',
            'due_date' => '2026-09-15',
        ], $over);
    }

    public function testCreateShowListAndEditFlow(): void
    {
        $kernel = $this->kernel($this->secretary);

        $form = $kernel->handle(new Request('GET', '/mails/new', ['direction' => 'incoming']));
        self::assertSame(200, $form->status(), $form->body());
        self::assertStringContainsString('correspondent-picker.js', $form->body());

        $created = $this->post($kernel, '/mails', $this->mailForm());
        self::assertSame(302, $created->status(), $created->body());
        $location = (string) $created->header('Location');
        self::assertMatchesRegularExpression('#^/mails/\d+$#', $location);

        $show = $this->kernel($this->secretary)->handle(new Request('GET', $location));
        self::assertSame(200, $show->status(), $show->body());
        $html = $show->body();
        self::assertStringContainsString('ENT-2026-00001', $html);
        self::assertStringContainsString('Demande &lt;b&gt;urgente&lt;/b&gt;', $html, 'user input is escaped');
        self::assertStringContainsString('Mairie &lt;de&gt; Lyon', $html);
        self::assertStringContainsString('01/09/2026 09:30', $html, 'local time zone round-trip');
        self::assertStringContainsString('Création', $html, 'history shows the creation');
        self::assertStringContainsString('enctype="multipart/form-data"', $html);

        $json = $this->kernel($this->secretary)->handle(new Request('GET', '/mails/data', ['sort' => 'reference', 'q' => 'urgente'], server: ['HTTP_ACCEPT' => 'application/json']));
        $data = json_decode($json->body(), true);
        self::assertSame(1, $data['meta']['filtered']);
        self::assertSame('ENT-2026-00001', $data['data'][0]['reference']);
        self::assertSame('2026-09-01 07:30:00', $data['data'][0]['mail_date'], 'stored in UTC');

        $id = (int) substr($location, strlen('/mails/'));
        $kernel = $this->kernel($this->secretary);
        $edit = $kernel->handle(new Request('GET', "/mails/{$id}/edit"));
        self::assertSame(200, $edit->status());
        self::assertStringContainsString('value="2026-09-01T09:30"', $edit->body());

        // The browser posts _method=PUT; Request::fromGlobals turns it into PUT (covered in RequestTest).
        // A posted "status" is ignored: status changes go through workflow actions only.
        $data = $this->mailForm(['status' => 'closed', 'priority' => 'urgent', Csrf::FIELD => (new Csrf($this->session))->token()]);
        $updated = $kernel->handle(new Request('PUT', "/mails/{$id}", post: $data));
        self::assertSame(302, $updated->status(), $updated->body());
        self::assertSame('registered', $this->pdo->query("SELECT status FROM mails WHERE id = {$id}")->fetchColumn());

        $history = $this->kernel($this->secretary)->handle(new Request('GET', "/mails/{$id}"))->body();
        self::assertStringContainsString('Modification', $history);
        self::assertStringContainsString('<del>Haute</del>', $history);
        self::assertStringContainsString('<ins>Urgente</ins>', $history);
    }

    public function testValidationErrorsRedisplayTheFormWith422(): void
    {
        $kernel = $this->kernel($this->secretary);
        $response = $this->post($kernel, '/mails', $this->mailForm(['subject' => '', 'due_date' => '2026-08-01']));
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Le champ Objet est obligatoire.', $response->body());
        self::assertStringContainsString('aria-invalid="true"', $response->body());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mails')->fetchColumn());

        $rule = $this->post($this->kernel($this->secretary), '/mails', $this->mailForm(['due_date' => '2026-08-01']));
        self::assertSame(422, $rule->status());
        self::assertStringContainsString('L&#039;échéance ne peut pas précéder la date du document.', $rule->body());
    }

    public function testAgentCannotCreateAndGets403(): void
    {
        $kernel = $this->kernel($this->agent);
        self::assertSame(403, $kernel->handle(new Request('GET', '/mails/new'))->status());
        self::assertSame(403, $this->post($kernel, '/mails', $this->mailForm())->status());
        self::assertSame(200, $this->kernel($this->agent)->handle(new Request('GET', '/mails'))->status());
    }

    public function testCorrespondentScreensAndSearch(): void
    {
        $kernel = $this->kernel($this->secretary);
        self::assertSame(200, $kernel->handle(new Request('GET', '/correspondents'))->status());
        self::assertSame(200, $this->kernel($this->secretary)->handle(new Request('GET', '/correspondents/new'))->status());

        $created = $this->post($this->kernel($this->secretary), '/correspondents', [
            'type' => 'person', 'name' => 'Jeanne Martin', 'country' => 'fr', 'is_active' => '1', 'email' => 'Jeanne@Example.org',
        ]);
        self::assertSame(302, $created->status(), $created->body());

        $search = $this->kernel($this->secretary)->handle(new Request('GET', '/correspondents/search', ['q' => 'jean']));
        $data = json_decode($search->body(), true)['data'];
        self::assertSame('Jeanne Martin', $data[0]['label']);

        $row = $this->pdo->query("SELECT email, country FROM correspondents WHERE name = 'Jeanne Martin'")->fetch();
        self::assertSame(['email' => 'jeanne@example.org', 'country' => 'FR'], $row);
    }

    public function testUnknownMailIs404(): void
    {
        self::assertSame(404, $this->kernel($this->secretary)->handle(new Request('GET', '/mails/999'))->status());
        self::assertSame(404, $this->kernel($this->secretary)->handle(new Request('GET', '/attachments/999'))->status());
    }
}
