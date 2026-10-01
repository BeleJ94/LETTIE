<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Domain\SiteScope;
use PHPUnit\Framework\TestCase;

final class SiteScopeTest extends TestCase
{
    private static function user(Role $role, int $siteId = 3): User
    {
        return new User(1, $siteId, null, $role, 'a@b.fr', 'x', 'A', 'B');
    }

    public function testUsersAreLimitedToTheirSite(): void
    {
        foreach ([Role::Secretariat, Role::HeadOfDepartment, Role::Agent] as $role) {
            $scope = SiteScope::forUser(self::user($role));
            self::assertFalse($scope->isUnrestricted());
            self::assertSame([3], $scope->siteIds());
            self::assertTrue($scope->allows(3));
            self::assertFalse($scope->allows(4));
        }
    }

    public function testAdminAndManagementSeeAllSites(): void
    {
        self::assertTrue(SiteScope::forUser(self::user(Role::Admin))->isUnrestricted());
        self::assertTrue(SiteScope::forUser(self::user(Role::Management))->allows(99));
    }

    public function testNoneAllowsNothingAndSystemEverything(): void
    {
        self::assertTrue(SiteScope::none()->isEmpty());
        self::assertFalse(SiteScope::none()->allows(1));
        self::assertTrue(SiteScope::system()->allows(1));
        self::assertFalse(SiteScope::system()->isEmpty());
    }

    public function testSitesDeduplicates(): void
    {
        self::assertSame([1, 2], SiteScope::sites(1, 2, 1)->siteIds());
    }
}
