<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\OutOfScopeException;
use App\Domain\SiteScope;
use PDO;

/**
 * Base class of every repository: the SiteScope is mandatory.
 * Reads on site-bound tables add scopeSql(); writes call assertInScope()
 * or add scopeSql() to their WHERE clause.
 */
abstract class Repository
{
    protected const DATETIME_FORMAT = 'Y-m-d H:i:s.u';

    private SiteScope $scope;

    private int $scopeParamCounter = 0;

    public function __construct(protected readonly PDO $pdo, SiteScope $scope)
    {
        $this->scope = $scope;
    }

    final public function withScope(SiteScope $scope): static
    {
        $clone = clone $this;
        $clone->scope = $scope;
        return $clone;
    }

    final protected function scope(): SiteScope
    {
        return $this->scope;
    }

    /**
     * SQL condition restricting $column (e.g. "u.site_id") to the scope.
     *
     * @param array<string, mixed> $params receives the bound values
     */
    final protected function scopeSql(string $column, array &$params): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $column)) {
            throw new \InvalidArgumentException("Invalid scope column: {$column}");
        }
        if ($this->scope->isUnrestricted()) {
            return '1 = 1';
        }
        if ($this->scope->isEmpty()) {
            return '1 = 0';
        }

        $placeholders = [];
        foreach ($this->scope->siteIds() as $siteId) {
            $name = 'scope_site_' . $this->scopeParamCounter++;
            $placeholders[] = ':' . $name;
            $params[$name] = $siteId;
        }
        return $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    /** @throws OutOfScopeException */
    final protected function assertInScope(int $siteId): void
    {
        if (!$this->scope->allows($siteId)) {
            throw OutOfScopeException::forSite($siteId);
        }
    }

    /** DATETIME columns hold UTC. */
    final protected static function utc(mixed $value): ?\DateTimeImmutable
    {
        return $value === null || $value === ''
            ? null
            : new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }

    final protected static function sqlDateTime(?\DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * ORDER BY clause from a whitelist: key => list of SQL expressions.
     *
     * @param array<string, list<string>> $sortable
     */
    final protected static function orderBy(array $sortable, string $key, string $dir, string $tieBreaker): string
    {
        $dir = $dir === 'DESC' ? 'DESC' : 'ASC';
        $parts = array_map(static fn (string $expr): string => "{$expr} {$dir}", $sortable[$key] ?? []);
        $parts[] = $tieBreaker;
        return ' ORDER BY ' . implode(', ', $parts);
    }

    /** @throws OutOfScopeException */
    final protected function assertUnrestricted(): void
    {
        if (!$this->scope->isUnrestricted()) {
            throw OutOfScopeException::forSite(null);
        }
    }
}
