<?php

declare(strict_types=1);

namespace App\Domain\Mail;

enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
