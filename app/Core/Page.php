<?php

declare(strict_types=1);

namespace App\Core;

/** One page of a server-side table, in the JSON envelope expected by lt-tables.js. */
final class Page
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $filtered,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{total: int, filtered: int}} */
    public function toArray(): array
    {
        return ['data' => $this->rows, 'meta' => ['total' => $this->total, 'filtered' => $this->filtered]];
    }
}
