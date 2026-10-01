<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Minimal .env loader: KEY=VALUE lines, # comments, optional single/double quotes.
 * Real environment variables take precedence over file values.
 */
final class Env
{
    /** @param array<string, string> $values */
    public function __construct(private array $values = [])
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Environment file not readable: {$path}");
        }

        return self::parse((string) file_get_contents($path));
    }

    public static function parse(string $content): self
    {
        $values = [];
        $lines = preg_split('/\R/', $content) ?: [];

        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                throw new RuntimeException('Invalid .env syntax on line ' . ($number + 1));
            }
            $values[$m[1]] = self::parseValue($m[2]);
        }

        return new self($values);
    }

    private static function parseValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $quote = $raw[0];
        if (($quote === '"' || $quote === "'") && strlen($raw) >= 2 && str_ends_with($raw, $quote)) {
            $value = substr($raw, 1, -1);
            return $quote === '"' ? str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $value) : $value;
        }
        // Strip trailing inline comment on unquoted values.
        $hash = strpos($raw, ' #');
        return $hash === false ? $raw : rtrim(substr($raw, 0, $hash));
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        return $this->values[$key] ?? $default;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new RuntimeException("Missing required environment variable: {$key}");
        }
        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }
}
