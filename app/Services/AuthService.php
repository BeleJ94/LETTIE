<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Transaction;
use App\Domain\Auth\AccountLockedException;
use App\Domain\Auth\InvalidCredentialsException;
use App\Domain\Auth\LoginThrottle;
use App\Domain\Auth\User;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;

/**
 * Authentication. Its repositories are built with SiteScope::system():
 * the user is not known yet, so no site filter can apply.
 */
class AuthService
{
    public const SESSION_KEY = 'auth.user_id';

    private ?User $user = null;
    private bool $resolved = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $attempts,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
        private readonly LoginThrottle $throttle,
    ) {
    }

    /**
     * @throws AccountLockedException
     * @throws InvalidCredentialsException
     */
    public function attempt(string $email, string $password, ?string $ip): User
    {
        $email = mb_strtolower(trim($email));
        $now = $this->clock->now();

        $this->assertNotLocked($email, $ip, $now);

        $user = $this->users->findByEmail($email);
        // Always run password_verify so response time does not reveal whether the email exists.
        $passwordOk = password_verify($password, $user?->passwordHash ?? self::dummyHash());

        if ($user === null || !$passwordOk || !$user->isActive) {
            $this->attempts->record($email, $ip, false, $now);
            $this->assertNotLocked($email, $ip, $now);
            throw new InvalidCredentialsException();
        }

        $this->transaction->run(function () use ($user, $email, $password, $ip, $now): void {
            $this->attempts->record($email, $ip, true, $now);
            $this->users->touchLastLogin($user->id, $now);
            if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
                $this->users->updatePasswordHash($user->id, self::hashPassword($password));
            }
        });

        // New session id and CSRF token: prevents session fixation.
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, $user->id);
        $this->csrf->rotate();

        $this->user = $user;
        $this->resolved = true;
        return $user;
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $id = $this->session->get(self::SESSION_KEY);
        if (!is_int($id)) {
            return null;
        }
        $user = $this->users->findById($id);
        if ($user === null || !$user->isActive) {
            $this->logout();
            return null;
        }
        return $this->user = $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function logout(): void
    {
        $locale = $this->session->get('locale');
        $this->session->clear();
        $this->session->regenerate();
        if ($locale !== null) {
            $this->session->set('locale', $locale);
        }
        $this->csrf->rotate();
        $this->user = null;
        $this->resolved = true;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    private function assertNotLocked(string $email, ?string $ip, \DateTimeImmutable $now): void
    {
        $since = $this->throttle->windowStart($now);
        $until = $this->throttle->lockedUntil(
            $this->attempts->recentFailuresForEmail($email, $since, LoginThrottle::MAX_FAILURES_PER_ACCOUNT),
            $this->attempts->recentFailuresForIp($ip, $since, LoginThrottle::MAX_FAILURES_PER_IP),
            $now,
        );
        if ($until !== null) {
            throw new AccountLockedException($until);
        }
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }
}
