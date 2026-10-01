<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Paging/sorting/search parameters of a server-side table (see lt-tables.js):
 * ?page=1&per_page=25&sort=key&dir=asc|desc&q=text. The sort key is
 * accepted only if the repository lists it as sortable.
 */
final class TableRequest
{
    public const MAX_PER_PAGE = 100;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $sort,
        public readonly string $dir,
        public readonly ?string $search,
    ) {
    }

    /** @param list<string> $sortable */
    public static function fromRequest(Request $request, array $sortable, string $defaultSort, string $defaultDir = 'asc'): self
    {
        $page = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 25;
        $sort = $request->query('sort');
        $dir = strtolower((string) $request->query('dir', $defaultDir));
        $search = $request->query('q');
        $search = is_string($search) ? mb_substr(trim($search), 0, 100) : '';

        return new self(
            min($page, 100000),
            min($perPage, self::MAX_PER_PAGE),
            is_string($sort) && in_array($sort, $sortable, true) ? $sort : $defaultSort,
            $dir === 'desc' ? 'DESC' : ($dir === 'asc' ? 'ASC' : strtoupper($defaultDir)),
            $search === '' ? null : $search,
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** Search term escaped for LIKE '%…%' (with ESCAPE '\\'). */
    public function likePattern(): ?string
    {
        return $this->search === null ? null : '%' . addcslashes($this->search, '%_\\') . '%';
    }
}
