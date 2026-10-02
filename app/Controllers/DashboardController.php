<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AuthService;
use App\Services\LaunchpadService;

final class DashboardController
{
    use HandlesDomain;

    public function __construct(
        private readonly LaunchpadService $launchpad,
        private readonly AuthService $auth,
        private readonly View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('home/index', $this->launchpad->home($this->actor($request))));
    }

    /** GET /navigation/counts → {"mine_overdue": n, "unassigned": n} (counters of the side navigation) */
    public function counts(Request $request): Response
    {
        return Response::json($this->launchpad->navigationCounts($this->actor($request)));
    }
}
