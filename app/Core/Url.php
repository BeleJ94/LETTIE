<?php

declare(strict_types=1);

namespace App\Core;

final class Url
{
    private readonly string $basePath;

    public function __construct(string $basePath, private readonly Router $router)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function to(string $path): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    /** @param array<string, string|int> $params */
    public function route(string $name, array $params = []): string
    {
        return $this->basePath . $this->router->path($name, $params);
    }

    /** True for an app-relative path that cannot redirect off-site. */
    public static function isSafeLocalPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\')
            && preg_match('/[\x00-\x1F]/', $path) === 0;
    }
}
