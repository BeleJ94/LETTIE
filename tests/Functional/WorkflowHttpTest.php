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

    /** @return array{0: int, 1: array<string, mixed>} status and decoded JSON body */
    private function postJson(User $user, string $path, array $data = []): array
    {
        $kernel = $this->kernel($user);
        $data[Csrf::FIELD] = (new Csrf($this->session))->token();
        $response = $kernel->handle(new Request('POST', $path, post: $data, server: ['HTTP_ACCEPT' => 'application/json']));
        return [$response->status(), (array) json_decode($response->body(), true)];
    }

    public function testListReportShowsTheActionsOfTheRole(): void
    {
        $secretary = $this->get($this->secretary, '/mails')->body();
        self::assertStringContainsString('@floorplan ListReport', (string) file_get_contents(dirname(__DIR__, 2) . '/views/mails/index.php'));
        self::assertStringContainsString('data-lt-list-report', $secretary);
        self::assertStringContainsString('data-url="/mails/data"', $secretary);
        self::assertStringContainsString('<table class="lt-table lt-dt" data-lt-table', $secretary, 'compact DataTable');
        self::assertStringContainsString('data-lt-select-all', $secretary);
        self::assertStringContainsString('dataTables.min.js', $secretary);
        self::assertStringContainsString('<ui5-dynamic-page-header slot="headerArea"', $secretary, 'the UI5 filter bar is kept');
        self::assertStringContainsString('data-lt-href="/mails/new?direction=incoming"', $secretary);
        self::assertStringContainsString('data-lt-bulk="assign"', $secretary);
        self::assertStringContainsString('data-lt-bulk="close"', $secretary);
        self::assertStringContainsString('id="mails-assign"', $secretary);
        self::assertMatchesRegularExpression('#<ui5-option value="' . $this->agent->id . '">#', $secretary, 'assignable users offered in the dialog');

        $agent = $this->get($this->agent, '/mails')->body();
        self::assertStringContainsString('data-lt-list-report', $agent);
        self::assertStringNotContainsString('data-lt-bulk="assign"', $agent, 'agents cannot assign');
        self::assertStringNotContainsString('id="mails-assign"', $agent);
        self::assertStringNotContainsString('/mails/new', $agent, 'agents cannot create');
        self::assertStringContainsString('data-lt-bulk="close"', $agent, 'agents close their own mail');
        self::assertStringContainsString('data-lt-export="xlsx"', $agent);
    }

    public function testBulkAssignProcessesEachMailAndReportsRefusals(): void
    {
        $first = $this->createMail();
        $second = $this->createMail();
        $closed = $this->createMail();
        $this->post($this->secretary, "/mails/{$closed}/assign", ['user_id' => (string) $this->agent->id, 'role' => 'for_action']);
        $this->post($this->secretary, "/mails/{$closed}/actions/close");

        [$status, $json] = $this->postJson($this->secretary, '/mails/bulk/assign', [
            'ids' => "{$first},{$second},{$closed},999999",
            'user_id' => (string) $this->agent->id,
            'role' => 'for_action',
        ]);

        self::assertSame(200, $status);
        self::assertSame([$first, $second], $json['done']);
        self::assertSame([$closed, 999999], array_column($json['failed'], 'id'));
        self::assertSame('Courrier introuvable.', $json['failed'][1]['message']);
        self::assertNotSame('', $json['failed'][0]['message']);
        self::assertSame('assigned', $this->mailStatus($first));
        self::assertSame('assigned', $this->mailStatus($second));
        self::assertSame('closed', $this->mailStatus($closed), 'a refusal changes nothing');
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'assigned' AND entity_id IN ({$first}, {$second})")->fetchColumn(), 'each assignment is logged');

        // Already owned + "for action" → reassignment, as on the mail page.
        $other = (new UserRepository($this->pdo, SiteScope::system()))->findById(
            TestDatabase::insertUser($this->secretary->siteId, 'agent2@example.org', 'password-123456', 'agent')
        );
        [, $again] = $this->postJson($this->secretary, '/mails/bulk/assign', ['ids' => (string) $first, 'user_id' => (string) $other->id, 'role' => 'for_action']);
        self::assertSame([$first], $again['done']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM assignments WHERE mail_id = {$first} AND status = 'active' AND role = 'for_action'")->fetchColumn());
        self::assertSame($other->id, (int) $this->pdo->query("SELECT user_id FROM assignments WHERE mail_id = {$first} AND status = 'active'")->fetchColumn());
    }

    public function testBulkAssignIsRefusedAsAWholeWhenTheRequestIsInvalid(): void
    {
        $id = $this->createMail();

        [$status, $json] = $this->postJson($this->secretary, '/mails/bulk/assign', ['ids' => (string) $id, 'role' => 'for_action']);
        self::assertSame(422, $status);
        self::assertSame(['Choisissez une personne ou un service.'], $json['errors']['user_id']);

        [$status, $json] = $this->postJson($this->secretary, '/mails/bulk/assign', ['ids' => 'abc', 'user_id' => (string) $this->agent->id, 'role' => 'for_action']);
        self::assertSame(422, $status);
        self::assertSame(['Aucun courrier sélectionné.'], $json['errors']['ids']);

        self::assertSame('registered', $this->mailStatus($id));
        self::assertSame(403, $this->postJson($this->agent, '/mails/bulk/assign', ['ids' => (string) $id, 'user_id' => (string) $this->agent->id, 'role' => 'for_action'])[0], 'agents cannot assign');
    }

    public function testBulkCloseFollowsTheSameRulesAsTheMailPage(): void
    {
        $mine = $this->createMail();
        $notMine = $this->createMail();
        $this->post($this->secretary, "/mails/{$mine}/assign", ['user_id' => (string) $this->agent->id, 'role' => 'for_action']);

        // An agent only closes mail assigned to them (MailAccess).
        [$status, $json] = $this->postJson($this->agent, '/mails/bulk/close', ['ids' => "{$mine},{$notMine}", 'comment' => 'Traité']);
        self::assertSame(200, $status);
        self::assertSame([$mine], $json['done']);
        self::assertSame([['id' => $notMine, 'message' => 'Vous n\'avez pas le droit d\'agir sur ce courrier.']], $json['failed']);
        self::assertSame('closed', $this->mailStatus($mine));
        self::assertSame('registered', $this->mailStatus($notMine));

        // Closing twice is refused by the workflow, not silently accepted.
        [, $again] = $this->postJson($this->secretary, '/mails/bulk/close', ['ids' => (string) $mine]);
        self::assertSame([], $again['done']);
        self::assertSame('Cette action n\'est pas possible dans l\'état actuel du courrier.', $again['failed'][0]['message']);
    }

    public function testExportCanBeLimitedToTheSelection(): void
    {
        $first = $this->createMail();
        $this->createMail();
        $third = $this->createMail();

        $all = json_decode($this->get($this->secretary, '/mails/export')->body(), true);
        self::assertCount(3, $all['data']);

        $selected = json_decode($this->get($this->secretary, '/mails/export', ['ids' => "{$first},{$third}"])->body(), true);
        self::assertEqualsCanonicalizing([$first, $third], array_column($selected['data'], 'id'));

        $none = json_decode($this->get($this->secretary, '/mails/export', ['ids' => 'x'])->body(), true);
        self::assertSame([], $none['data'], 'an unusable selection exports nothing rather than everything');
    }

    public function testAssignStartCloseThroughTheScreens(): void
    {
        $id = $this->createMail();

        $page = $this->get($this->secretary, "/mails/{$id}")->body();
        self::assertStringContainsString('@floorplan ObjectPage', (string) file_get_contents(dirname(__DIR__, 2) . '/views/mails/show.php'));
        self::assertStringContainsString("action=\"/mails/{$id}/assign\"", $page);
        self::assertStringContainsString('data-lt-open-dialog="dlg-assign"', $page);
        self::assertStringContainsString('<ui5-breadcrumbs-item href="/mails">', $page, 'breadcrumb back to the list');
        self::assertStringContainsString('data-lt-anchor-bar', $page);
        self::assertStringContainsString('data-kpi="owner"', $page);
        self::assertStringContainsString('Non affecté', $page);

        $assigned = $this->post($this->secretary, "/mails/{$id}/assign", ['user_id' => (string) $this->agent->id, 'role' => 'for_action']);
        self::assertSame("/mails/{$id}#assignments", $assigned->header('Location'));
        self::assertSame('assigned', $this->mailStatus($id));

        // The agent sees "Prendre en charge", uses it, then closes with a comment.
        $agentPage = $this->get($this->agent, "/mails/{$id}")->body();
        self::assertStringContainsString('Prendre en charge', $agentPage);
        self::assertStringContainsString('slot="footerArea"', $agentPage, 'main actions in the footer');
        self::assertMatchesRegularExpression('#data-lt-action="wf-close"\s+data-confirm="Clôturer ce courrier \?[^"]*"\s+data-confirm-title="Clôturer"\s+data-confirm-state="Critical"\s+data-confirm-input="comment"#', $agentPage, 'close asks for confirmation, with a comment');
        self::assertStringContainsString('id="lt-confirm"', $agentPage);
        self::assertStringNotContainsString('dlg-assign', $agentPage, 'agents cannot assign');
        $this->post($this->agent, "/mails/{$id}/actions/start");
        self::assertSame('in_progress', $this->mailStatus($id));
        $this->post($this->agent, "/mails/{$id}/actions/close", ['comment' => 'Traité']);
        self::assertSame('closed', $this->mailStatus($id));

        $history = $this->get($this->secretary, "/mails/{$id}")->body();
        self::assertStringContainsString('Affectation', $history);
        self::assertStringContainsString('→ Clos', $history);
        self::assertStringContainsString(': Traité', $history, 'a value set for the first time is shown without an arrow');
        self::assertStringNotContainsString('— →', $history);
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
