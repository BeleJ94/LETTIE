<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\Permission;
use App\Services\AuthService;
use Closure;
use InvalidArgumentException;

/**
 * Route spec "can:mail.view,mail.create": the user needs every listed permission.
 */
final class Authorize implements Middleware
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if ($args === []) {
            throw new InvalidArgumentException('The "can" middleware needs at least one permission.');
        }
        $permissions = array_map(static function (string $code): Permission {
            return Permission::tryFrom($code) ?? throw new InvalidArgumentException("Unknown permission: {$code}");
        }, $args);

        $user = $this->auth->user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }
        foreach ($permissions as $permission) {
            if (!$user->can($permission)) {
                throw HttpException::forbidden();
            }
        }
        return $next($request);
    }
}
