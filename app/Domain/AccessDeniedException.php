<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** The actor sees the item but may not perform this action on it (HTTP 403). */
final class AccessDeniedException extends RuntimeException
{
}
