<?php

declare(strict_types=1);

use App\Core\Translator;

if (!function_exists('e')) {
    /** Escape a value for HTML text and attribute contexts. */
    function e(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }
        if (!is_scalar($value) && !$value instanceof Stringable) {
            throw new InvalidArgumentException('e() expects a scalar or Stringable value.');
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('local_datetime')) {
    /**
     * Formats a UTC instant in the application time zone (APP_TIMEZONE).
     * Not escaped: wrap in e() in views.
     */
    function local_datetime(?DateTimeInterface $utc, string $format = 'd/m/Y H:i'): string
    {
        if ($utc === null) {
            return '';
        }
        return DateTimeImmutable::createFromInterface($utc)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format($format);
    }
}

if (!function_exists('local_date')) {
    /** "Y-m-d" calendar date → display format (no time zone conversion). */
    function local_date(?string $date, string $format = 'd/m/Y'): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed === false ? $date : $parsed->format($format);
    }
}

if (!function_exists('__')) {
    /**
     * Translate a key with the application translator (not escaped: wrap in e() in views).
     *
     * @param array<string, string|int|float> $replace
     */
    function __(string $key, array $replace = []): string
    {
        $translator = App\Core\Kernel::translator();
        return $translator instanceof Translator ? $translator->get($key, $replace) : $key;
    }
}
