<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Wires the core services and turns a Request into a Response
 * (session, CSRF, route middleware pipeline, errors, security headers).
 */
final class Kernel
{
    /** No inline script or style is allowed: every script lives in public/js. */
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; "
        . "base-uri 'none'; object-src 'none'; frame-ancestors 'none'";

    private static ?Translator $translator = null;

    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
    ) {
    }

    public static function boot(string $rootDir, ?Env $env = null): self
    {
        $env ??= Env::load($rootDir . '/.env');
        date_default_timezone_set($env->get('APP_TIMEZONE', 'UTC') ?? 'UTC');
        $basePath = rtrim($env->get('APP_BASE_PATH', '') ?? '', '/');

        $container = new Container();
        $container->instance(Env::class, $env);
        $container->instance(Container::class, $container);
        $container->set(PDO::class, static fn (Container $c): PDO => Database::connect($c->get(Env::class)));
        $container->set(Clock::class, static fn (): Clock => new Clock());
        $container->set(Session::class, static fn (): Session => new Session(
            name: $env->get('SESSION_NAME', 'lettie_session') ?? 'lettie_session',
            secure: $env->bool('SESSION_SECURE', true),
            lifetime: $env->int('SESSION_LIFETIME', 7200),
            path: $basePath . '/',
            sameSite: $env->get('SESSION_SAMESITE', 'Lax') ?? 'Lax',
        ));
        $container->set(Translator::class, static fn (): Translator => new Translator(
            $rootDir . '/lang',
            $env->get('APP_LOCALE', 'fr') ?? 'fr',
        ));
        $container->set(View::class, static function () use ($rootDir, $basePath): View {
            $view = new View($rootDir . '/views');
            $view->share('basePath', $basePath);
            $view->share('currentUser', null);
            return $view;
        });

        $router = new Router();
        $container->instance(Router::class, $router);
        $container->set(Url::class, static fn (): Url => new Url($basePath, $router));

        foreach (['container', 'routes'] as $file) {
            $path = $rootDir . "/app/{$file}.php";
            if (is_file($path)) {
                (require $path)($file === 'routes' ? $router : $container);
            }
        }

        self::$translator = $container->get(Translator::class);
        return new self($container, $router);
    }

    public static function translator(): ?Translator
    {
        return self::$translator;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function handle(Request $request): Response
    {
        try {
            $session = $this->container->get(Session::class);
            $session->start();
            $this->applyLocale($session);

            $csrf = $this->container->get(Csrf::class);
            $csrf->verify($request);

            $view = $this->container->get(View::class);
            $view->share('csrf', $csrf);
            $view->share('locale', self::$translator?->locale());
            $view->share('timezone', date_default_timezone_get());
            $view->share('currentPath', $request->path());
            $view->share('i18n', [
                'js' => self::$translator?->section('js') ?? [],
                'enums' => self::$translator?->section('enums') ?? [],
            ]);
            $view->share('flash', [
                'success' => $session->getFlash('flash.success'),
                'error' => $session->getFlash('flash.error'),
            ]);

            $match = $this->router->match($request->method(), $request->path());
            $request = $request->withRouteParams($match['params']);
            $response = $this->pipeline($match['handler'], $match['middleware'])($request);
        } catch (ValidationException $e) {
            // Controllers handle HTML forms themselves; AJAX callers get a 422 with field errors.
            $response = $request->wantsJson()
                ? Response::json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422)
                : $this->error($request, 500, $e->getMessage());
        } catch (HttpException $e) {
            $response = $this->error($request, $e->status(), $e->getMessage());
            foreach ($e->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        } catch (Throwable $e) {
            $env = $this->container->get(Env::class);
            error_log((string) $e);
            $message = $env->bool('APP_DEBUG') ? $e->getMessage() : 'Server Error';
            $response = $this->error($request, 500, $message);
        }

        return $this->securityHeaders($request, $response);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param list<string> $specs
     * @return Closure(Request): Response
     */
    private function pipeline(array|Closure $handler, array $specs): Closure
    {
        $next = fn (Request $request): Response => $this->call($handler, $request);

        foreach (array_reverse($specs) as $spec) {
            [$name, $argString] = array_pad(explode(':', $spec, 2), 2, '');
            $args = $argString === '' ? [] : array_map('trim', explode(',', $argString));
            $middleware = $this->resolveMiddleware($name);
            $inner = $next;
            $next = static fn (Request $request): Response => $middleware->handle($request, $inner, ...$args);
        }
        return $next;
    }

    private function resolveMiddleware(string $name): Middleware
    {
        $aliases = $this->container->has('middleware.aliases') ? $this->container->get('middleware.aliases') : [];
        $class = $aliases[$name] ?? $name;
        $middleware = $this->container->get($class);
        if (!$middleware instanceof Middleware) {
            throw new RuntimeException("{$class} is not a middleware.");
        }
        return $middleware;
    }

    private function applyLocale(Session $session): void
    {
        $locale = $session->get('locale');
        if (is_string($locale) && self::$translator !== null) {
            try {
                self::$translator->setLocale($locale);
            } catch (Throwable) {
                $session->remove('locale');
            }
        }
    }

    /** @param array{0: class-string, 1: string}|Closure $handler */
    private function call(array|Closure $handler, Request $request): Response
    {
        if ($handler instanceof Closure) {
            $response = $handler($request);
        } else {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            if (!method_exists($controller, $method)) {
                throw new RuntimeException("Unknown action {$class}::{$method}");
            }
            $response = $controller->{$method}($request);
        }
        if (!$response instanceof Response) {
            throw new RuntimeException('Route handlers must return a Response.');
        }
        return $response;
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }
        try {
            $html = $this->container->get(View::class)->render('errors/error', [
                'status' => $status,
                'message' => $message,
            ]);
        } catch (Throwable) {
            $html = '<h1>' . $status . '</h1><p>' . e($message) . '</p>';
        }
        return Response::html($html, $status);
    }

    private function securityHeaders(Request $request, Response $response): Response
    {
        if ($response->header('Content-Security-Policy') === null) {
            // A response may carry a stricter policy (e.g. file downloads); pages get the default one.
            $response = $response->withHeader('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }
        $response = $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');

        if ($response->header('Cache-Control') === null) {
            // Mail data is confidential: never keep pages in shared or browser caches.
            $response = $response->withHeader('Cache-Control', 'no-store');
        }
        if ($request->isSecure()) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}
