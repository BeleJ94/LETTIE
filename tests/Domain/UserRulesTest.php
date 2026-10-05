<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Domain\Auth\UserInput;
use App\Domain\Auth\UserRules;
use App\Domain\RuleViolation;
use PHPUnit\Framework\TestCase;

final class UserRulesTest extends TestCase
{
    private static function user(int $id, Role $role = Role::Admin): User
    {
        return new User($id, 1, null, $role, "u{$id}@example.org", 'hash', 'Ana', 'Martin');
    }

    private static function input(Role $role = Role::Agent, bool $active = true, ?int $departmentId = null, string $locale = 'fr'): UserInput
    {
        return new UserInput('Ana', 'Martin', 'ana@example.org', $role, 1, $departmentId, $locale, $active);
    }

    /** @return list<string> fields in error */
    private static function violations(callable $check): array
    {
        try {
            $check();
        } catch (RuleViolation $e) {
            return array_keys($e->violations());
        }
        return [];
    }

    public function testAValidAccountPasses(): void
    {
        self::assertSame([], self::violations(fn () => UserRules::assertValid(self::input(departmentId: 5), self::user(1), null, false, true, 1)));
    }

    public function testEmailSiteDepartmentAndLocaleAreChecked(): void
    {
        self::assertSame(['email'], self::violations(fn () => UserRules::assertValid(self::input(), self::user(1), null, true, true, null)));
        self::assertSame(['site_id'], self::violations(fn () => UserRules::assertValid(self::input(), self::user(1), null, false, false, null)));
        self::assertSame(['department_id'], self::violations(fn () => UserRules::assertValid(self::input(departmentId: 5), self::user(1), null, false, true, 2)), 'department of another site');
        self::assertSame(['department_id'], self::violations(fn () => UserRules::assertValid(self::input(departmentId: 5), self::user(1), null, false, true, null)), 'unknown department');
        self::assertSame(['locale'], self::violations(fn () => UserRules::assertValid(self::input(locale: 'de'), self::user(1), null, false, true, null)));
    }

    public function testAnAdministratorCannotLockThemselvesOut(): void
    {
        $admin = self::user(1);
        self::assertSame(['is_active'], self::violations(fn () => UserRules::assertValid(self::input(Role::Admin, active: false), $admin, $admin, false, true, null)));
        self::assertSame(['role'], self::violations(fn () => UserRules::assertValid(self::input(Role::Agent), $admin, $admin, false, true, null)));
        self::assertSame([], self::violations(fn () => UserRules::assertValid(self::input(Role::Admin), $admin, $admin, false, true, null)));
        // Another administrator may do both.
        self::assertSame([], self::violations(fn () => UserRules::assertValid(self::input(Role::Agent, active: false), $admin, self::user(2), false, true, null)));
    }

    public function testCommonAndPersonalPasswordsAreRefused(): void
    {
        self::assertSame(['password'], self::violations(fn () => UserRules::assertPassword('Password1234')), 'well-known password, any case');
        self::assertSame(['password'], self::violations(fn () => UserRules::assertPassword('zzzzzzzzzzzzzz')), 'one repeated character');
        $words = UserRules::personalWords('Anastasia', 'Li', 'a.martinez@example.org');
        self::assertSame(['password'], self::violations(fn () => UserRules::assertPassword('2026-anastasia-ok', 'password', $words)));
        self::assertSame(['password'], self::violations(fn () => UserRules::assertPassword('xx-A.MARTINEZ-2026', 'password', $words)), 'name part of the e-mail');
        self::assertSame([], self::violations(fn () => UserRules::assertPassword('violin-lighthouse', 'password', $words)), 'a two-letter name is too short to be searched');
        self::assertSame([], self::violations(fn () => UserRules::assertPassword('correct horse battery', 'password', $words)));
    }

    public function testPasswordLength(): void
    {
        self::assertSame(['password'], self::violations(fn () => UserRules::assertPassword(substr(str_repeat('xy7', 40), 0, PasswordPolicy::MIN_LENGTH - 1))));
        self::assertSame([], self::violations(fn () => UserRules::assertPassword(substr(str_repeat('xy7', 40), 0, PasswordPolicy::MIN_LENGTH))));
        self::assertSame(['new_password'], self::violations(fn () => UserRules::assertPassword(substr(str_repeat('xy7', 40), 0, PasswordPolicy::MAX_BYTES + 1), 'new_password')));
    }
}
