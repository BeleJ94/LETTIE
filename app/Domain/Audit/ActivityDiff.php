<?php

declare(strict_types=1);

namespace App\Domain\Audit;

final class ActivityDiff
{
    /**
     * Keeps only the fields whose value changed.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [old values, new values]
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if (self::normalize($a) !== self::normalize($b)) {
                $old[$key] = $a;
                $new[$key] = $b;
            }
        }
        return [$old, $new];
    }

    /** "3" and 3 are the same value; "" and null too. */
    private static function normalize(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $value;
    }
}
