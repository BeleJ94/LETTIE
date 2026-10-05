<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use DomainException;

/** The password is right, and the account asks for the code of its authenticator application. */
final class SecondFactorRequiredException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Second factor required.');
    }
}
