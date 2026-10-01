<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Url;
use App\Services\AuthService;
use Closure;

final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(private readonly AuthService $auth, private readonly Url $url)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if ($this->auth->user() !== null) {
            return Response::redirect($this->url->route('home'));
        }
        return $next($request);
    }
}
