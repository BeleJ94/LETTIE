<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use InvalidArgumentException;

/**
 * Routes: "/mails/{id}" or "/mails/{id:\d+}". Default parameter pattern is [^/]+.
 * Handlers: [ControllerClass::class, 'method'] or a Closure(Request): Response.
 * Middleware specs: "auth", "can:mail.view,mail.create" (aliases resolved by the Kernel).
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, name: ?string, regex: string, handler: array{0: class-string, 1: string}|Closure, middleware: list<string>}> */
    private array $routes = [];

    /** @var array<string, string> */
    private array $named = [];

    /** @var list<string> */
    private array $groupMiddleware = [];

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param list<string> $middleware
     */
    public function add(string $method, string $pattern, array|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $pattern = '/' . trim($pattern, '/');
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'name' => $name,
            'regex' => self::compile($pattern),
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
        if ($name !== null) {
            if (isset($this->named[$name])) {
                throw new InvalidArgumentException("Duplicate route name: {$name}");
            }
            $this->named[$name] = $pattern;
        }
    }

    /**
     * Applies $middleware to every route declared inside $routes.
     *
     * @param list<string> $middleware
     * @param Closure(Router): void $routes
     */
    public function group(array $middleware, Closure $routes): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = [...$previous, ...$middleware];
        try {
            $routes($this);
        } finally {
            $this->groupMiddleware = $previous;
        }
    }

    /** @param array{0: class-string, 1: string}|Closure $handler
     *  @param list<string> $middleware */
    public function get(string $pattern, array|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $name, $middleware);
    }

    /** @param array{0: class-string, 1: string}|Closure $handler
     *  @param list<string> $middleware */
    public function post(string $pattern, array|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $name, $middleware);
    }

    /** @param array{0: class-string, 1: string}|Closure $handler
     *  @param list<string> $middleware */
    public function put(string $pattern, array|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $name, $middleware);
    }

    /** @param array{0: class-string, 1: string}|Closure $handler
     *  @param list<string> $middleware */
    public function patch(string $pattern, array|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('PATCH', $pattern, $handler, $name, $middleware);
    }

    /** @param array{0: class-string, 1: string}|Closure $handler
     *  @param list<string> $middleware */
    public function delete(string $pattern, array|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $name, $middleware);
    }

    private static function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern,
        );
        return '#^' . $regex . '$#u';
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}|Closure, params: array<string, string>, middleware: list<string>}
     * @throws HttpException 404 or 405
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $lookup) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['handler' => $route['handler'], 'params' => $params, 'middleware' => $route['middleware']];
        }

        if ($allowed !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($allowed)));
        }
        throw HttpException::notFound();
    }

    /**
     * Every declared route (tests use it to check that access rules cover all routes).
     *
     * @return list<array{method: string, pattern: string, name: ?string, middleware: list<string>}>
     */
    public function routes(): array
    {
        return array_map(static fn (array $r): array => [
            'method' => $r['method'],
            'pattern' => $r['pattern'],
            'name' => $r['name'],
            'middleware' => $r['middleware'],
        ], $this->routes);
    }

    /** @param array<string, string|int> $params */
    public function path(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new InvalidArgumentException("Unknown route: {$name}");
        }
        $path = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
            static function (array $m) use ($params, $name): string {
                if (!array_key_exists($m[1], $params)) {
                    throw new InvalidArgumentException("Missing parameter {$m[1]} for route {$name}");
                }
                return rawurlencode((string) $params[$m[1]]);
            },
            $this->named[$name],
        );
        return (string) $path;
    }
}
