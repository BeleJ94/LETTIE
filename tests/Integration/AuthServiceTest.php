<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Csrf;
use App\Core\Session;
use App\Core\Transaction;
use App\Domain\Auth\AccountLockedException;
use App\Domain\Auth\InvalidCredentialsException;
use App\Domain\Auth\LoginThrottle;
use App\Domain\Auth\Role;
use App\Domain\SiteScope;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;

final class AuthServiceTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';

    private PDO $pdo;
    private FrozenClock $clock;
    private Session $session;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock();
        $this->session = Session::inMemory();
        $site = TestDatabase::insertSite('HQ');
        $this->userId = TestDatabase::insertUser($site, 'ana@example.org', self::PASSWORD, 'secretariat');
    }

    private function service(): AuthService
    {
        return new AuthService(
            new UserRepository($this->pdo, SiteScope::system()),
            new LoginAttemptRepository($this->pdo, SiteScope::system()),
            $this->session,
            new Csrf($this->session),
            new Transaction($this->pdo),
            $this->clock,
            new LoginThrottle(),
        );
    }

    private function failLogin(AuthService $auth, string $email = 'ana@example.org', string $ip = '10.0.0.1'): \Throwable
    {
        try {
            $auth->attempt($email, 'wrong password!', $ip);
        } catch (InvalidCredentialsException | AccountLockedException $e) {
            return $e;
        }
        self::fail('Login should have failed');
    }

    public function testSuccessfulLoginStoresUserAndRotatesCsrf(): void
    {
        $csrf = new Csrf($this->session);
        $before = $csrf->token();

        $user = $this->service()->attempt('  ANA@example.org ', self::PASSWORD, '10.0.0.1');

        self::assertSame($this->userId, $user->id);
        self::assertSame(Role::Secretariat, $user->role);
        self::assertSame($this->userId, $this->session->get(AuthService::SESSION_KEY));
        self::assertNotSame($before, $csrf->token());
        self::assertSame('2026-09-30 10:00:00', $this->pdo->query('SELECT last_login_at FROM users')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 1')->fetchColumn());

        // A fresh service (next request) restores the user from the session.
        self::assertSame($this->userId, $this->service()->user()?->id);
    }

    public function testWrongPasswordAndUnknownEmailAreRecordedFailures(): void
    {
        $auth = $this->service();
        self::assertInstanceOf(InvalidCredentialsException::class, $this->failLogin($auth));
        self::assertInstanceOf(InvalidCredentialsException::class, $this->failLogin($auth, 'nobody@example.org'));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0')->fetchColumn());
        self::assertNull($this->session->get(AuthService::SESSION_KEY));
    }

    public function testFifthFailureLocksAccountEvenForCorrectPassword(): void
    {
        $auth = $this->service();
        for ($i = 1; $i <= 4; $i++) {
            self::assertInstanceOf(InvalidCredentialsException::class, $this->failLogin($auth));
            $this->clock->advance('+1 minute');
        }
        self::assertInstanceOf(AccountLockedException::class, $this->failLogin($auth));

        $this->expectException(AccountLockedException::class);
        $auth->attempt('ana@example.org', self::PASSWORD, '10.0.0.2');
    }

    public function testLockExpiresAfterWindow(): void
    {
        $auth = $this->service();
        for ($i = 1; $i <= 5; $i++) {
            $this->failLogin($auth);
        }
        $this->clock->advance('+14 minutes');
        self::assertInstanceOf(AccountLockedException::class, $this->failLogin($auth));

        $this->clock->advance('+16 minutes');
        self::assertSame($this->userId, $auth->attempt('ana@example.org', self::PASSWORD, '10.0.0.1')->id);
    }

    public function testSuccessResetsFailureCount(): void
    {
        $auth = $this->service();
        for ($i = 1; $i <= 4; $i++) {
            $this->failLogin($auth);
        }
        $this->clock->advance('+1 second');
        $auth->attempt('ana@example.org', self::PASSWORD, '10.0.0.1');
        $this->clock->advance('+1 second');
        for ($i = 1; $i <= 4; $i++) {
            self::assertInstanceOf(InvalidCredentialsException::class, $this->failLogin($auth));
        }
    }

    public function testIpIsLockedAfterTwentyFailuresOnDifferentAccounts(): void
    {
        $auth = $this->service();
        for ($i = 1; $i <= 19; $i++) {
            self::assertInstanceOf(InvalidCredentialsException::class, $this->failLogin($auth, "user{$i}@example.org", '10.9.9.9'));
        }
        self::assertInstanceOf(AccountLockedException::class, $this->failLogin($auth, 'user20@example.org', '10.9.9.9'));
        // Another IP is not affected.
        self::assertSame($this->userId, $auth->attempt('ana@example.org', self::PASSWORD, '10.0.0.1')->id);
    }

    public function testInactiveUserCannotLogIn(): void
    {
        TestDatabase::insertUser(1, 'off@example.org', self::PASSWORD, 'agent', active: false);
        $this->expectException(InvalidCredentialsException::class);
        $this->service()->attempt('off@example.org', self::PASSWORD, null);
    }

    public function testDeactivatedUserIsLoggedOutOnNextRequest(): void
    {
        $this->service()->attempt('ana@example.org', self::PASSWORD, null);
        $this->pdo->exec('UPDATE users SET is_active = 0');
        self::assertNull($this->service()->user());
        self::assertNull($this->session->get(AuthService::SESSION_KEY));
    }

    public function testLogoutKeepsLocale(): void
    {
        $auth = $this->service();
        $auth->attempt('ana@example.org', self::PASSWORD, null);
        $this->session->set('locale', 'en');
        $auth->logout();

        self::assertNull($auth->user());
        self::assertNull($this->session->get(AuthService::SESSION_KEY));
        self::assertSame('en', $this->session->get('locale'));
    }

    public function testOutdatedHashIsUpgraded(): void
    {
        $weak = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->pdo->prepare('UPDATE users SET password_hash = :h')->execute(['h' => $weak]);

        $this->service()->attempt('ana@example.org', self::PASSWORD, null);
        self::assertNotSame($weak, $this->pdo->query('SELECT password_hash FROM users')->fetchColumn());
    }
}
