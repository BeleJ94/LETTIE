<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

interface Middleware
{
    /**
     * @param Closure(Request): Response $next
     * @param string ...$args parameters from the route spec, e.g. "can:mail.view,mail.create"
     */
    public function handle(Request $request, Closure $next, string ...$args): Response;
}
