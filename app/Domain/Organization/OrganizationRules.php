<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\RuleViolation;

/** Rules shared by sites and departments (pure: the facts are looked up by the service). */
final class OrganizationRules
{
    public const CODE_MAX = 20;
    public const NAME_MAX = 150;

    /** Codes are stored in upper case: letters, digits, dash and underscore. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @throws RuleViolation */
    public static function assertValid(string $code, string $name, bool $codeTaken): void
    {
        $violations = [];
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,' . (self::CODE_MAX - 1) . '}$/', $code) !== 1) {
            $violations['code'][] = ['rules.organization.code_format', ['max' => self::CODE_MAX]];
        } elseif ($codeTaken) {
            $violations['code'][] = ['rules.organization.code_taken', []];
        }
        if (trim($name) === '' || mb_strlen($name) > self::NAME_MAX) {
            $violations['name'][] = ['rules.organization.name_required', ['max' => self::NAME_MAX]];
        }
        if ($violations !== []) {
            throw new RuleViolation($violations);
        }
    }

    /**
     * A site or a department that still has active accounts stays active: the accounts are moved first.
     *
     * @throws RuleViolation
     */
    public static function assertCanDeactivate(int $activeUsers, bool $isOwnSite = false): void
    {
        if ($isOwnSite) {
            throw RuleViolation::single('is_active', 'rules.organization.own_site');
        }
        if ($activeUsers > 0) {
            throw RuleViolation::single('is_active', 'rules.organization.has_users', ['count' => $activeUsers]);
        }
    }
}
