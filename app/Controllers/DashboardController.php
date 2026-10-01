<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AuthService;
use App\Services\DeadlineService;

final class DashboardController
{
    use HandlesDomain;

    public function __construct(
        private readonly DeadlineService $deadlines,
        private readonly AuthService $auth,
        private readonly View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('home/index', $this->deadlines->dashboard($this->actor($request))));
    }
}
