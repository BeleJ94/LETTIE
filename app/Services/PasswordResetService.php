<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\MailException;
use App\Core\Mailer;
use App\Core\Transaction;
use App\Domain\Audit\Actor;
use App\Domain\Auth\LoginThrottle;
use App\Domain\Auth\User;
use App\Domain\Auth\UserRules;
use App\Domain\RuleViolation;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use Closure;

/**
 * "Mot de passe oublié": a link sent by e-mail lets the user choose a new password.
 * Like authentication, it runs before any user is known: its repositories are built with SiteScope::system().
 */
class PasswordResetService
{
    public const LINK_MINUTES = 30;
    public const MAX_PER_HOUR = 3;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $resets,
        private readonly LoginAttemptRepository $attempts,
        private readonly Mailer $mailer,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
        private readonly LoginThrottle $throttle,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->mailer->isConfigured();
    }

    /**
     * Sends a link when the address belongs to an active account. Never tells the caller whether
     * it did: the screen answers the same thing for a known and an unknown address.
     *
     * @param Closure(User, string): array{subject: string, text: string} $message builds the e-mail from the user and the token
     */
    public function request(string $email, ?string $ip, Closure $message): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $now = $this->clock->now();
        $this->resets->purgeExpiredBefore($now->modify('-1 day'));

        $user = $this->users->findByEmail(mb_strtolower(trim($email)));
        if ($user === null || !$user->isActive || $this->resets->countSince($user->id, $now->modify('-1 hour')) >= self::MAX_PER_HOUR) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $this->resets->create($user->id, hash('sha256', $token), $now->modify('+' . self::LINK_MINUTES . ' minutes'), $ip, $now);
        $mail = $message($user, $token);
        try {
            $this->mailer->send($user->email, $mail['subject'], $mail['text']);
        } catch (MailException $e) {
            // Same answer on screen; the administrator finds the cause in the server log.
            error_log('Lettie: password reset e-mail not sent: ' . $e->getMessage());
        }
    }

    /** Account of a link that can still be used, or null. */
    public function userFor(string $token): ?User
    {
        $userId = $this->resets->findUserId(hash('sha256', $token), $this->clock->now());
        $user = $userId !== null ? $this->users->findById($userId) : null;
        return $user !== null && $user->isActive ? $user : null;
    }

    /**
     * Replaces the password, closes the sessions of the account, lifts its lock and burns its links.
     * Two-factor authentication, when it is on, is still asked at the next sign-in.
     *
     * @throws RuleViolation
     */
    public function reset(string $token, string $password, ?string $ip, ?string $userAgent): User
    {
        $user = $this->userFor($token) ?? throw RuleViolation::single('token', 'rules.reset.invalid');
        UserRules::assertPassword($password, 'new_password', UserRules::personalWords($user->firstName, $user->lastName, $user->email));

        return $this->transaction->run(function () use ($user, $password, $ip, $userAgent): User {
            $now = $this->clock->now();
            $this->users->changePassword($user->id, AuthService::hashPassword($password), false, $now);
            $this->resets->markAllUsed($user->id, $now);
            $this->attempts->clearFailuresForEmail($user->email, $this->throttle->windowStart($now));
            $this->audit->event(new Actor($user, $ip, $userAgent), UserAdminService::ENTITY, $user->id, 'password_recovered', $user->siteId, null, null);
            return $user;
        });
    }
}
