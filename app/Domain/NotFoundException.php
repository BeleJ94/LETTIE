<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** The entity does not exist or is outside the actor's site scope (same answer on purpose). */
final class NotFoundException extends RuntimeException
{
    public static function of(string $entity, int $id): self
    {
        return new self("{$entity} #{$id} not found.");
    }
}
