<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

final class OutOfScopeException extends RuntimeException
{
    public static function forSite(?int $siteId): self
    {
        return new self($siteId === null
            ? 'This operation requires an unrestricted site scope.'
            : "Site {$siteId} is outside the current site scope.");
    }
}
