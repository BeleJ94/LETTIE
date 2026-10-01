<?php

declare(strict_types=1);

namespace App\Domain\Auth;

enum Role: string
{
    case Admin = 'admin';
    case Secretariat = 'secretariat';
    case HeadOfDepartment = 'head_of_department';
    case Agent = 'agent';
    case Management = 'management';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Secretariat => [
                Permission::MailView,
                Permission::MailCreate,
                Permission::MailUpdate,
                Permission::MailAssign,
                Permission::MailAnnotate,
                Permission::CorrespondentsManage,
            ],
            self::HeadOfDepartment => [
                Permission::MailView,
                Permission::MailUpdate,
                Permission::MailAssign,
                Permission::MailAnnotate,
                Permission::ReportsView,
            ],
            self::Agent => [
                Permission::MailView,
                Permission::MailUpdate,
                Permission::MailAnnotate,
            ],
            self::Management => [
                Permission::MailView,
                Permission::MailAnnotate,
                Permission::ReportsView,
                Permission::SitesAll,
            ],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function labelKey(): string
    {
        return 'roles.' . $this->value;
    }
}
