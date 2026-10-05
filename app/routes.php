<?php

declare(strict_types=1);

use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\CorrespondentController;
use App\Controllers\DashboardController;
use App\Controllers\DelegationController;
use App\Controllers\NotificationController;
use App\Controllers\OverviewController;
use App\Controllers\RegisterController;
use App\Controllers\RetentionController;
use App\Controllers\OrganizationController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Controllers\StatisticsController;
use App\Controllers\UserController;
use App\Controllers\WorkflowController;
use App\Controllers\HomeController;
use App\Controllers\MailController;
use App\Core\Router;

return static function (Router $router): void {
    $router->group(['guest'], static function (Router $router): void {
        $router->get('/login', [AuthController::class, 'showLogin'], 'login');
        $router->post('/login', [AuthController::class, 'login'], 'login.submit');
        $router->get('/login/code', [AuthController::class, 'showCode'], 'login.code');
        $router->post('/login/code', [AuthController::class, 'code'], 'login.code.submit');
        $router->get('/password/forgot', [PasswordResetController::class, 'showForgot'], 'password.forgot');
        $router->post('/password/forgot', [PasswordResetController::class, 'forgot'], 'password.forgot.submit');
        $router->get('/password/reset/{token:[a-f0-9]+}', [PasswordResetController::class, 'showReset'], 'password.reset');
        $router->post('/password/reset/{token:[a-f0-9]+}', [PasswordResetController::class, 'reset'], 'password.reset.submit');
    });

    $router->post('/locale', [HomeController::class, 'switchLocale'], 'locale.switch');

    $router->group(['auth'], static function (Router $router): void {
        $router->post('/logout', [AuthController::class, 'logout'], 'logout');
        $router->get('/profile', [ProfileController::class, 'show'], 'profile');
        $router->put('/profile/password', [ProfileController::class, 'password'], 'profile.password');
        $router->post('/profile/2fa/start', [ProfileController::class, 'totpStart'], 'profile.totp.start');
        $router->post('/profile/2fa/cancel', [ProfileController::class, 'totpCancel'], 'profile.totp.cancel');
        $router->post('/profile/2fa/enable', [ProfileController::class, 'totpEnable'], 'profile.totp.enable');
        $router->post('/profile/2fa/disable', [ProfileController::class, 'totpDisable'], 'profile.totp.disable');
        $router->get('/', [DashboardController::class, 'index'], 'home', ['can:mail.view']);
        $router->get('/navigation/counts', [DashboardController::class, 'counts'], 'navigation.counts', ['can:mail.view']);

        // Notifications (each user sees their own)
        $router->get('/notifications', [NotificationController::class, 'index'], 'notifications.index');
        $router->get('/notifications/count', [NotificationController::class, 'count'], 'notifications.count');
        $router->post('/notifications/read-all', [NotificationController::class, 'readAll'], 'notifications.read_all');
        $router->post('/notifications/{id:\d+}/read', [NotificationController::class, 'read'], 'notifications.read');

        // Statistics
        $router->group(['can:reports.view'], static function (Router $router): void {
            $router->get('/overview', [OverviewController::class, 'index'], 'overview.index');
            $router->get('/statistics', [StatisticsController::class, 'index'], 'statistics.index');
            $router->get('/statistics/data', [StatisticsController::class, 'data'], 'statistics.data');
        });

        // Retention rules (administration)
        $router->group(['can:settings.manage'], static function (Router $router): void {
            $router->get('/retention-rules', [RetentionController::class, 'index'], 'retention.index');
            $router->post('/retention-rules', [RetentionController::class, 'store'], 'retention.store');
            $router->post('/retention-rules/{id:\d+}/toggle', [RetentionController::class, 'toggle'], 'retention.toggle');
        });

        // Mail
        $router->group(['can:mail.view'], static function (Router $router): void {
            $router->get('/mails', [MailController::class, 'index'], 'mails.index');
            $router->get('/mails/data', [MailController::class, 'data'], 'mails.data');
            $router->get('/mails/export', [MailController::class, 'export'], 'mails.export');
            $router->get('/mails/{id:\d+}/slip', [MailController::class, 'slip'], 'mails.slip');
            $router->get('/register', [RegisterController::class, 'index'], 'register.index');
            $router->get('/register/data', [RegisterController::class, 'data'], 'register.data');
            $router->get('/mails/{id:\d+}', [MailController::class, 'show'], 'mails.show');
            $router->get('/attachments/{id:\d+}', [AttachmentController::class, 'download'], 'attachments.download');
            $router->get('/correspondents/search', [CorrespondentController::class, 'search'], 'correspondents.search');
        });
        $router->group(['can:mail.create'], static function (Router $router): void {
            $router->get('/mails/new', [MailController::class, 'create'], 'mails.create');
            $router->post('/mails', [MailController::class, 'store'], 'mails.store');
        });
        $router->group(['can:mail.update'], static function (Router $router): void {
            $router->get('/mails/{id:\d+}/edit', [MailController::class, 'edit'], 'mails.edit');
            $router->put('/mails/{id:\d+}', [MailController::class, 'update'], 'mails.update');
            $router->post('/mails/{id:\d+}/attachments', [AttachmentController::class, 'store'], 'attachments.store');
        });

        // Workflow (finer checks — assignee, status — are made by WorkflowService)
        $router->post('/mails/bulk/assign', [WorkflowController::class, 'bulkAssign'], 'workflow.bulk_assign', ['can:mail.assign']);
        $router->post('/mails/bulk/close', [WorkflowController::class, 'bulkClose'], 'workflow.bulk_close', ['can:mail.update']);
        $router->post('/mails/{id:\d+}/assign', [WorkflowController::class, 'assign'], 'workflow.assign', ['can:mail.assign']);
        $router->post('/mails/{id:\d+}/reassign', [WorkflowController::class, 'reassign'], 'workflow.reassign', ['can:mail.assign']);
        $router->post('/mails/{id:\d+}/actions/{action:[a-z_]+}', [WorkflowController::class, 'action'], 'workflow.action', ['can:mail.update']);
        $router->post('/mails/{id:\d+}/annotations', [WorkflowController::class, 'annotate'], 'workflow.annotate', ['can:mail.annotate']);
        $router->post('/mails/{id:\d+}/reply-link', [WorkflowController::class, 'linkReply'], 'workflow.link_reply', ['can:mail.update']);

        // Absence delegations: everyone manages their own
        $router->group(['can:mail.view'], static function (Router $router): void {
            $router->get('/delegations', [DelegationController::class, 'index'], 'delegations.index');
            $router->post('/delegations', [DelegationController::class, 'store'], 'delegations.store');
            $router->post('/delegations/{id:\d+}/cancel', [DelegationController::class, 'cancel'], 'delegations.cancel');
        });

        // Correspondents
        $router->group(['can:settings.manage'], static function (Router $router): void {
            $router->get('/organization', [OrganizationController::class, 'index'], 'organization.index');
            $router->post('/sites', [OrganizationController::class, 'storeSite'], 'sites.store');
            $router->put('/sites/{id:\d+}', [OrganizationController::class, 'updateSite'], 'sites.update');
            $router->post('/sites/{id:\d+}/toggle', [OrganizationController::class, 'toggleSite'], 'sites.toggle');
            $router->post('/departments', [OrganizationController::class, 'storeDepartment'], 'departments.store');
            $router->put('/departments/{id:\d+}', [OrganizationController::class, 'updateDepartment'], 'departments.update');
            $router->post('/departments/{id:\d+}/toggle', [OrganizationController::class, 'toggleDepartment'], 'departments.toggle');
        });

        $router->group(['can:users.manage'], static function (Router $router): void {
            $router->get('/users', [UserController::class, 'index'], 'users.index');
            $router->get('/users/data', [UserController::class, 'data'], 'users.data');
            $router->get('/users/export', [UserController::class, 'export'], 'users.export');
            $router->get('/roles', [UserController::class, 'roles'], 'roles.index');
            $router->get('/users/import', [UserController::class, 'importForm'], 'users.import');
            $router->post('/users/import', [UserController::class, 'importCheck'], 'users.import.check');
            $router->post('/users/import/confirm', [UserController::class, 'importConfirm'], 'users.import.confirm');
            $router->get('/users/new', [UserController::class, 'create'], 'users.create');
            $router->post('/users', [UserController::class, 'store'], 'users.store');
            $router->get('/users/{id:\d+}/edit', [UserController::class, 'edit'], 'users.edit');
            $router->put('/users/{id:\d+}', [UserController::class, 'update'], 'users.update');
            $router->post('/users/{id:\d+}/password', [UserController::class, 'password'], 'users.password');
            $router->post('/users/{id:\d+}/unlock', [UserController::class, 'unlock'], 'users.unlock');
            $router->post('/users/{id:\d+}/2fa/reset', [UserController::class, 'resetTotp'], 'users.totp.reset');
        });

        $router->group(['can:correspondents.manage'], static function (Router $router): void {
            $router->get('/correspondents', [CorrespondentController::class, 'index'], 'correspondents.index');
            $router->get('/correspondents/data', [CorrespondentController::class, 'data'], 'correspondents.data');
            $router->get('/correspondents/new', [CorrespondentController::class, 'create'], 'correspondents.create');
            $router->post('/correspondents', [CorrespondentController::class, 'store'], 'correspondents.store');
            $router->get('/correspondents/{id:\d+}/edit', [CorrespondentController::class, 'edit'], 'correspondents.edit');
            $router->put('/correspondents/{id:\d+}', [CorrespondentController::class, 'update'], 'correspondents.update');
        });
    });
};
