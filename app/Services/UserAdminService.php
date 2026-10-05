<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Page;
use App\Core\TableRequest;
use App\Core\Transaction;
use App\Domain\AccessDeniedException;
use App\Domain\Assignment\AssignmentRequest;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Audit\Actor;
use App\Domain\Auth\LoginThrottle;
use App\Domain\Auth\Permission;
use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Domain\Auth\UserInput;
use App\Domain\Auth\UserRules;
use App\Domain\NotFoundException;
use App\Domain\RuleViolation;
use App\Repositories\ActivityLogRepository;
use App\Repositories\AssignmentRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;

/**
 * User administration screen (permission users.manage): accounts are created, modified,
 * deactivated and given a new password. An account is never deleted: the activity log refers to it.
 */
final class UserAdminService
{
    public const ENTITY = 'user';
    public const EXPORT_LIMIT = 5000;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SiteRepository $sites,
        private readonly DepartmentRepository $departments,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
        private readonly AssignmentRepository $assignments,
        private readonly WorkflowService $workflow,
        private readonly LoginAttemptRepository $attempts,
        private readonly LoginThrottle $throttle,
        private readonly ActivityLogRepository $activity,
    ) {
    }

    public function page(TableRequest $table, ?Role $role, ?int $siteId, bool $includeInactive): Page
    {
        return $this->users->page($table, $role, $siteId, $includeInactive);
    }

    /**
     * The whole filtered list for an export.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{count: int, truncated: bool, limit: int}}
     */
    public function export(TableRequest $table, ?Role $role, ?int $siteId, bool $includeInactive): array
    {
        $rows = $this->users->page($table, $role, $siteId, $includeInactive, self::EXPORT_LIMIT + 1)->toArray()['data'];
        $truncated = count($rows) > self::EXPORT_LIMIT;
        $rows = array_slice($rows, 0, self::EXPORT_LIMIT);
        return ['data' => $rows, 'meta' => ['count' => count($rows), 'truncated' => $truncated, 'limit' => self::EXPORT_LIMIT]];
    }

    /**
     * What the account page shows besides the form.
     *
     * @return array{
     *   activeMails: int,
     *   lockedUntil: ?\DateTimeImmutable,
     *   candidates: list<array{id: int, site_id: int, department_id: ?int, name: string, role: string}>,
     *   history: list<array{created_at: \DateTimeImmutable, action: string, user_name: ?string, old_values: array<string, mixed>, new_values: array<string, mixed>}>,
     *   logins: list<array{at: \DateTimeImmutable, succeeded: bool, ip: ?string}>
     * }
     */
    public function details(User $user): array
    {
        return [
            'activeMails' => count($this->assignments->activeMailIdsForUser($user->id)),
            'lockedUntil' => $this->lockedUntil($user),
            // Colleagues of the same site who can take over the mails of the account.
            'candidates' => array_values(array_filter(
                $this->workflow->assignableUsers($user->siteId),
                static fn (array $u): bool => $u['id'] !== $user->id,
            )),
            'history' => array_reverse($this->activity->forEntity(self::ENTITY, $user->id)),
            'logins' => $this->attempts->recentForEmail($user->email, 10),
        ];
    }

    /** End of the lock caused by failed sign-ins on this account (the lock by IP address is not tied to an account). */
    public function lockedUntil(User $user): ?\DateTimeImmutable
    {
        $now = $this->clock->now();
        $failures = $this->attempts->recentFailuresForEmail($user->email, $this->throttle->windowStart($now), LoginThrottle::MAX_FAILURES_PER_ACCOUNT);
        return $this->throttle->lockedUntil($failures, [], $now);
    }

    /** Lifts the lock: the recent failed sign-ins of the account no longer count. */
    public function unlock(Actor $actor, int $id): User
    {
        return $this->transaction->run(function () use ($actor, $id): User {
            $user = $this->find($id);
            $removed = $this->attempts->clearFailuresForEmail($user->email, $this->throttle->windowStart($this->clock->now()));
            $this->audit->event($actor, self::ENTITY, $id, 'unlock', $user->siteId, null, ['failures' => $removed]);
            return $user;
        });
    }

    /** The user lost their phone: two-factor authentication is turned off, they set it up again from their profile. */
    public function resetTotp(Actor $actor, int $id): User
    {
        return $this->transaction->run(function () use ($actor, $id): User {
            $user = $this->find($id);
            $this->users->disableTotp($id);
            $this->audit->event($actor, self::ENTITY, $id, 'totp_reset', $user->siteId, null, null);
            return $user;
        });
    }

    /** @return list<string> */
    public static function sortKeys(): array
    {
        return array_keys(UserRepository::SORTABLE);
    }

    public function find(int $id): User
    {
        return $this->users->findById($id) ?? throw NotFoundException::of('User', $id);
    }

    /** @return list<array{id: int, code: string, name: string, is_active: bool}> sites the administrator may use */
    public function sites(): array
    {
        return $this->sites->listAll();
    }

    public function create(Actor $actor, UserInput $input, string $password): User
    {
        $input = $this->withinReach($actor, $input);

        return $this->transaction->run(function () use ($actor, $input, $password): User {
            $this->assertValid($actor, $input, null);
            UserRules::assertPassword($password, 'password', UserRules::personalWords($input->firstName, $input->lastName, $input->email));
            $id = $this->users->create(
                $input->siteId,
                $input->departmentId,
                $input->role,
                $input->email,
                AuthService::hashPassword($password),
                $input->firstName,
                $input->lastName,
                $input->locale,
                // Chosen by the administrator: the user replaces it at the first sign-in.
                mustChangePassword: true,
            );
            if (!$input->isActive) {
                $this->users->update($id, $input);
            }
            $this->audit->created($actor, self::ENTITY, $id, $input->siteId, $input->auditValues());
            return $this->find($id);
        });
    }

    /**
     * @param ?int $reassignTo colleague who takes over the mails in progress when the account is deactivated
     */
    public function update(Actor $actor, int $id, UserInput $input, ?int $reassignTo = null): User
    {
        $input = $this->withinReach($actor, $input);

        return $this->transaction->run(function () use ($actor, $id, $input, $reassignTo): User {
            $current = $this->find($id);
            $this->assertValid($actor, $input, $current);
            if ($current->isActive && !$input->isActive) {
                $this->handOver($actor, $current, $reassignTo);
            }
            $this->users->update($id, $input);
            $this->audit->updated($actor, self::ENTITY, $id, $input->siteId, $current->auditValues(), $input->auditValues());
            return $this->find($id);
        });
    }

    /**
     * New password chosen by the administrator: the user's sessions are closed and the password must be
     * replaced at the next sign-in. The password itself is never written to the activity log.
     */
    public function resetPassword(Actor $actor, int $id, string $password): User
    {
        return $this->transaction->run(function () use ($actor, $id, $password): User {
            $user = $this->find($id);
            UserRules::assertPassword($password, 'new_password', UserRules::personalWords($user->firstName, $user->lastName, $user->email));
            $this->users->changePassword($id, AuthService::hashPassword($password), true, $this->clock->now());
            $this->audit->event($actor, self::ENTITY, $id, 'password_reset', $user->siteId, null, null);
            return $user;
        });
    }

    /**
     * Mails in progress never stay with an account that can no longer sign in: they go to a colleague,
     * each one through the normal reassignment (history, notification, delegation).
     */
    private function handOver(Actor $actor, User $user, ?int $reassignTo): void
    {
        $mailIds = $this->assignments->activeMailIdsForUser($user->id);
        if ($mailIds === []) {
            return;
        }
        if ($reassignTo === null) {
            throw RuleViolation::single('reassign_to', 'rules.user.has_mails', ['count' => count($mailIds)]);
        }
        if ($reassignTo === $user->id) {
            throw RuleViolation::single('reassign_to', 'rules.user.reassign_self');
        }
        try {
            foreach ($mailIds as $mailId) {
                $this->workflow->reassign($actor, $mailId, new AssignmentRequest($reassignTo, null, AssignmentRole::ForAction), 'account-deactivated');
            }
        } catch (RuleViolation | AccessDeniedException | NotFoundException) {
            throw RuleViolation::single('reassign_to', 'rules.user.reassign_failed');
        }
    }

    /** An administrator without access to every site only manages accounts of their own site. */
    private function withinReach(Actor $actor, UserInput $input): UserInput
    {
        if ($actor->user->can(Permission::SitesAll)) {
            return $input;
        }
        return new UserInput(
            $input->firstName,
            $input->lastName,
            $input->email,
            $input->role,
            $actor->user->siteId,
            $input->departmentId,
            $input->locale,
            $input->isActive,
        );
    }

    private function assertValid(Actor $actor, UserInput $input, ?User $target): void
    {
        UserRules::assertValid(
            $input,
            $actor->user,
            $target,
            emailTaken: $this->users->emailTaken($input->email, $target?->id),
            siteKnown: $this->sites->findName($input->siteId) !== null,
            departmentSiteId: $input->departmentId !== null ? $this->departments->findSiteId($input->departmentId) : null,
        );
    }
}
