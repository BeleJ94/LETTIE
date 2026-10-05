<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Transaction;
use App\Domain\Audit\Actor;
use App\Domain\NotFoundException;
use App\Domain\Organization\OrganizationRules;
use App\Repositories\DepartmentRepository;
use App\Repositories\SiteRepository;

/**
 * Sites and departments (permission settings.manage). Nothing is deleted: mail, users and the
 * activity log refer to them; a site or a department is deactivated instead.
 */
final class OrganizationService
{
    public const SITE = 'site';
    public const DEPARTMENT = 'department';

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly DepartmentRepository $departments,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {
    }

    /** @return list<array{id: int, code: string, name: string, is_active: bool, users_count: int}> */
    public function sites(): array
    {
        return $this->sites->rows();
    }

    /** @return list<array{id: int, site_id: int, site_name: string, code: string, name: string, is_active: bool, users_count: int}> */
    public function departments(): array
    {
        return $this->departments->rows();
    }

    /** @return int id of the new site */
    public function createSite(Actor $actor, string $code, string $name): int
    {
        $code = OrganizationRules::normalizeCode($code);
        $name = trim($name);
        return $this->transaction->run(function () use ($actor, $code, $name): int {
            OrganizationRules::assertValid($code, $name, $this->sites->codeTaken($code));
            $id = $this->sites->create($code, $name);
            $this->audit->created($actor, self::SITE, $id, $id, ['code' => $code, 'name' => $name, 'is_active' => true]);
            return $id;
        });
    }

    public function updateSite(Actor $actor, int $id, string $code, string $name): void
    {
        $code = OrganizationRules::normalizeCode($code);
        $name = trim($name);
        $this->transaction->run(function () use ($actor, $id, $code, $name): void {
            $site = $this->sites->find($id) ?? throw NotFoundException::of('Site', $id);
            OrganizationRules::assertValid($code, $name, $this->sites->codeTaken($code, $id));
            $this->sites->update($id, $code, $name);
            $this->audit->updated($actor, self::SITE, $id, $id, ['code' => $site['code'], 'name' => $site['name']], ['code' => $code, 'name' => $name]);
        });
    }

    public function setSiteActive(Actor $actor, int $id, bool $active): void
    {
        $this->transaction->run(function () use ($actor, $id, $active): void {
            $site = $this->sites->find($id) ?? throw NotFoundException::of('Site', $id);
            if (!$active) {
                OrganizationRules::assertCanDeactivate($site['users_count'], $id === $actor->user->siteId);
            }
            $this->sites->setActive($id, $active);
            $this->audit->updated($actor, self::SITE, $id, $id, ['is_active' => $site['is_active']], ['is_active' => $active]);
        });
    }

    /** @return int id of the new department */
    public function createDepartment(Actor $actor, int $siteId, string $code, string $name): int
    {
        $code = OrganizationRules::normalizeCode($code);
        $name = trim($name);
        return $this->transaction->run(function () use ($actor, $siteId, $code, $name): int {
            if ($this->sites->find($siteId) === null) {
                throw NotFoundException::of('Site', $siteId);
            }
            OrganizationRules::assertValid($code, $name, $this->departments->codeTaken($siteId, $code));
            $id = $this->departments->create($siteId, $code, $name);
            $this->audit->created($actor, self::DEPARTMENT, $id, $siteId, ['site_id' => $siteId, 'code' => $code, 'name' => $name, 'is_active' => true]);
            return $id;
        });
    }

    public function updateDepartment(Actor $actor, int $id, string $code, string $name): void
    {
        $code = OrganizationRules::normalizeCode($code);
        $name = trim($name);
        $this->transaction->run(function () use ($actor, $id, $code, $name): void {
            $department = $this->departments->find($id) ?? throw NotFoundException::of('Department', $id);
            OrganizationRules::assertValid($code, $name, $this->departments->codeTaken($department['site_id'], $code, $id));
            $this->departments->update($id, $code, $name);
            $this->audit->updated($actor, self::DEPARTMENT, $id, $department['site_id'], ['code' => $department['code'], 'name' => $department['name']], ['code' => $code, 'name' => $name]);
        });
    }

    public function setDepartmentActive(Actor $actor, int $id, bool $active): void
    {
        $this->transaction->run(function () use ($actor, $id, $active): void {
            $department = $this->departments->find($id) ?? throw NotFoundException::of('Department', $id);
            if (!$active) {
                OrganizationRules::assertCanDeactivate($department['users_count']);
            }
            $this->departments->setActive($id, $active);
            $this->audit->updated($actor, self::DEPARTMENT, $id, $department['site_id'], ['is_active' => $department['is_active']], ['is_active' => $active]);
        });
    }
}
