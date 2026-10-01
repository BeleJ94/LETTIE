<?php

declare(strict_types=1);

namespace App\Domain\Mail;

enum Channel: string
{
    case Postal = 'postal';
    case Email = 'email';
    case HandDelivered = 'hand_delivered';
    case Fax = 'fax';
    case Registered = 'registered';
    case Other = 'other';
}
