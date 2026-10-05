<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\Totp;
use App\Services\AuthService;
use Tests\Support\FunctionalTestCase;
use Tests\Support\TestDatabase;

/** Two-factor authentication: the pure algorithm, the set-up from the profile, and the sign-in in two steps. */
final class TwoFactorHttpTest extends FunctionalTestCase
{
    private const PASSWORD = 'correct horse battery';

    private int $userId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $site = TestDatabase::insertSite('A');
        $this->userId = TestDatabase::insertUser($site, 'ana@example.org', self::PASSWORD, 'agent');
        $this->adminId = TestDatabase::insertUser($site, 'admin@example.org', self::PASSWORD, 'admin');
    }

    private function login(): Response
    {
        return $this->post('/login', ['email' => 'ana@example.org', 'password' => self::PASSWORD]);
    }

    /** Secret shown on the profile while two-factor is being set up. */
    private function shownSecret(): string
    {
        preg_match('#data-totp-secret>([A-Z2-7 ]+)<#', $this->get('/profile')->body(), $m);
        self::assertNotEmpty($m, 'the secret is shown');
        return str_replace(' ', '', $m[1]);
    }

    private function code(string $secret, int $offset = 0): string
    {
        return Totp::code($secret, Totp::counter(time()) + $offset);
    }

    /** Turns two-factor on for ana and returns the secret. */
    private function enable(): string
    {
        $this->login();
        self::assertSame(302, $this->post('/profile/2fa/start')->status());
        $secret = $this->shownSecret();
        self::assertSame(302, $this->post('/profile/2fa/enable', ['code' => $this->code($secret)])->status());
        return $secret;
    }

    public function testAlgorithmMatchesTheRfcVectors(): void
    {
        $secret = Totp::encodeSecret('12345678901234567890');
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        self::assertSame('12345678901234567890', Totp::decodeSecret('gezd gnbv gy3t qojq gezd gnbv gy3t qojq'), 'spaces and case are ignored');
        // RFC 6238, appendix B (SHA-1), last 6 digits.
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            self::assertSame($code, Totp::code($secret, Totp::counter($time)), "T = {$time}");
        }
        self::assertSame(Totp::counter(59), Totp::verify($secret, '287 082', 59));
        self::assertSame(Totp::counter(59), Totp::verify($secret, '287082', 59 + Totp::PERIOD), 'the previous period is accepted');
        self::assertNull(Totp::verify($secret, '287082', 59 + 3 * Totp::PERIOD), 'not older ones');
        self::assertNull(Totp::verify($secret, '287082', 59, Totp::counter(59)), 'a code is accepted once');
        self::assertNull(Totp::verify($secret, 'abcdef', 59));
        self::assertStringStartsWith('otpauth://totp/Lettie%3Aana%40example.org?secret=' . $secret, Totp::uri('Lettie', 'ana@example.org', $secret));
    }

    public function testSetUpFromTheProfileNeedsAValidCode(): void
    {
        $this->login();
        self::assertStringContainsString('data-totp="off"', $this->get('/profile')->body());

        $this->post('/profile/2fa/start');
        $secret = $this->shownSecret();
        self::assertStringContainsString('otpauth://totp/', $this->get('/profile')->body());

        $wrong = $this->post('/profile/2fa/enable', ['code' => '000000']);
        self::assertSame(422, $wrong->status());
        self::assertStringContainsString('Code incorrect', $wrong->body());
        self::assertNull($this->pdo->query("SELECT totp_enabled_at FROM users WHERE id = {$this->userId}")->fetchColumn() ?: null, 'nothing is saved before a valid code');

        self::assertSame(302, $this->post('/profile/2fa/enable', ['code' => $this->code($secret)])->status());
        self::assertSame($secret, $this->pdo->query("SELECT totp_secret FROM users WHERE id = {$this->userId}")->fetchColumn());
        $page = $this->get('/profile')->body();
        self::assertStringContainsString('data-totp="on"', $page);
        self::assertStringNotContainsString($secret, str_replace(' ', '', $page), 'the secret is never shown again');
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'user' AND action = 'totp_enable'")->fetchColumn());
    }

    public function testSignInAsksForTheCode(): void
    {
        $secret = $this->enable();
        $this->session = Session::inMemory();

        self::assertSame('/login', $this->get('/login/code')->header('Location'), 'no code step without a password first');
        self::assertSame('/login/code', $this->login()->header('Location'));
        self::assertNull($this->session->get(AuthService::SESSION_KEY), 'not signed in yet');
        self::assertSame('/login', $this->get('/mails')->header('Location'));
        self::assertStringContainsString('name="code"', $this->get('/login/code')->body());

        self::assertSame('/login/code', $this->post('/login/code', ['code' => '000000'])->header('Location'));
        self::assertStringContainsString('Code incorrect', $this->get('/login/code')->body());
        // The code that turned two-factor on cannot be used again.
        self::assertSame('/login/code', $this->post('/login/code', ['code' => $this->code($secret)])->header('Location'), 'a code is accepted once');

        $done = $this->post('/login/code', ['code' => $this->code($secret, 1)]);
        self::assertSame('/mails', $done->header('Location'), 'the page asked before the sign-in');
        self::assertSame($this->userId, $this->session->get(AuthService::SESSION_KEY));
        self::assertSame(200, $this->get('/mails')->status());
    }

    public function testWrongCodesLockTheAccountAndThePendingStepExpires(): void
    {
        $this->enable();
        $this->session = Session::inMemory();
        $this->login();
        for ($i = 0; $i < 4; $i++) {
            self::assertSame('/login/code', $this->post('/login/code', ['code' => '000000'])->header('Location'));
        }
        self::assertSame('/login', $this->post('/login/code', ['code' => '000000'])->header('Location'), 'fifth failure: locked');
        self::assertStringContainsString('Trop de tentatives', $this->get('/login')->body());

        $this->pdo->exec('DELETE FROM login_attempts');
        $this->session = Session::inMemory();
        $this->login();
        $pending = $this->session->get(AuthService::PENDING_KEY);
        $this->session->set(AuthService::PENDING_KEY, ['at' => time() - AuthService::PENDING_SECONDS - 1] + $pending);
        self::assertSame('/login', $this->get('/login/code')->header('Location'), 'the first step expired');
    }

    public function testTheUserOrAnAdministratorTurnsItOff(): void
    {
        $this->enable();
        $refused = $this->post('/profile/2fa/disable', ['totp_password' => 'wrong password here']);
        self::assertSame(422, $refused->status());
        self::assertSame(302, $this->post('/profile/2fa/disable', ['totp_password' => self::PASSWORD])->status());
        self::assertNull($this->pdo->query("SELECT totp_secret FROM users WHERE id = {$this->userId}")->fetchColumn() ?: null);

        $this->post('/profile/2fa/start');
        $this->post('/profile/2fa/enable', ['code' => $this->code($this->shownSecret())]);

        $this->session = Session::inMemory();
        $this->actingAs($this->user($this->adminId));
        $page = $this->get("/users/{$this->userId}/edit")->body();
        self::assertStringContainsString('data-lt-submit="user-totp-reset"', $page);
        self::assertSame(302, $this->post("/users/{$this->userId}/2fa/reset")->status());
        self::assertStringNotContainsString('data-lt-submit="user-totp-reset"', $this->get("/users/{$this->userId}/edit")->body());

        $this->actingAs = null;
        $this->session = Session::inMemory();
        self::assertSame('/', $this->login()->header('Location'), 'the password alone is enough again');
    }
}
