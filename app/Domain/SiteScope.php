<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Auth\Permission;
use App\Domain\Auth\User;

/**
 * Which sites the current actor may read or write. Every repository receives
 * one and must filter on it.
 *
 * - forUser(): own site, or every site with Permission::SitesAll
 * - none(): guests, matches nothing
 * - system(): unrestricted, for technical code (authentication, CLI) only
 */
final class SiteScope
{
    /** @param list<int> $siteIds */
    private function __construct(
        private readonly bool $unrestricted,
        private readonly array $siteIds,
    ) {
    }

    public static function forUser(User $user): self
    {
        return $user->can(Permission::SitesAll) ? new self(true, []) : new self(false, [$user->siteId]);
    }

    public static function sites(int ...$siteIds): self
    {
        return new self(false, array_values(array_unique($siteIds)));
    }

    public static function none(): self
    {
        return new self(false, []);
    }

    public static function system(): self
    {
        return new self(true, []);
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    public function isEmpty(): bool
    {
        return !$this->unrestricted && $this->siteIds === [];
    }

    /** @return list<int> */
    public function siteIds(): array
    {
        return $this->siteIds;
    }

    public function allows(int $siteId): bool
    {
        return $this->unrestricted || in_array($siteId, $this->siteIds, true);
    }
}
