<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable HTTP request. The path is relative to APP_BASE_PATH.
 */
final class Request
{
    private const OVERRIDABLE = ['PUT', 'PATCH', 'DELETE'];

    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, string> */
    private array $routeParams = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
    ) {
        $this->headers = self::extractHeaders($server);
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $override = strtoupper((string) ($_POST['_method'] ?? ''));
        if ($method === 'POST' && in_array($override, self::OVERRIDABLE, true)) {
            $method = $override;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return new self(
            $method,
            self::normalizePath($uri, $basePath),
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            $_FILES,
        );
    }

    public static function normalizePath(string $uri, string $basePath = ''): string
    {
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?? '/'));
        $basePath = rtrim($basePath, '/');

        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }
        if (str_ends_with($path, '/index.php')) {
            $path = substr($path, 0, -strlen('index.php'));
        }

        $path = '/' . trim($path, '/');
        return $path;
    }

    /** @param array<string, mixed> $server
     *  @return array<string, string> */
    private static function extractHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }
        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** Safe methods do not change state and are exempt from CSRF checks. */
    public function isSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /** Body value first, then query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /** @param list<string> $keys
     *  @return array<string, mixed> */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : $default;
    }

    /** @return array<string, mixed>|null */
    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;
        return is_array($file) ? $file : null;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        return $this->isAjax() || str_contains((string) $this->header('Accept'), 'application/json');
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off') || (int) ($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }

    /** @return array<string, string> */
    public function params(): array
    {
        return $this->routeParams;
    }
}
