<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Code 39 barcode as inline SVG (no library, no font). Readable by any
 * standard scanner: printed on the registration slip so the reference of a
 * paper mail can be scanned. Supports 0-9, A-Z, space and - . $ / + %.
 */
final class Code39
{
    /** Pattern per character: 9 elements (bar, space, bar…), n = narrow, w = wide. */
    private const PATTERNS = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    public const NARROW = 2;
    public const WIDE = 5;

    /**
     * Bar widths (in narrow units) for "*TEXT*": odd positions are bars, even are spaces.
     *
     * @return list<int>
     */
    public static function modules(string $text): array
    {
        $text = strtoupper($text);
        if ($text === '' || str_contains($text, '*')) {
            throw new InvalidArgumentException('Code 39 text must be non-empty and must not contain "*".');
        }
        $widths = [];
        foreach (str_split('*' . $text . '*') as $i => $char) {
            if (!isset(self::PATTERNS[$char])) {
                throw new InvalidArgumentException("Character not supported by Code 39: {$char}");
            }
            if ($i > 0) {
                $widths[] = self::NARROW; // inter-character gap
            }
            foreach (str_split(self::PATTERNS[$char]) as $element) {
                $widths[] = $element === 'w' ? self::WIDE : self::NARROW;
            }
        }
        return $widths;
    }

    /** SVG markup (safe to print as-is: only numbers and the escaped label). */
    public static function svg(string $text, int $height = 56, string $label = ''): string
    {
        $x = 10; // quiet zone
        $bars = '';
        foreach (self::modules($text) as $i => $width) {
            if ($i % 2 === 0) {
                $bars .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $x, $width, $height);
            }
            $x += $width;
        }
        $total = $x + 10;
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="lt-barcode" viewBox="0 0 %d %d" role="img" aria-label="%s"><g fill="#000">%s</g></svg>',
            $total,
            $height,
            htmlspecialchars($label !== '' ? $label : $text, ENT_QUOTES, 'UTF-8'),
            $bars,
        );
    }
}
