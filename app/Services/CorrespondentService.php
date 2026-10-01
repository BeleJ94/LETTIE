<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Page;
use App\Core\TableRequest;
use App\Core\Transaction;
use App\Domain\Audit\Actor;
use App\Domain\Auth\Permission;
use App\Domain\Correspondent\Correspondent;
use App\Domain\Correspondent\CorrespondentInput;
use App\Domain\NotFoundException;
use App\Repositories\CorrespondentRepository;

final class CorrespondentService
{
    public const ENTITY = 'correspondent';

    public function __construct(
        private readonly CorrespondentRepository $correspondents,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(Actor $actor, CorrespondentInput $input, ?int $siteId = null): Correspondent
    {
        $site = $siteId !== null && $actor->user->can(Permission::SitesAll) ? $siteId : $actor->user->siteId;

        return $this->transaction->run(function () use ($actor, $input, $site): Correspondent {
            $id = $this->correspondents->create($site, $input, $actor->user->id);
            $this->audit->created($actor, self::ENTITY, $id, $site, ['site_id' => $site] + $input->auditValues());
            return $this->correspondents->findById($id) ?? throw NotFoundException::of('Correspondent', $id);
        });
    }

    public function update(Actor $actor, int $id, CorrespondentInput $input): Correspondent
    {
        return $this->transaction->run(function () use ($actor, $id, $input): Correspondent {
            $current = $this->find($id);
            $this->correspondents->update($id, $input);
            $updated = $this->find($id);
            $this->audit->updated($actor, self::ENTITY, $id, $current->siteId, $current->auditValues(), $updated->auditValues());
            return $updated;
        });
    }

    public function find(int $id): Correspondent
    {
        return $this->correspondents->findById($id) ?? throw NotFoundException::of('Correspondent', $id);
    }

    public function findOrNull(int $id): ?Correspondent
    {
        return $this->correspondents->findById($id);
    }

    public function page(TableRequest $table, bool $includeInactive): Page
    {
        return $this->correspondents->page($table, $includeInactive);
    }

    /** @return list<string> */
    public static function sortKeys(): array
    {
        return array_keys(CorrespondentRepository::SORTABLE);
    }

    /** @return list<array{id: int, name: string, organization: ?string, city: ?string, site_id: int}> */
    public function search(string $term, int $limit = 10, ?int $siteId = null): array
    {
        return $this->correspondents->search($term, $limit, $siteId);
    }
}
