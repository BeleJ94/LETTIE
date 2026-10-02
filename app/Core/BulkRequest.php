<?php

declare(strict_types=1);

namespace App\Core;

/** Parsing of the "ids" parameter sent by list selections ("12,15,18" or an array). */
final class BulkRequest
{
    /** A selection never exceeds what one list page can show several times over. */
    public const MAX_IDS = 200;

    /**
     * Positive, distinct integers, in the order given. Anything else is dropped;
     * beyond MAX_IDS the list is cut.
     *
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        $parts = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
        $ids = [];
        foreach ($parts as $part) {
            $id = is_int($part) ? $part : (is_string($part) && ctype_digit(trim($part)) ? (int) trim($part) : 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
            if (count($ids) >= self::MAX_IDS) {
                break;
            }
        }
        return array_values($ids);
    }
}
