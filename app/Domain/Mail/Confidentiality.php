<?php

declare(strict_types=1);

namespace App\Domain\Mail;

enum Confidentiality: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Secret = 'secret';

    /** Documents leaving the application (register, exports, slip) do not show the subject of secret mail. */
    public function masksSubjectInDocuments(): bool
    {
        return $this === self::Secret;
    }
}
