<?php

declare(strict_types=1);

namespace App\Domain\Correspondent;

enum CorrespondentType: string
{
    case Person = 'person';
    case Organization = 'organization';
}
