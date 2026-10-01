<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Auth\Permission;
use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function testFiveRoles(): void
    {
        self::assertSame(
            ['admin', 'secretariat', 'head_of_department', 'agent', 'management'],
            array_column(Role::cases(), 'value'),
        );
    }

    public function testAdminHasEveryPermission(): void
    {
        foreach (Permission::cases() as $permission) {
            self::assertTrue(Role::Admin->can($permission), $permission->value);
        }
    }

    public function testOnlyAdminManagesUsersAndSettings(): void
    {
        foreach (Role::cases() as $role) {
            $expected = $role === Role::Admin;
            self::assertSame($expected, $role->can(Permission::UsersManage), $role->value);
            self::assertSame($expected, $role->can(Permission::SettingsManage), $role->value);
        }
    }

    public function testRolePermissionsMatrix(): void
    {
        self::assertTrue(Role::Secretariat->can(Permission::MailCreate));
        self::assertTrue(Role::Secretariat->can(Permission::MailAssign));
        self::assertFalse(Role::Secretariat->can(Permission::SitesAll));

        self::assertTrue(Role::HeadOfDepartment->can(Permission::MailAssign));
        self::assertFalse(Role::HeadOfDepartment->can(Permission::MailCreate));

        self::assertTrue(Role::Agent->can(Permission::MailView));
        self::assertFalse(Role::Agent->can(Permission::MailAssign));
        self::assertFalse(Role::Agent->can(Permission::MailDelete));

        self::assertTrue(Role::Management->can(Permission::SitesAll));
        self::assertTrue(Role::Management->can(Permission::ReportsView));
        self::assertFalse(Role::Management->can(Permission::MailCreate));
    }

    public function testEveryRoleCanViewMail(): void
    {
        foreach (Role::cases() as $role) {
            self::assertTrue($role->can(Permission::MailView), $role->value);
        }
    }

    public function testInactiveUserHasNoPermission(): void
    {
        $user = new User(1, 1, null, Role::Admin, 'a@b.fr', 'x', 'A', 'B', isActive: false);
        self::assertFalse($user->can(Permission::MailView));
        self::assertSame('roles.admin', Role::Admin->labelKey());
    }
}
