<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use InvalidArgumentException;

/** ENT-2026-00001: direction prefix, registration year, 5-digit number per site. */
final class MailReference
{
    public const DIGITS = 5;

    public static function format(Direction $direction, int $year, int $number): string
    {
        if ($year < 1900 || $year > 9999) {
            throw new InvalidArgumentException("Invalid year: {$year}");
        }
        if ($number < 1) {
            throw new InvalidArgumentException("Invalid sequence number: {$number}");
        }
        // Past 99999 the number simply grows: references stay unique and sortable per year.
        return sprintf('%s-%04d-%0' . self::DIGITS . 'd', $direction->prefix(), $year, $number);
    }

    /** @return array{direction: Direction, year: int, number: int}|null */
    public static function parse(string $reference): ?array
    {
        if (!preg_match('/^(ENT|SOR)-(\d{4})-(\d{' . self::DIGITS . ',})$/', $reference, $m)) {
            return null;
        }
        return [
            'direction' => $m[1] === 'ENT' ? Direction::Incoming : Direction::Outgoing,
            'year' => (int) $m[2],
            'number' => (int) $m[3],
        ];
    }
}
