<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Domain\Auth\User;
use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** User administration through the Kernel with MariaDB. */
final class UserHttpTest extends FunctionalTestCase
{
    private int $siteA;
    private int $siteB;
    private int $departmentB;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteA = TestDatabase::insertSite('A');
        $this->siteB = TestDatabase::insertSite('B');
        $this->departmentB = TestDatabase::insertDepartment($this->siteB, 'DB');
        $this->admin = $this->user(TestDatabase::insertUser($this->siteA, 'admin@example.org', 'password-123456', 'admin'));
        $this->agent = $this->user(TestDatabase::insertUser($this->siteA, 'agent@example.org', 'password-123456', 'agent'));
        TestDatabase::insertUser($this->siteB, 'old@example.org', 'password-123456', 'secretariat', active: false);
    }

    /** @return array<string, string> */
    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Jeanne', 'last_name' => 'Durand', 'email' => 'Jeanne.Durand@Example.org', 'role' => 'secretariat',
            'site_id' => (string) $this->siteA, 'department_id' => '', 'locale' => 'fr', 'is_active' => '1',
        ];
    }

    public function testOnlyAdministratorsReachTheScreen(): void
    {
        $this->actingAs($this->agent);
        self::assertSame(403, $this->get('/users')->status());
        self::assertSame(403, $this->post('/users', $this->valid(['password' => 'un-mot-de-passe-long']))->status());
        self::assertStringNotContainsString('href="/users"', $this->get('/')->body());

        $this->actingAs($this->admin);
        $list = $this->get('/users')->body();
        self::assertStringContainsString('@floorplan', (string) file_get_contents(dirname(__DIR__, 2) . '/views/users/index.php'));
        self::assertStringContainsString('data-url="/users/data"', $list);
        self::assertStringContainsString('data-lt-href="/users/new"', $list);
        self::assertMatchesRegularExpression('#<ui5-side-navigation-item text="Utilisateurs"[^>]*href="/users"#', $list);
    }

    public function testListFiltersAndSearch(): void
    {
        $this->actingAs($this->admin);
        $all = $this->getJson('/users/data');
        self::assertSame(['admin@example.org', 'agent@example.org'], array_column($all['data'], 'email'), 'inactive accounts are hidden by default');
        self::assertSame('Administrateur', $all['data'][0]['role'], 'the role is shown by its label');
        self::assertSame('active', $all['data'][0]['status']);
        self::assertArrayNotHasKey('password_hash', $all['data'][0]);

        $inactive = $this->getJson('/users/data', ['inactive' => '1', 'sort' => 'email']);
        self::assertSame(3, $inactive['meta']['filtered']);
        self::assertSame(['agent@example.org'], array_column($this->getJson('/users/data', ['role' => 'agent'])['data'], 'email'));
        self::assertSame(['old@example.org'], array_column($this->getJson('/users/data', ['site_id' => (string) $this->siteB, 'inactive' => '1'])['data'], 'email'));
        self::assertSame(['agent@example.org'], array_column($this->getJson('/users/data', ['q' => 'agent@'])['data'], 'email'));
        self::assertSame(2, $this->getJson('/users/data', ['sort' => 'password_hash'])['meta']['filtered'], 'an unknown sort key falls back to the default');
    }

    public function testCreateAnAccountThatCanSignIn(): void
    {
        $this->actingAs($this->admin);
        self::assertStringContainsString('<ui5-input id="password" name="password" type="Password"', $this->get('/users/new')->body());

        $created = $this->post('/users', $this->valid(['password' => 'un-mot-de-passe-long']));
        self::assertSame(302, $created->status(), $created->body());
        $row = $this->pdo->query("SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = 'jeanne.durand@example.org'")->fetch();
        self::assertSame('secretariat', $row['role'], 'e-mail stored in lower case, role applied');
        self::assertTrue(password_verify('un-mot-de-passe-long', $row['password_hash']));

        $log = $this->pdo->query("SELECT action, new_values FROM activity_log WHERE entity_type = 'user' AND entity_id = {$row['id']}")->fetch();
        self::assertSame('create', $log['action']);
        self::assertStringNotContainsString('password', (string) $log['new_values'], 'no password in the activity log');
    }

    public function testInvalidInputComesBackInTheFields(): void
    {
        $this->actingAs($this->admin);
        $short = $this->post('/users', $this->valid(['password' => 'court']));
        self::assertSame(422, $short->status());
        self::assertStringContainsString('au moins 12 caractères', $short->body());
        self::assertStringContainsString('value="Jeanne"', $short->body(), 'entered values are kept');
        self::assertStringNotContainsString('value="court"', $short->body(), 'a password is never sent back');

        $taken = $this->post('/users', $this->valid(['email' => 'AGENT@example.org', 'password' => 'un-mot-de-passe-long']));
        self::assertSame(422, $taken->status());
        self::assertStringContainsString('déjà utilisée', $taken->body());

        $otherSite = $this->post('/users', $this->valid(['department_id' => (string) $this->departmentB, 'password' => 'un-mot-de-passe-long']));
        self::assertSame(422, $otherSite->status());
        self::assertStringContainsString('appartient à un autre site', $otherSite->body());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'nothing was created');
    }

    public function testUpdateDeactivateAndTrace(): void
    {
        $this->actingAs($this->admin);
        $form = $this->get("/users/{$this->agent->id}/edit")->body();
        self::assertStringContainsString('value="agent@example.org"', $form);
        self::assertStringContainsString('data-lt-open-dialog="user-password"', $form);
        self::assertStringNotContainsString('name="password"', $form, 'the password is not part of the edit form');

        $updated = $this->post("/users/{$this->agent->id}", $this->valid([
            'first_name' => 'Test', 'last_name' => 'User', 'email' => 'agent@example.org', 'role' => 'head_of_department', 'is_active' => '0',
        ]), 'PUT');
        self::assertSame(302, $updated->status(), $updated->body());
        $after = $this->user($this->agent->id);
        self::assertSame('head_of_department', $after->role->value);
        self::assertFalse($after->isActive);

        $log = $this->pdo->query("SELECT old_values, new_values FROM activity_log WHERE entity_type = 'user' AND action = 'update'")->fetch();
        self::assertSame(['is_active' => true, 'role' => 'agent'], self::sorted($log['old_values']), 'only the changed fields are traced');
        self::assertSame(['is_active' => false, 'role' => 'head_of_department'], self::sorted($log['new_values']));

        self::assertSame(404, $this->get('/users/999999/edit')->status());
    }

    public function testAnAdministratorCannotLockThemselvesOut(): void
    {
        $this->actingAs($this->admin);
        $self = ['first_name' => 'Test', 'last_name' => 'User', 'email' => 'admin@example.org'];
        $deactivate = $this->post("/users/{$this->admin->id}", $this->valid($self + ['role' => 'admin', 'is_active' => '0']), 'PUT');
        self::assertSame(422, $deactivate->status());
        self::assertStringContainsString('désactiver votre propre compte', $deactivate->body());

        $demote = $this->post("/users/{$this->admin->id}", $this->valid($self + ['role' => 'agent']), 'PUT');
        self::assertSame(422, $demote->status());
        self::assertStringContainsString('changer votre propre rôle', $demote->body());
        self::assertTrue($this->user($this->admin->id)->isActive);
        self::assertSame('admin', $this->user($this->admin->id)->role->value);
    }

    public function testNewPassword(): void
    {
        $this->actingAs($this->admin);
        $short = $this->post("/users/{$this->agent->id}/password", ['new_password' => 'court']);
        self::assertSame(422, $short->status());
        self::assertMatchesRegularExpression('#<ui5-dialog id="user-password"[^>]* open>#', $short->body(), 'the dialog reopens with its error');
        self::assertTrue(password_verify('password-123456', $this->user($this->agent->id)->passwordHash));

        $done = $this->post("/users/{$this->agent->id}/password", ['new_password' => 'un-nouveau-mot-de-passe']);
        self::assertSame(302, $done->status(), $done->body());
        self::assertTrue(password_verify('un-nouveau-mot-de-passe', $this->user($this->agent->id)->passwordHash));

        $log = $this->pdo->query("SELECT old_values, new_values FROM activity_log WHERE entity_type = 'user' AND action = 'password_reset'")->fetch();
        self::assertNotFalse($log, 'the reset is traced');
        self::assertNull($log['new_values'], 'without the password');
    }

    /** A mail registered by the administrator and assigned "for action" to $userId. */
    private function assignedMail(int $userId): int
    {
        $correspondent = TestDatabase::insertCorrespondent($this->siteA, 'Mairie');
        $created = $this->post('/mails', [
            'direction' => 'incoming', 'subject' => 'Objet', 'correspondent_id' => (string) $correspondent,
            'received_at' => date('Y-m-d', strtotime('-1 day')) . 'T08:00', 'channel' => 'postal', 'priority' => 'normal', 'confidentiality' => 'internal',
        ]);
        self::assertSame(302, $created->status(), $created->body());
        $id = self::idFromLocation($created);
        self::assertSame(302, $this->post("/mails/{$id}/assign", ['user_id' => (string) $userId, 'role' => 'for_action'])->status());
        return $id;
    }

    public function testDeactivatingAnAccountHandsItsMailsOver(): void
    {
        $this->actingAs($this->admin);
        $colleague = TestDatabase::insertUser($this->siteA, 'colleague@example.org', 'password-123456', 'agent');
        $mail = $this->assignedMail($this->agent->id);
        $agentFields = ['first_name' => 'Test', 'last_name' => 'User', 'email' => 'agent@example.org', 'role' => 'agent', 'is_active' => '0'];

        $form = $this->get("/users/{$this->agent->id}/edit")->body();
        self::assertStringContainsString('name="reassign_to"', $form, 'the hand-over field is offered while the account has mails in progress');
        self::assertStringContainsString('data-kpi="mails"', $form);

        $refused = $this->post("/users/{$this->agent->id}", $this->valid($agentFields), 'PUT');
        self::assertSame(422, $refused->status());
        self::assertStringContainsString('1 courrier(s) en cours', $refused->body());
        self::assertTrue($this->user($this->agent->id)->isActive);

        $self = $this->post("/users/{$this->agent->id}", $this->valid($agentFields + ['reassign_to' => (string) $this->agent->id]), 'PUT');
        self::assertSame(422, $self->status(), 'not to the account itself');

        $done = $this->post("/users/{$this->agent->id}", $this->valid($agentFields + ['reassign_to' => (string) $colleague]), 'PUT');
        self::assertSame(302, $done->status(), $done->body());
        self::assertFalse($this->user($this->agent->id)->isActive);
        self::assertSame($colleague, (int) $this->pdo->query("SELECT user_id FROM assignments WHERE mail_id = {$mail} AND status = 'active' AND role = 'for_action'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'mail' AND entity_id = {$mail} AND action = 'reassigned'")->fetchColumn(), 'traced on the mail');

        // Without mail in progress, no hand-over is asked.
        $idle = $this->post("/users/{$colleague}", $this->valid(['first_name' => 'Test', 'last_name' => 'User', 'email' => 'colleague@example.org', 'role' => 'agent']), 'PUT');
        self::assertSame(302, $idle->status(), $idle->body());
    }

    public function testALockedAccountIsShownAndCanBeUnlocked(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $insert = $this->pdo->prepare('INSERT INTO login_attempts (email, ip_address, succeeded, attempted_at) VALUES (:email, :ip, 0, :at)');
        for ($i = 0; $i < 5; $i++) {
            $insert->execute(['email' => 'agent@example.org', 'ip' => inet_pton('192.0.2.7'), 'at' => $now]);
        }
        $this->actingAs($this->admin);
        $page = $this->get("/users/{$this->agent->id}/edit")->body();
        self::assertStringContainsString('Verrouillé jusqu', $page);
        self::assertStringContainsString('data-lt-submit="user-unlock"', $page);
        self::assertStringContainsString('192.0.2.7', $page, 'the last sign-in attempts are listed');
        self::assertStringNotContainsString('data-lt-submit="user-unlock"', $this->get("/users/{$this->admin->id}/edit")->body());

        self::assertSame(302, $this->post("/users/{$this->agent->id}/unlock")->status());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM login_attempts WHERE email = 'agent@example.org'")->fetchColumn());
        $page = $this->get("/users/{$this->agent->id}/edit")->body();
        self::assertStringNotContainsString('Verrouillé jusqu', $page);
        self::assertStringContainsString('Compte débloqué', $page, 'the unlock appears in the history of the account');
    }

    public function testHistoryShowsWhoChangedWhat(): void
    {
        $this->actingAs($this->admin);
        $this->post("/users/{$this->agent->id}", $this->valid(['first_name' => 'Test', 'last_name' => 'User', 'email' => 'agent@example.org', 'role' => 'head_of_department']), 'PUT');
        $page = $this->get("/users/{$this->agent->id}/edit")->body();
        self::assertStringContainsString('Compte modifié', $page);
        self::assertStringContainsString('Rôle : Agent → Chef de service', $page);
        self::assertStringContainsString('Historique (1)', $page);
    }

    public function testExportTakesTheFilteredListWithoutPaging(): void
    {
        $this->actingAs($this->admin);
        $export = $this->getJson('/users/export', ['inactive' => '1', 'per_page' => '1']);
        self::assertSame(3, $export['meta']['count'], 'paging is ignored');
        self::assertFalse($export['meta']['truncated']);
        self::assertSame(['email', 'id', 'last_login_at', 'department_name', 'name', 'role', 'site_name', 'status'], array_values(array_intersect(
            ['email', 'id', 'last_login_at', 'department_name', 'name', 'role', 'site_name', 'status', 'password_hash'],
            array_keys($export['data'][0]),
        )), 'no password hash in the export');
        self::assertSame(['agent@example.org'], array_column($this->getJson('/users/export', ['role' => 'agent'])['data'], 'email'));
        self::assertStringContainsString('data-export-source="/users/export"', $this->get('/users')->body());
    }

    /** @return array<string, mixed> */
    private static function sorted(?string $json): array
    {
        $values = (array) json_decode((string) $json, true);
        ksort($values);
        return $values;
    }
}
