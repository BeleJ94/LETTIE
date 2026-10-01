<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Transaction;
use App\Domain\AccessDeniedException;
use App\Domain\Audit\Actor;
use App\Domain\Auth\Permission;
use App\Domain\Delegation\Delegation;
use App\Domain\Delegation\DelegationRules;
use App\Domain\NotFoundException;
use App\Domain\RuleViolation;
use App\Repositories\DelegationRepository;
use App\Repositories\UserRepository;
use DateTimeZone;

/**
 * Absence delegations. Anyone may declare their own absence; dispatchers
 * (mail.assign) may declare one for a colleague of their scope.
 */
final class DelegationService
{
    public const ENTITY = 'delegation';

    public function __construct(
        private readonly DelegationRepository $delegations,
        private readonly UserRepository $users,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
    ) {
    }

    /** @throws AccessDeniedException|RuleViolation */
    public function create(Actor $actor, ?int $delegatorId, int $delegateId, string $startsOn, string $endsOn, ?string $reason): Delegation
    {
        $delegatorId ??= $actor->user->id;
        if ($delegatorId !== $actor->user->id && !$actor->user->can(Permission::MailAssign)) {
            throw new AccessDeniedException('Only dispatchers may declare an absence for someone else.');
        }

        return $this->transaction->run(function () use ($actor, $delegatorId, $delegateId, $startsOn, $endsOn, $reason): Delegation {
            $delegator = $this->users->findById($delegatorId) ?? throw RuleViolation::single('delegator_id', 'rules.delegation.user_invalid');
            $delegate = $this->users->findById($delegateId) ?? throw RuleViolation::single('delegate_id', 'rules.delegation.user_invalid');

            DelegationRules::check(
                $delegator->id, $delegator->siteId, $delegate->id, $delegate->siteId, $delegate->isActive,
                $startsOn, $endsOn, $this->today(), $this->delegations->forDelegator($delegator->id),
            );

            $id = $this->delegations->create($delegator->siteId, $delegator->id, $delegate->id, $startsOn, $endsOn, $reason, $actor->user->id, $this->clock->now());
            $delegation = $this->delegations->findById($id) ?? throw NotFoundException::of('Delegation', $id);
            $this->audit->created($actor, self::ENTITY, $id, $delegation->siteId, $delegation->auditValues());
            return $delegation;
        });
    }

    /** @throws NotFoundException|AccessDeniedException */
    public function cancel(Actor $actor, int $id): void
    {
        $this->transaction->run(function () use ($actor, $id): void {
            $delegation = $this->delegations->findById($id) ?? throw NotFoundException::of('Delegation', $id);
            if (!$this->canManage($actor, $delegation)) {
                throw new AccessDeniedException('Not allowed to cancel this delegation.');
            }
            if ($delegation->isCancelled()) {
                return;
            }
            $this->delegations->cancel($id, $this->clock->now(), $actor->user->id);
            $this->audit->event($actor, self::ENTITY, $id, 'cancel', $delegation->siteId, $delegation->auditValues(), null);
        });
    }

    public function canManage(Actor $actor, Delegation $delegation): bool
    {
        return $delegation->delegatorId === $actor->user->id
            || $delegation->createdBy === $actor->user->id
            || $actor->user->can(Permission::MailAssign);
    }

    /** @return list<Delegation> current and upcoming */
    public function current(): array
    {
        return $this->delegations->listFrom($this->today());
    }

    /** @return list<array{id: int, site_id: int, department_id: ?int, name: string, role: string}> */
    public function users(): array
    {
        return $this->users->listActive();
    }

    public function today(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }
}
