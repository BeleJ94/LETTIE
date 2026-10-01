<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Loads lang/{locale}.php (flat or nested arrays, accessed with dot keys).
 * Placeholders ":name" are replaced from $replace. Missing keys fall back
 * to the fallback locale, then to the key itself.
 */
final class Translator
{
    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    private string $locale;

    public function __construct(
        private readonly string $langDir,
        string $locale = 'fr',
        private readonly string $fallback = 'fr',
    ) {
        $this->setLocale($locale);
    }

    /** @return list<string> */
    public function available(): array
    {
        $locales = [];
        foreach (glob(rtrim($this->langDir, '/\\') . '/*.php') ?: [] as $file) {
            $locales[] = basename($file, '.php');
        }
        sort($locales);
        return $locales;
    }

    public function setLocale(string $locale): void
    {
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale) || !in_array($locale, $this->available(), true)) {
            throw new InvalidArgumentException("Unsupported locale: {$locale}");
        }
        $this->locale = $locale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, string|int|float> $replace */
    public function get(string $key, array $replace = []): string
    {
        $line = $this->lookup($this->locale, $key) ?? $this->lookup($this->fallback, $key) ?? $key;

        if ($replace === []) {
            return $line;
        }
        // Whole placeholder names only: ":page" must not eat the start of ":pages".
        return (string) preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static fn (array $m): string => array_key_exists($m[1], $replace) ? (string) $replace[$m[1]] : $m[0],
            $line,
        );
    }

    public function has(string $key): bool
    {
        return $this->lookup($this->locale, $key) !== null;
    }

    /**
     * A whole group of lines (e.g. "js" for the front-end), current locale over fallback.
     *
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        $fallback = $this->raw($this->fallback, $key);
        $current = $this->raw($this->locale, $key);
        return array_replace_recursive(is_array($fallback) ? $fallback : [], is_array($current) ? $current : []);
    }

    private function lookup(string $locale, string $key): ?string
    {
        $value = $this->raw($locale, $key);
        return is_string($value) ? $value : null;
    }

    private function raw(string $locale, string $key): mixed
    {
        $lines = $this->load($locale);
        if (array_key_exists($key, $lines)) {
            return $lines[$key];
        }
        $value = $lines;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function load(string $locale): array
    {
        if (!isset($this->loaded[$locale])) {
            $file = rtrim($this->langDir, '/\\') . "/{$locale}.php";
            $lines = is_file($file) ? require $file : [];
            $this->loaded[$locale] = is_array($lines) ? $lines : [];
        }
        return $this->loaded[$locale];
    }
}
