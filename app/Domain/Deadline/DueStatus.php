<?php

declare(strict_types=1);

namespace App\Domain\Deadline;

enum DueStatus: string
{
    /** No due date, or the mail is finished. */
    case None = 'none';
    case Ok = 'ok';
    case Soon = 'soon';
    case Today = 'today';
    case Overdue = 'overdue';
}
