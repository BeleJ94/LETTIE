<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Middleware\Authenticate;
use App\Middleware\Authorize;
use App\Middleware\RedirectIfAuthenticated;
use App\Services\AuthService;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    private Url $url;

    protected function setUp(): void
    {
        $router = new Router();
        $router->get('/login', static fn (): Response => new Response(), 'login');
        $router->get('/', static fn (): Response => new Response(), 'home');
        $this->url = new Url('/app', $router);
    }

    private function auth(?User $user): AuthService
    {
        $auth = $this->createStub(AuthService::class);
        $auth->method('user')->willReturn($user);
        return $auth;
    }

    private static function user(Role $role): User
    {
        return new User(1, 1, null, $role, 'a@b.fr', 'x', 'Ana', 'Martin');
    }

    private static function next(): Closure
    {
        return static fn (Request $r): Response => new Response('passed');
    }

    public function testAuthenticateRedirectsGuestsAndRemembersIntendedPath(): void
    {
        $session = Session::inMemory();
        $middleware = new Authenticate($this->auth(null), $session, $this->url, new View(sys_get_temp_dir()));

        $response = $middleware->handle(new Request('GET', '/mails/3'), self::next());

        self::assertSame(302, $response->status());
        self::assertSame('/app/login', $response->header('Location'));
        self::assertSame('/mails/3', $session->get(Authenticate::INTENDED_KEY));
    }

    public function testAuthenticateReturns401ForAjax(): void
    {
        $middleware = new Authenticate($this->auth(null), Session::inMemory(), $this->url, new View(sys_get_temp_dir()));
        try {
            $middleware->handle(new Request('GET', '/x', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), self::next());
            self::fail('Expected 401');
        } catch (HttpException $e) {
            self::assertSame(401, $e->status());
        }
    }

    public function testAuthenticatePassesUsers(): void
    {
        $middleware = new Authenticate($this->auth(self::user(Role::Agent)), Session::inMemory(), $this->url, new View(sys_get_temp_dir()));
        self::assertSame('passed', $middleware->handle(new Request('GET', '/'), self::next())->body());
    }

    public function testGuestMiddleware(): void
    {
        $redirect = (new RedirectIfAuthenticated($this->auth(self::user(Role::Agent)), $this->url))
            ->handle(new Request('GET', '/login'), self::next());
        self::assertSame('/app/', $redirect->header('Location'));

        $pass = (new RedirectIfAuthenticated($this->auth(null), $this->url))->handle(new Request('GET', '/login'), self::next());
        self::assertSame('passed', $pass->body());
    }

    public function testAuthorizeAllowsWhenUserHasEveryPermission(): void
    {
        $middleware = new Authorize($this->auth(self::user(Role::Secretariat)));
        self::assertSame('passed', $middleware->handle(new Request('GET', '/'), self::next(), 'mail.view', 'mail.create')->body());
    }

    public function testAuthorizeForbidsMissingPermission(): void
    {
        $middleware = new Authorize($this->auth(self::user(Role::Agent)));
        try {
            $middleware->handle(new Request('GET', '/'), self::next(), 'mail.view', 'mail.assign');
            self::fail('Expected 403');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testAuthorizeRequiresUser(): void
    {
        try {
            (new Authorize($this->auth(null)))->handle(new Request('GET', '/'), self::next(), 'mail.view');
            self::fail('Expected 401');
        } catch (HttpException $e) {
            self::assertSame(401, $e->status());
        }
    }

    public function testAuthorizeRejectsUnknownPermission(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Authorize($this->auth(self::user(Role::Admin))))->handle(new Request('GET', '/'), self::next(), 'mail.fly');
    }

    public function testSafeLocalPath(): void
    {
        self::assertTrue(Url::isSafeLocalPath('/mails/3'));
        self::assertFalse(Url::isSafeLocalPath('//evil.com'));
        self::assertFalse(Url::isSafeLocalPath('https://evil.com'));
        self::assertFalse(Url::isSafeLocalPath('/\\evil.com'));
    }
}
