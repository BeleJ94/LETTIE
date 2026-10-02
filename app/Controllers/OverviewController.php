<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AuthService;
use App\Services\OverviewService;

final class OverviewController
{
    use HandlesDomain;

    public function __construct(
        private readonly OverviewService $overview,
        private readonly AuthService $auth,
        private readonly View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('overview/index', $this->overview->page($this->actor($request))));
    }
}
