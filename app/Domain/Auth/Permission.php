<?php

declare(strict_types=1);

namespace App\Domain\Auth;

enum Permission: string
{
    case MailView = 'mail.view';
    case MailCreate = 'mail.create';
    case MailUpdate = 'mail.update';
    case MailAssign = 'mail.assign';
    case MailAnnotate = 'mail.annotate';
    case MailDelete = 'mail.delete';
    case CorrespondentsManage = 'correspondents.manage';
    case ReportsView = 'reports.view';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    /** Access to the data of every site (otherwise: own site only). */
    case SitesAll = 'sites.all';
}
