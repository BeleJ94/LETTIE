<?php

declare(strict_types=1);

namespace App\Domain\Assignment;

enum AssignmentRole: string
{
    /** The person/department responsible for handling the mail (one at a time). */
    case ForAction = 'for_action';
    /** Copy for information (any number). */
    case ForInformation = 'for_information';
}
