<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DepartmentRepository;

/** Lists used by forms and filters (scoped to the user's sites). */
final class ReferenceDataService
{
    public function __construct(private readonly DepartmentRepository $departments)
    {
    }

    /** @return list<array{id: int, site_id: int, name: string}> */
    public function departments(): array
    {
        return $this->departments->listActive();
    }

    public function departmentName(int $id): ?string
    {
        return $this->departments->findName($id);
    }
}
