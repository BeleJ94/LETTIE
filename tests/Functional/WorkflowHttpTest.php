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

/** Workflow screens and routes through the Kernel (routing, permissions, views, confirmations markup). */
final class WorkflowHttpTest extends TestCase
{
    private PDO $pdo;
    private Session $session;
    private User $secretary;
    private User $agent;
    private int $correspondent;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $site = TestDatabase::insertSite('A');
        $this->correspondent = TestDatabase::insertCorrespondent($site, 'Mairie');
        $users = new UserRepository($this->pdo, SiteScope::system());
        $this->secretary = $users->findById(TestDatabase::insertUser($site, 'sec@example.org', 'password-123456', 'secretariat'));
        $this->agent = $users->findById(TestDatabase::insertUser($site, 'agent@example.org', 'password-123456', 'agent'));
    }

    private function kernel(User $user): Kernel
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), new Env(['APP_TIMEZONE' => 'Europe/Paris', 'APP_LOCALE' => 'fr', 'DB_NAME' => 'unused']));
        $container = $kernel->container();
        $container->instance(PDO::class, $this->pdo);
        $this->session = Session::inMemory();
        $container->instance(Session::class, $this->session);
        $auth = $this->createStub(AuthService::class);
        $auth->method('user')->willReturn($user);
        $container->instance(AuthService::class, $auth);
        return $kernel;
    }

    private function get(User $user, string $path, array $query = []): Response
    {
        return $this->kernel($user)->handle(new Request('GET', $path, $query));
    }

    private function post(User $user, string $path, array $data = []): Response
    {
        $kernel = $this->kernel($user);
        $data[Csrf::FIELD] = (new Csrf($this->session))->token();
        return $kernel->handle(new Request('POST', $path, post: $data));
    }

    private function createMail(string $direction = 'incoming', array $extra = []): int
    {
        $response = $this->post($this->secretary, '/mails', array_merge([
            'direction' => $direction,
            'subject' => 'Objet',
            'correspondent_id' => (string) $this->correspondent,
            'received_at' => $direction === 'incoming' ? '2026-09-01T09:00' : '',
            'sent_at' => $direction === 'outgoing' ? '2026-09-01T09:00' : '',
            'channel' => 'postal',
            'priority' => 'normal',
            'confidentiality' => 'internal',
        ], $extra));
        self::assertSame(302, $response->status(), $response->body());
        return (int) substr((string) $response->header('Location'), strlen('/mails/'));
    }

    private function mailStatus(int $id): string
    {
        return (string) $this->pdo->query("SELECT status FROM mails WHERE id = {$id}")->fetchColumn();
    }

    public function testAssignStartCloseThroughTheScreens(): void
    {
        $id = $this->createMail();

        $page = $this->get($this->secretary, "/mails/{$id}")->body();
        self::assertStringContainsString("action=\"/mails/{$id}/assign\"", $page);
        self::assertStringContainsString('data-lt-confirm="Clôturer ce courrier ?', $page, 'close asks for confirmation');
        self::assertStringContainsString('data-lt-confirm-input="comment"', $page);

        $assigned = $this->post($this->secretary, "/mails/{$id}/assign", ['user_id' => (string) $this->agent->id, 'role' => 'for_action']);
        self::assertSame("/mails/{$id}#assignments", $assigned->header('Location'));
        self::assertSame('assigned', $this->mailStatus($id));

        // The agent sees "Prendre en charge", uses it, then closes with a comment.
        self::assertStringContainsString('Prendre en charge', $this->get($this->agent, "/mails/{$id}")->body());
        $this->post($this->agent, "/mails/{$id}/actions/start");
        self::assertSame('in_progress', $this->mailStatus($id));
        $this->post($this->agent, "/mails/{$id}/actions/close", ['comment' => 'Traité']);
        self::assertSame('closed', $this->mailStatus($id));

        $history = $this->get($this->secretary, "/mails/{$id}")->body();
        self::assertStringContainsString('Affectation', $history);
        self::assertStringContainsString('<ins>Clos</ins>', $history);
        self::assertStringContainsString('<ins>Traité</ins>', $history);
    }

    public function testForbiddenAndInvalidActions(): void
    {
        $id = $this->createMail();
        // Agent not assigned: 403. Agent cannot assign at all (route permission): 403.
        self::assertSame(403, $this->post($this->agent, "/mails/{$id}/actions/close")->status());
        self::assertSame(403, $this->post($this->agent, "/mails/{$id}/assign", ['user_id' => (string) $this->agent->id, 'role' => 'for_action'])->status());
        // Unknown or non-routable action: 404.
        self::assertSame(404, $this->post($this->secretary, "/mails/{$id}/actions/answer")->status());
        self::assertSame(404, $this->post($this->secretary, "/mails/{$id}/actions/explode")->status());

        // Not allowed by the workflow: redirect with an error message, status unchanged.
        $response = $this->post($this->secretary, "/mails/{$id}/actions/archive");
        self::assertSame(302, $response->status());
        self::assertSame('Cette action n\'est pas possible dans l\'état actuel du courrier.', $this->session->get('_flash')['flash.error']);
        self::assertSame('registered', $this->mailStatus($id));
    }

    public function testReplyFromIncomingMailClosesIt(): void
    {
        $incoming = $this->createMail();
        self::assertStringContainsString("/mails/new?reply_to={$incoming}", $this->get($this->secretary, "/mails/{$incoming}")->body());

        $form = $this->get($this->secretary, '/mails/new', ['reply_to' => (string) $incoming])->body();
        self::assertStringContainsString('name="reply_to" value="' . $incoming . '"', $form);
        self::assertStringContainsString('value="Re: Objet"', $form);

        $response = $this->post($this->secretary, '/mails', [
            'reply_to' => (string) $incoming,
            'subject' => 'Re: Objet',
            'correspondent_id' => (string) $this->correspondent,
            'sent_at' => '2026-09-02T10:00',
            'channel' => 'email',
            'priority' => 'normal',
            'confidentiality' => 'internal',
        ]);
        self::assertSame(302, $response->status(), $response->body());
        self::assertSame('closed', $this->mailStatus($incoming));
        self::assertStringContainsString('SOR-', (string) $this->session->get('_flash')['flash.success']);
    }

    public function testLinkExistingOutgoingMail(): void
    {
        $incoming = $this->createMail();
        $outgoing = $this->createMail('outgoing');
        $reference = (string) $this->pdo->query("SELECT reference FROM mails WHERE id = {$incoming}")->fetchColumn();

        $this->post($this->secretary, "/mails/{$outgoing}/reply-link", ['reference' => $reference]);
        self::assertSame('closed', $this->mailStatus($incoming));
        self::assertStringContainsString($reference, $this->get($this->secretary, "/mails/{$outgoing}")->body());
    }

    public function testAnnotationsAndDelegationsScreens(): void
    {
        $id = $this->createMail();
        $this->post($this->agent, "/mails/{$id}/annotations", ['body' => 'Rappeler <lundi>', 'is_private' => '1']);
        self::assertStringContainsString('Rappeler &lt;lundi&gt;', $this->get($this->agent, "/mails/{$id}")->body());
        self::assertStringNotContainsString('Rappeler', $this->get($this->secretary, "/mails/{$id}")->body(), 'private note hidden from others');

        self::assertSame(200, $this->get($this->agent, '/delegations')->status());
        $created = $this->post($this->agent, '/delegations', [
            'delegate_id' => (string) $this->secretary->id,
            'starts_on' => date('Y-m-d'),
            'ends_on' => date('Y-m-d', strtotime('+3 days')),
            'reason' => 'Congés',
        ]);
        self::assertSame(302, $created->status(), $created->body());
        $page = $this->get($this->agent, '/delegations')->body();
        self::assertStringContainsString('Congés', $page);
        self::assertStringContainsString('data-lt-confirm-danger', $page);

        $invalid = $this->post($this->agent, '/delegations', ['delegate_id' => (string) $this->agent->id, 'starts_on' => date('Y-m-d'), 'ends_on' => date('Y-m-d')]);
        self::assertSame(422, $invalid->status());
        self::assertStringContainsString('On ne peut pas se déléguer à soi-même.', $invalid->body());
    }
}
