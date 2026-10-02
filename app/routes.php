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
use App\Controllers\StatisticsController;
use App\Controllers\WorkflowController;
use App\Controllers\HomeController;
use App\Controllers\MailController;
use App\Core\Router;

return static function (Router $router): void {
    $router->group(['guest'], static function (Router $router): void {
        $router->get('/login', [AuthController::class, 'showLogin'], 'login');
        $router->post('/login', [AuthController::class, 'login'], 'login.submit');
    });

    $router->post('/locale', [HomeController::class, 'switchLocale'], 'locale.switch');

    $router->group(['auth'], static function (Router $router): void {
        $router->post('/logout', [AuthController::class, 'logout'], 'logout');
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
