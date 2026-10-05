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
use App\Domain\Auth\SecondFactorRequiredException;
use App\Domain\Auth\Totp;
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
    /** users.session_version at sign-in: a password change makes older sessions invalid. */
    public const VERSION_KEY = 'auth.version';
    /** Password accepted, code of the authenticator application still expected. */
    public const PENDING_KEY = 'auth.pending';
    public const PENDING_SECONDS = 300;

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
     * @throws SecondFactorRequiredException the password is right; completeSecondFactor() finishes the sign-in
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

        if ($user->totpEnabled) {
            // Not signed in yet: only the right to enter the code, for a few minutes.
            $this->session->regenerate();
            $this->session->set(self::PENDING_KEY, ['id' => $user->id, 'email' => $email, 'at' => $now->getTimestamp()]);
            $this->csrf->rotate();
            throw new SecondFactorRequiredException();
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
        $this->session->set(self::VERSION_KEY, $user->sessionVersion);
        $this->csrf->rotate();

        $this->user = $user;
        $this->resolved = true;
        return $user;
    }

    public function hasPendingSecondFactor(): bool
    {
        $pending = $this->session->get(self::PENDING_KEY);
        return is_array($pending) && $this->clock->now()->getTimestamp() - (int) ($pending['at'] ?? 0) <= self::PENDING_SECONDS;
    }

    /**
     * Second step of the sign-in: the code of the authenticator application.
     * A wrong code counts as a failed sign-in (same lock as wrong passwords).
     *
     * @throws AccountLockedException
     * @throws InvalidCredentialsException wrong code, or the first step expired (hasPendingSecondFactor() tells which)
     */
    public function completeSecondFactor(string $code, ?string $ip): User
    {
        $now = $this->clock->now();
        $pending = $this->session->get(self::PENDING_KEY);
        if (!$this->hasPendingSecondFactor() || !is_array($pending)) {
            $this->session->remove(self::PENDING_KEY);
            throw new InvalidCredentialsException();
        }
        $email = (string) $pending['email'];
        $this->assertNotLocked($email, $ip, $now);

        $user = $this->users->findById((int) $pending['id']);
        $state = $user !== null && $user->isActive ? $this->users->totpState($user->id) : null;
        $counter = $state !== null ? Totp::verify($state['secret'], $code, $now->getTimestamp(), $state['last_counter']) : null;
        if ($user === null || $counter === null) {
            $this->attempts->record($email, $ip, false, $now);
            $this->assertNotLocked($email, $ip, $now);
            throw new InvalidCredentialsException();
        }

        $this->transaction->run(function () use ($user, $email, $counter, $ip, $now): void {
            $this->attempts->record($email, $ip, true, $now);
            $this->users->touchLastLogin($user->id, $now);
            $this->users->touchTotpCounter($user->id, $counter);
        });

        $this->session->remove(self::PENDING_KEY);
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, $user->id);
        $this->session->set(self::VERSION_KEY, $user->sessionVersion);
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
        if ($user === null || !$user->isActive || $this->session->get(self::VERSION_KEY) !== $user->sessionVersion) {
            $this->logout();
            return null;
        }
        return $this->user = $user;
    }

    /** After the user changed their own password: this session goes on, the others are closed. */
    public function keepSession(User $user): void
    {
        $this->session->set(self::VERSION_KEY, $user->sessionVersion);
        $this->user = $user;
        $this->resolved = true;
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
