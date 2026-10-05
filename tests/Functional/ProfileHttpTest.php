<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Response;
use App\Core\Session;
use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** "Mon profil", forced password change and session closing, with the real authentication. */
final class ProfileHttpTest extends FunctionalTestCase
{
    private const PASSWORD = 'correct horse battery';

    private int $siteId;
    private int $adminId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteId = TestDatabase::insertSite('A');
        $this->adminId = TestDatabase::insertUser($this->siteId, 'admin@example.org', self::PASSWORD, 'admin');
        $this->userId = TestDatabase::insertUser($this->siteId, 'ana@example.org', self::PASSWORD, 'agent');
    }

    private function login(string $email, string $password): Response
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    /** @param array<string, string> $overrides */
    private function change(array $overrides = []): Response
    {
        return $this->post('/profile/password', $overrides + [
            'current_password' => self::PASSWORD, 'new_password' => 'un autre mot de passe', 'new_password_confirmation' => 'un autre mot de passe',
        ], 'PUT');
    }

    private function hash(int $userId): string
    {
        return (string) $this->pdo->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn();
    }

    public function testProfileShowsTheAccountAndChangesThePassword(): void
    {
        self::assertSame('/login', $this->get('/profile')->header('Location'), 'guests have no profile');
        $this->login('ana@example.org', self::PASSWORD);

        $page = $this->get('/profile')->body();
        self::assertStringContainsString('ana@example.org', $page);
        self::assertStringContainsString('name="current_password" type="Password"', $page);
        self::assertStringContainsString('data-lt-href="/profile"', $this->get('/')->body(), 'entry in the profile menu');

        self::assertSame(422, $this->change(['current_password' => 'wrong password here'])->status());
        self::assertStringContainsString('mot de passe actuel est incorrect', $this->change(['current_password' => 'wrong password here'])->body());
        self::assertSame(422, $this->change(['new_password_confirmation' => 'something else entirely'])->status());
        self::assertSame(422, $this->change(['new_password' => self::PASSWORD, 'new_password_confirmation' => self::PASSWORD])->status(), 'same as the current one');
        self::assertSame(422, $this->change(['new_password' => 'court', 'new_password_confirmation' => 'court'])->status());
        self::assertSame(422, $this->change(['new_password' => 'password1234', 'new_password_confirmation' => 'password1234'])->status(), 'common password');
        self::assertTrue(password_verify(self::PASSWORD, $this->hash($this->userId)), 'nothing changed so far');

        $done = $this->change();
        self::assertSame(302, $done->status(), $done->body());
        self::assertSame('/profile', $done->header('Location'));
        self::assertTrue(password_verify('un autre mot de passe', $this->hash($this->userId)));
        self::assertSame(200, $this->get('/mails')->status(), 'the session that changed the password goes on');

        $log = $this->pdo->query("SELECT new_values FROM activity_log WHERE entity_type = 'user' AND action = 'password_change'")->fetch();
        self::assertNotFalse($log);
        self::assertNull($log['new_values'], 'no password in the activity log');
    }

    public function testAPasswordMustNotContainTheNameOrTheEmail(): void
    {
        $this->pdo->exec("UPDATE users SET first_name = 'Anastasia', last_name = 'Martinez' WHERE id = {$this->userId}");
        $this->login('ana@example.org', self::PASSWORD);
        $refused = $this->change(['new_password' => 'Bonjour-MARTINEZ-2026', 'new_password_confirmation' => 'Bonjour-MARTINEZ-2026']);
        self::assertSame(422, $refused->status());
        self::assertStringContainsString('ni le prénom, ni le nom', $refused->body());
    }

    public function testChangingThePasswordClosesTheOtherSessions(): void
    {
        $this->login('ana@example.org', self::PASSWORD);
        $otherDevice = $this->session->all();

        $this->session = Session::inMemory();
        $this->login('ana@example.org', self::PASSWORD);
        self::assertSame(302, $this->change()->status());
        self::assertSame(200, $this->get('/mails')->status());

        $this->session = Session::inMemory($otherDevice);
        self::assertSame('/login', $this->get('/mails')->header('Location'), 'the session opened with the former password is closed');
    }

    public function testAnAccountCreatedByAnAdministratorMustReplaceItsPassword(): void
    {
        $this->actingAs($this->user($this->adminId));
        $created = $this->post('/users', [
            'first_name' => 'Jeanne', 'last_name' => 'Durand', 'email' => 'jeanne@example.org', 'role' => 'agent',
            'site_id' => (string) $this->siteId, 'department_id' => '', 'locale' => 'fr', 'is_active' => '1', 'password' => 'provisoire-2026-xyz',
        ]);
        self::assertSame(302, $created->status(), $created->body());
        self::assertSame(1, (int) $this->pdo->query("SELECT must_change_password FROM users WHERE email = 'jeanne@example.org'")->fetchColumn());
        self::assertSame(422, $this->post('/users', [
            'first_name' => 'Paul', 'last_name' => 'Lambert', 'email' => 'paul@example.org', 'role' => 'agent',
            'site_id' => (string) $this->siteId, 'department_id' => '', 'locale' => 'fr', 'is_active' => '1', 'password' => 'Lambert-2026-xyz',
        ])->status(), 'the administrator cannot choose a password that contains the name');

        $this->actingAs = null;
        $this->session = Session::inMemory();
        $this->login('jeanne@example.org', 'provisoire-2026-xyz');
        self::assertSame('/profile', $this->get('/mails')->header('Location'), 'every screen leads to the profile');
        self::assertSame(403, $this->get('/navigation/counts', json: true)->status());
        self::assertStringContainsString('remplacez-le pour continuer', $this->get('/profile')->body());

        $done = $this->change(['current_password' => 'provisoire-2026-xyz']);
        self::assertSame('/', $done->header('Location'), 'then the home page');
        self::assertSame(200, $this->get('/mails')->status());
        self::assertSame(0, (int) $this->pdo->query("SELECT must_change_password FROM users WHERE email = 'jeanne@example.org'")->fetchColumn());
    }

    public function testAPasswordGivenByAnAdministratorClosesTheSessionsAndMustBeReplaced(): void
    {
        $this->login('ana@example.org', self::PASSWORD);
        $userSession = $this->session->all();
        self::assertSame(200, $this->get('/mails')->status());

        $this->session = Session::inMemory();
        $this->actingAs($this->user($this->adminId));
        self::assertSame(302, $this->post("/users/{$this->userId}/password", ['new_password' => 'donne-par-admin-2026'])->status());

        $this->actingAs = null;
        $this->session = Session::inMemory($userSession);
        self::assertSame('/login', $this->get('/mails')->header('Location'), 'the open session is closed');
        $this->login('ana@example.org', 'donne-par-admin-2026');
        self::assertSame('/profile', $this->get('/mails')->header('Location'), 'and the new password must be replaced');
    }
}
