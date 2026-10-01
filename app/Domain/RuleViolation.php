<?php

declare(strict_types=1);

namespace App\Domain;

use DomainException;

/**
 * Business rule failure, per field. Messages are translation keys with
 * parameters: the Domain never produces user-facing text.
 */
final class RuleViolation extends DomainException
{
    /** @param array<string, list<array{0: string, 1: array<string, string|int>}>> $violations */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('Business rule violated: ' . implode(', ', array_keys($violations)));
    }

    /** @param array<string, string|int> $params */
    public static function single(string $field, string $key, array $params = []): self
    {
        return new self([$field => [[$key, $params]]]);
    }

    /** @return array<string, list<array{0: string, 1: array<string, string|int>}>> */
    public function violations(): array
    {
        return $this->violations;
    }
}
