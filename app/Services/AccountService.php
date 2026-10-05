<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Transaction;
use App\Domain\Audit\Actor;
use App\Domain\Auth\Totp;
use App\Domain\Auth\User;
use App\Domain\Auth\UserRules;
use App\Domain\NotFoundException;
use App\Domain\RuleViolation;
use App\Repositories\UserRepository;

/** What a signed-in user does on their own account ("Mon profil"). */
final class AccountService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The user replaces their own password. Sessions opened with the former password are closed;
     * the caller keeps the current one alive (AuthService::keepSession).
     *
     * @throws RuleViolation
     */
    public function changePassword(Actor $actor, string $current, string $new): User
    {
        $user = $actor->user;
        if (!password_verify($current, $user->passwordHash)) {
            throw RuleViolation::single('current_password', 'rules.user.current_password');
        }
        if (password_verify($new, $user->passwordHash)) {
            throw RuleViolation::single('new_password', 'rules.user.password_same');
        }
        UserRules::assertPassword($new, 'new_password', UserRules::personalWords($user->firstName, $user->lastName, $user->email));

        return $this->transaction->run(function () use ($actor, $user, $new): User {
            $this->users->changePassword($user->id, AuthService::hashPassword($new), false, $this->clock->now());
            $this->audit->event($actor, UserAdminService::ENTITY, $user->id, 'password_change', $user->siteId, null, null);
            return $this->users->findById($user->id) ?? throw NotFoundException::of('User', $user->id);
        });
    }

    /** New secret for an authenticator application; nothing is saved until enableTotp() proves it works. */
    public function newTotpSecret(): string
    {
        return Totp::encodeSecret(random_bytes(20));
    }

    /**
     * Turns two-factor authentication on once the user typed a valid code of their application.
     *
     * @throws RuleViolation
     */
    public function enableTotp(Actor $actor, string $secret, string $code): void
    {
        $now = $this->clock->now();
        $counter = Totp::verify($secret, $code, $now->getTimestamp());
        if ($counter === null) {
            throw RuleViolation::single('code', 'rules.totp.code');
        }
        $this->transaction->run(function () use ($actor, $secret, $counter, $now): void {
            $this->users->enableTotp($actor->user->id, $secret, $counter, $now);
            $this->audit->event($actor, UserAdminService::ENTITY, $actor->user->id, 'totp_enable', $actor->user->siteId, null, null);
        });
    }

    /**
     * Turning it off asks for the password again: an unattended open session is not enough.
     *
     * @throws RuleViolation
     */
    public function disableTotp(Actor $actor, string $password): void
    {
        if (!password_verify($password, $actor->user->passwordHash)) {
            throw RuleViolation::single('totp_password', 'rules.user.current_password');
        }
        $this->transaction->run(function () use ($actor): void {
            $this->users->disableTotp($actor->user->id);
            $this->audit->event($actor, UserAdminService::ENTITY, $actor->user->id, 'totp_disable', $actor->user->siteId, null, null);
        });
    }
}
