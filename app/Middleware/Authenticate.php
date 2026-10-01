<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use App\Services\AuthService;
use Closure;

final class Authenticate implements Middleware
{
    public const INTENDED_KEY = 'auth.intended';

    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
        private readonly Url $url,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            if ($request->wantsJson()) {
                throw HttpException::unauthorized();
            }
            if ($request->isMethod('GET')) {
                $this->session->set(self::INTENDED_KEY, $request->path());
            }
            return Response::redirect($this->url->route('login'));
        }

        $this->view->share('currentUser', $user);
        return $next($request);
    }
}
