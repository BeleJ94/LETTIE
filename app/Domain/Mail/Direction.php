<?php

declare(strict_types=1);

namespace App\Domain\Mail;

enum Direction: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';

    /** Reference prefix: ENT-2026-00001 / SOR-2026-00001. */
    public function prefix(): string
    {
        return match ($this) {
            self::Incoming => 'ENT',
            self::Outgoing => 'SOR',
        };
    }
}
