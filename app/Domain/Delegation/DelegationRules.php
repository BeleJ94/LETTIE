<?php

declare(strict_types=1);

namespace App\Domain\Delegation;

use App\Domain\RuleViolation;

final class DelegationRules
{
    public const MAX_DAYS = 366;

    /**
     * @param list<Delegation> $existing delegations of the same delegator
     * @throws RuleViolation
     */
    public static function check(
        int $delegatorId,
        int $delegatorSiteId,
        int $delegateId,
        int $delegateSiteId,
        bool $delegateActive,
        string $startsOn,
        string $endsOn,
        string $today,
        array $existing,
    ): void {
        $errors = [];
        if ($delegatorId === $delegateId) {
            $errors['delegate_id'][] = ['rules.delegation.same_user', []];
        }
        if ($delegatorSiteId !== $delegateSiteId) {
            $errors['delegate_id'][] = ['rules.delegation.other_site', []];
        }
        if (!$delegateActive) {
            $errors['delegate_id'][] = ['rules.delegation.inactive', []];
        }
        if ($endsOn < $startsOn) {
            $errors['ends_on'][] = ['rules.delegation.end_before_start', []];
        } elseif ((strtotime($endsOn) - strtotime($startsOn)) / 86400 >= self::MAX_DAYS) {
            $errors['ends_on'][] = ['rules.delegation.too_long', ['max' => self::MAX_DAYS]];
        }
        if ($endsOn < $today) {
            $errors['ends_on'][] = ['rules.delegation.in_past', []];
        }
        foreach ($existing as $delegation) {
            if ($delegation->overlaps($startsOn, $endsOn)) {
                $errors['starts_on'][] = ['rules.delegation.overlap', ['from' => $delegation->startsOn, 'to' => $delegation->endsOn]];
                break;
            }
        }
        if ($errors !== []) {
            throw new RuleViolation($errors);
        }
    }
}
