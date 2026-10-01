<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Container;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Services\AuthService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelTest extends TestCase
{
    private Kernel $kernel;
    private Session $session;

    protected function setUp(): void
    {
        $this->kernel = $this->bootWith(null);
    }

    private function bootWith(?User $user): Kernel
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), new Env([
            'APP_TIMEZONE' => 'Europe/Paris',
            'APP_LOCALE' => 'en',
            'DB_NAME' => 'unused',
        ]));
        $this->session = Session::inMemory();
        $container = $kernel->container();
        $container->instance(Session::class, $this->session);

        $auth = $this->createStub(AuthService::class);
        $auth->method('user')->willReturn($user);
        $container->instance(AuthService::class, $auth);

        $router = $container->get(Router::class);
        $router->get('/test/hello/{name}', static fn (Request $r): Response => new Response('Hello ' . $r->param('name')));
        $router->post('/test/save', static fn (): Response => new Response('saved'));
        $router->get('/test/fail', static fn (): Response => throw new RuntimeException('secret detail'));
        $router->get('/test/invalid', static fn (): Response => throw new \App\Core\ValidationException(['subject' => ['Required']]));
        $router->get('/test/admin',static fn (): Response => new Response('admin area'), null, ['auth', 'can:users.manage']);
        // A page rendered with the full layout, without database access.
        $router->get('/test/page', static fn (): Response => Response::html($container->get(\App\Core\View::class)->render('notifications/index', ['notifications' => []])), null, ['auth']);
        return $kernel;
    }

    private static function user(Role $role): User
    {
        return new User(1, 1, null, $role, 'a@b.fr', 'x', 'Ana', 'Martin');
    }

    public function testBootSetsTimezone(): void
    {
        self::assertSame('Europe/Paris', date_default_timezone_get());
        self::assertInstanceOf(Container::class, $this->kernel->container());
    }

    public function testDispatchesRouteWithParams(): void
    {
        self::assertSame('Hello Ana', $this->kernel->handle(new Request('GET', '/test/hello/Ana'))->body());
    }

    public function testSecurityHeaders(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/login', server: ['HTTPS' => 'on']));

        $csp = (string) $response->header('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertStringStartsWith('max-age=', (string) $response->header('Strict-Transport-Security'));
    }

    public function testNoHstsOverPlainHttp(): void
    {
        self::assertNull($this->kernel->handle(new Request('GET', '/login'))->header('Strict-Transport-Security'));
    }

    public function testGuestIsRedirectedFromHomeToLogin(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/'));
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function testLoginPageRenders(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/login'));
        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="password"', $response->body());
        self::assertStringContainsString('name="_csrf"', $response->body());
    }

    public function testAuthenticatedUserSeesHomeAndIsRedirectedFromLogin(): void
    {
        $kernel = $this->bootWith(self::user(Role::Agent));
        $home = $kernel->handle(new Request('GET', '/test/page'));
        self::assertSame(200, $home->status());
        self::assertStringContainsString('Ana Martin', $home->body());

        self::assertSame('/', $kernel->handle(new Request('GET', '/login'))->header('Location'));
    }

    public function testMissingPermissionIs403(): void
    {
        $response = $this->bootWith(self::user(Role::Agent))->handle(new Request('GET', '/test/admin'));
        self::assertSame(403, $response->status());
    }

    public function testPermissionGranted(): void
    {
        self::assertSame('admin area', $this->bootWith(self::user(Role::Admin))->handle(new Request('GET', '/test/admin'))->body());
    }

    public function testNotFoundRendersErrorView(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/nope'));
        self::assertSame(404, $response->status());
        self::assertStringContainsString('Page not found.', $response->body());
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        self::assertSame(419, $this->kernel->handle(new Request('POST', '/test/save'))->status());
    }

    public function testPostWithCsrfTokenSucceeds(): void
    {
        $token = (new Csrf($this->session))->token();
        $response = $this->kernel->handle(new Request('POST', '/test/save', post: [Csrf::FIELD => $token]));
        self::assertSame('saved', $response->body());
    }

    public function testErrorsAreJsonForAjaxAndHideDetails(): void
    {
        $previous = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $response = $this->kernel->handle(new Request('GET', '/test/fail', server: ['HTTP_ACCEPT' => 'application/json']));
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertSame(500, $response->status());
        self::assertSame('{"error":"Server Error"}', $response->body());
    }

    public function testValidationErrorsAre422ForAjax(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/test/invalid', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        self::assertSame(422, $response->status());
        self::assertSame(['subject' => ['Required']], json_decode($response->body(), true)['errors']);
    }

    public function testLayoutExposesConfigToJavascript(): void
    {
        $body = $this->bootWith(self::user(Role::Agent))->handle(new Request('GET', '/test/page'))->body();
        self::assertStringContainsString('data-timezone="Europe/Paris"', $body);
        self::assertStringContainsString('data-i18n="{&quot;js&quot;:', $body);
        self::assertStringContainsString('data-lt-notifications data-url="/notifications/count"', $body);
        self::assertStringContainsString('/assets/js/app.js', $body);
    }

    public function testLocaleSwitchStoresLocaleAndRedirects(): void
    {
        $token = (new Csrf($this->session))->token();
        $response = $this->kernel->handle(new Request('POST', '/locale', post: [Csrf::FIELD => $token, 'locale' => 'fr']));

        self::assertSame(302, $response->status());
        self::assertSame('fr', $this->session->get('locale'));
    }
}
