<?php

declare(strict_types=1);

namespace App\Domain\Assignment;

enum AssignmentStatus: string
{
    case Active = 'active';
    /** The mail was answered or closed. */
    case Completed = 'completed';
    /** Replaced by a reassignment. */
    case Reassigned = 'reassigned';
    case Cancelled = 'cancelled';
}
