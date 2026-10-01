<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'Unauthorized');
    }

    public static function forbidden(): self
    {
        return new self(403, 'Forbidden');
    }

    public static function notFound(): self
    {
        return new self(404, 'Not Found');
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'Method Not Allowed', ['Allow' => implode(', ', $allowed)]);
    }

    public static function csrfMismatch(): self
    {
        return new self(419, 'CSRF token mismatch');
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
