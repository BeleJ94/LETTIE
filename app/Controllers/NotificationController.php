<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\Url;
use App\Core\View;
use App\Services\AuthService;
use App\Services\NotificationService;

final class NotificationController
{
    use HandlesDomain;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuthService $auth,
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly View $view,
        private readonly Url $url,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('notifications/index', [
            'notifications' => $this->notifications->forUser($this->actor($request), 100),
        ]));
    }

    /** Polled by app.js to refresh the bell badge. */
    public function count(Request $request): Response
    {
        return Response::json(['unread' => $this->notifications->unreadCount($this->actor($request))]);
    }

    /** Marks as read and opens the related mail. */
    public function read(Request $request): Response
    {
        $mailId = $this->notifications->markRead($this->actor($request), $this->routeId($request));
        if ($mailId === null) {
            throw HttpException::notFound();
        }
        return Response::redirect($mailId > 0 ? $this->url->route('mails.show', ['id' => $mailId]) : $this->url->route('notifications.index'));
    }

    public function readAll(Request $request): Response
    {
        $count = $this->notifications->markAllRead($this->actor($request));
        $this->session->flash('flash.success', $this->translator->get('notification.all_read', ['count' => $count]));
        return Response::redirect($this->url->route('notifications.index'));
    }
}
