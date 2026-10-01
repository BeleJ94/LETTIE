<?php

declare(strict_types=1);

namespace App\Domain\Delegation;

/**
 * Who actually receives an assignment addressed to a user today.
 * Follows chains (A absent → B, B absent → C gives C) and stops on cycles
 * (A → B → A) by keeping the last user before the loop.
 */
final class DelegationResolver
{
    public const MAX_DEPTH = 5;

    /**
     * @param array<int, int> $activeDelegations delegator id => delegate id, for today
     * @return array{user_id: int, delegated_from: ?int}
     */
    public static function resolve(int $userId, array $activeDelegations): array
    {
        $current = $userId;
        $seen = [$userId => true];
        for ($depth = 0; $depth < self::MAX_DEPTH && isset($activeDelegations[$current]); $depth++) {
            $next = $activeDelegations[$current];
            if (isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $current = $next;
        }
        return ['user_id' => $current, 'delegated_from' => $current === $userId ? null : $userId];
    }
}
