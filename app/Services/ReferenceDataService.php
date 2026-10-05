<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DepartmentRepository;
use App\Repositories\SiteRepository;

/** Lists used by forms and filters (scoped to the user's sites). */
final class ReferenceDataService
{
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly SiteRepository $sites,
    ) {
    }

    public function siteName(int $id): ?string
    {
        return $this->sites->findName($id);
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
