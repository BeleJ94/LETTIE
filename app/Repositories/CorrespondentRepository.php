<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Page;
use App\Core\TableRequest;
use App\Domain\Correspondent\Correspondent;
use App\Domain\Correspondent\CorrespondentInput;
use App\Domain\Correspondent\CorrespondentType;

final class CorrespondentRepository extends Repository
{
    public const SORTABLE = [
        'name' => ['c.name'],
        'type' => ['c.type'],
        'organization' => ['c.organization'],
        'city' => ['c.city'],
        'email' => ['c.email'],
        'is_active' => ['c.is_active'],
        'mails_count' => ['mails_count'],
    ];

    public function findById(int $id): ?Correspondent
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare('SELECT * FROM correspondents c WHERE c.id = :id AND ' . $this->scopeSql('c.site_id', $params));
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function create(int $siteId, CorrespondentInput $input, int $createdBy): int
    {
        $this->assertInScope($siteId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO correspondents (site_id, type, name, organization, email, phone, address_line1, address_line2,
                                         postal_code, city, country, notes, is_active, created_by)
             VALUES (:site_id, :type, :name, :organization, :email, :phone, :address_line1, :address_line2,
                     :postal_code, :city, :country, :notes, :is_active, :created_by)'
        );
        $stmt->execute(['site_id' => $siteId, 'created_by' => $createdBy] + self::inputParams($input));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, CorrespondentInput $input): void
    {
        $params = ['id' => $id] + self::inputParams($input);
        $stmt = $this->pdo->prepare(
            'UPDATE correspondents SET type = :type, name = :name, organization = :organization, email = :email,
                    phone = :phone, address_line1 = :address_line1, address_line2 = :address_line2,
                    postal_code = :postal_code, city = :city, country = :country, notes = :notes, is_active = :is_active
             WHERE id = :id AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
    }

    /**
     * Autocomplete: active correspondents whose name or organization contains $term.
     *
     * @return list<array{id: int, name: string, organization: ?string, city: ?string, site_id: int}>
     */
    public function search(string $term, int $limit = 10, ?int $siteId = null): array
    {
        $like = '%' . addcslashes(mb_substr(trim($term), 0, 100), '%_\\') . '%';
        $params = ['q1' => $like, 'q2' => $like];
        $sql = 'SELECT c.id, c.name, c.organization, c.city, c.site_id FROM correspondents c
                WHERE c.is_active = 1 AND (c.name LIKE :q1 ESCAPE \'\\\\\' OR c.organization LIKE :q2 ESCAPE \'\\\\\')
                  AND ' . $this->scopeSql('c.site_id', $params);
        if ($siteId !== null) {
            $sql .= ' AND c.site_id = :site_id';
            $params['site_id'] = $siteId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY c.name LIMIT ' . max(1, min($limit, 50)));
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'organization' => $r['organization'],
            'city' => $r['city'],
            'site_id' => (int) $r['site_id'],
        ], $stmt->fetchAll());
    }

    public function page(TableRequest $table, bool $includeInactive = true): Page
    {
        $totalParams = [];
        $total = $this->pdo->prepare('SELECT COUNT(*) FROM correspondents c WHERE ' . $this->scopeSql('c.site_id', $totalParams));
        $total->execute($totalParams);

        $params = [];
        $conditions = [$this->scopeSql('c.site_id', $params)];
        if (!$includeInactive) {
            $conditions[] = 'c.is_active = 1';
        }
        $like = $table->likePattern();
        if ($like !== null) {
            $parts = [];
            foreach (['c.name', 'c.organization', 'c.email', 'c.city'] as $i => $field) {
                $parts[] = "{$field} LIKE :q{$i} ESCAPE '\\\\'";
                $params['q' . $i] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        $where = ' WHERE ' . implode(' AND ', $conditions);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM correspondents c' . $where);
        $count->execute($params);

        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.type, c.name, c.organization, c.email, c.phone, c.city, c.is_active,
                    (SELECT COUNT(*) FROM mails m WHERE m.correspondent_id = c.id) AS mails_count
             FROM correspondents c' . $where
            . self::orderBy(self::SORTABLE, $table->sort, $table->dir, 'c.id')
            . ' LIMIT ' . $table->perPage . ' OFFSET ' . $table->offset()
        );
        $stmt->execute($params);
        $rows = array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['is_active'] = (bool) $r['is_active'];
            $r['mails_count'] = (int) $r['mails_count'];
            return $r;
        }, $stmt->fetchAll());

        return new Page($rows, (int) $total->fetchColumn(), (int) $count->fetchColumn());
    }

    /** @return array<string, mixed> */
    private static function inputParams(CorrespondentInput $input): array
    {
        return [
            'type' => $input->type->value,
            'name' => $input->name,
            'organization' => $input->organization,
            'email' => $input->email,
            'phone' => $input->phone,
            'address_line1' => $input->addressLine1,
            'address_line2' => $input->addressLine2,
            'postal_code' => $input->postalCode,
            'city' => $input->city,
            'country' => $input->country,
            'notes' => $input->notes,
            'is_active' => $input->isActive ? 1 : 0,
        ];
    }

    /** @param array<string, mixed> $r */
    private static function hydrate(array $r): Correspondent
    {
        $str = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        return new Correspondent(
            id: (int) $r['id'],
            siteId: (int) $r['site_id'],
            type: CorrespondentType::from((string) $r['type']),
            name: (string) $r['name'],
            organization: $str($r['organization']),
            email: $str($r['email']),
            phone: $str($r['phone']),
            addressLine1: $str($r['address_line1']),
            addressLine2: $str($r['address_line2']),
            postalCode: $str($r['postal_code']),
            city: $str($r['city']),
            country: (string) $r['country'],
            notes: $str($r['notes']),
            isActive: (bool) $r['is_active'],
        );
    }
}
