<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use RuntimeException;

/** Deliberately generic: never reveals whether the email exists. */
final class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid credentials.');
    }
}
