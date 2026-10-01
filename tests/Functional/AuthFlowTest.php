<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Csrf;
use App\Services\AuthService;
use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** Real authentication over HTTP (no stubbed AuthService). */
final class AuthFlowTest extends FunctionalTestCase
{
    private const PASSWORD = 'correct horse battery';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $site = TestDatabase::insertSite('A');
        $this->userId = TestDatabase::insertUser($site, 'ana@example.org', self::PASSWORD, 'secretariat');
    }

    private function login(string $email, string $password): \App\Core\Response
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    public function testGuestIsSentToLoginAndBackToTheRequestedPage(): void
    {
        $response = $this->get('/mails');
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));

        $login = $this->login('ANA@example.org ', self::PASSWORD);
        self::assertSame('/mails', $login->header('Location'), 'intended page restored');
        self::assertSame($this->userId, $this->session->get(AuthService::SESSION_KEY));
        self::assertSame(200, $this->get('/mails')->status());
        self::assertSame('/', $this->get('/login')->header('Location'), 'signed-in users leave the login page');
    }

    public function testIntendedPathCannotRedirectOffSite(): void
    {
        $this->session->set('auth.intended', '//evil.example/steal');
        self::assertSame('/', $this->login('ana@example.org', self::PASSWORD)->header('Location'));
    }

    public function testWrongPasswordShowsGenericErrorAndKeepsEmail(): void
    {
        $response = $this->login('ana@example.org', 'wrong password');
        self::assertSame('/login', $response->header('Location'));

        $page = $this->get('/login')->body();
        self::assertStringContainsString('Identifiants incorrects.', $page);
        self::assertStringContainsString('value="ana@example.org"', $page);
        self::assertNull($this->session->get(AuthService::SESSION_KEY));
    }

    public function testUnknownEmailGetsTheSameMessage(): void
    {
        $this->login('nobody@example.org', 'whatever');
        self::assertStringContainsString('Identifiants incorrects.', $this->get('/login')->body());
    }

    public function testLockoutAfterFiveFailures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('ana@example.org', 'wrong');
        }
        $this->login('ana@example.org', self::PASSWORD);
        self::assertNull($this->session->get(AuthService::SESSION_KEY), 'locked even with the right password');
        self::assertStringContainsString('Trop de tentatives échouées', $this->get('/login')->body());
        self::assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0')->fetchColumn(), 'the locked attempt is not even checked');
    }

    public function testLoginRequiresCsrfToken(): void
    {
        $response = $this->post('/login', ['email' => 'ana@example.org', 'password' => self::PASSWORD, Csrf::FIELD => 'forged']);
        self::assertSame(419, $response->status());
        self::assertNull($this->session->get(AuthService::SESSION_KEY));
    }

    public function testLogoutEndsTheSessionButKeepsLanguage(): void
    {
        $this->login('ana@example.org', self::PASSWORD);
        $this->post('/locale', ['locale' => 'en']);
        $logout = $this->post('/logout');

        self::assertSame('/login', $logout->header('Location'));
        self::assertNull($this->session->get(AuthService::SESSION_KEY));
        self::assertSame('en', $this->session->get('locale'));
        self::assertStringContainsString('Sign in', $this->get('/login')->body());
        self::assertSame(302, $this->get('/')->status());
    }

    public function testDeactivatedUserIsSignedOutOnNextRequest(): void
    {
        $this->login('ana@example.org', self::PASSWORD);
        $this->pdo->exec("UPDATE users SET is_active = 0 WHERE id = {$this->userId}");
        self::assertSame('/login', $this->get('/mails')->header('Location'));
    }

    public function testJsonRequestsGet401InsteadOfRedirect(): void
    {
        $response = $this->get('/notifications/count', json: true);
        self::assertSame(401, $response->status());
        self::assertSame('{"error":"Unauthorized"}', $response->body());
    }

    public function testSecurityHeadersOnEveryPage(): void
    {
        foreach (['/login', '/mails'] as $path) {
            $response = $this->get($path);
            self::assertStringContainsString("script-src 'self'", (string) $response->header('Content-Security-Policy'));
            self::assertSame('DENY', $response->header('X-Frame-Options'));
            self::assertSame('no-store', $response->header('Cache-Control'));
        }
    }
}
