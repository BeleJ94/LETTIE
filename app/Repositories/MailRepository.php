<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Page;
use App\Core\TableRequest;
use App\Domain\Mail\Channel;
use App\Domain\Mail\Confidentiality;
use App\Domain\Mail\Direction;
use App\Domain\Mail\Mail;
use App\Domain\Mail\MailFilter;
use App\Domain\Mail\MailInput;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\Priority;
use DateTimeImmutable;

final class MailRepository extends Repository
{
    /** Table sort keys => SQL expressions (whitelist). */
    public const SORTABLE = [
        // Same order as the text (ENT < SOR), but numeric within a year.
        'reference' => ['m.sequence_year', 'm.direction', 'm.sequence_number'],
        'direction' => ['m.direction'],
        'subject' => ['m.subject'],
        'correspondent_name' => ['c.name'],
        'department_name' => ['d.name'],
        'mail_date' => ['m.mail_date'],
        'due_date' => ['m.due_date'],
        'priority' => ['m.priority'],
        'status' => ['m.status'],
    ];

    private const LIST_FROM = ' FROM mails m
        JOIN correspondents c ON c.id = m.correspondent_id
        LEFT JOIN departments d ON d.id = m.department_id';

    public function findById(int $id): ?Mail
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare('SELECT * FROM mails m WHERE m.id = :id AND ' . $this->scopeSql('m.site_id', $params));
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function create(
        int $siteId,
        Direction $direction,
        string $reference,
        int $year,
        int $number,
        MailInput $input,
        int $createdBy,
    ): int {
        $this->assertInScope($siteId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO mails (site_id, direction, reference, sequence_year, sequence_number, subject, summary,
                                correspondent_id, department_id, channel, priority, confidentiality, status,
                                document_date, received_at, sent_at, due_date, external_reference, created_by)
             VALUES (:site_id, :direction, :reference, :year, :number, :subject, :summary,
                     :correspondent_id, :department_id, :channel, :priority, :confidentiality, :status,
                     :document_date, :received_at, :sent_at, :due_date, :external_reference, :created_by)'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'direction' => $direction->value,
            'reference' => $reference,
            'year' => $year,
            'number' => $number,
            'status' => MailStatus::Registered->value,
            'created_by' => $createdBy,
        ] + self::inputParams($input));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, MailInput $input, MailStatus $status, ?DateTimeImmutable $closedAt, int $updatedBy): void
    {
        $params = ['id' => $id, 'status' => $status->value, 'closed_at' => self::sqlDateTime($closedAt), 'updated_by' => $updatedBy]
            + self::inputParams($input);
        $stmt = $this->pdo->prepare(
            'UPDATE mails SET subject = :subject, summary = :summary, correspondent_id = :correspondent_id,
                    department_id = :department_id, channel = :channel, priority = :priority,
                    confidentiality = :confidentiality, status = :status, document_date = :document_date,
                    received_at = :received_at, sent_at = :sent_at, due_date = :due_date,
                    external_reference = :external_reference, closed_at = :closed_at, updated_by = :updated_by
             WHERE id = :id AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
    }

    public function findByReference(int $siteId, string $reference): ?Mail
    {
        $params = ['site_id' => $siteId, 'reference' => $reference];
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mails m WHERE m.site_id = :site_id AND m.reference = :reference AND ' . $this->scopeSql('m.site_id', $params)
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function updateStatus(int $id, MailStatus $status, ?DateTimeImmutable $closedAt, ?int $updatedBy): void
    {
        $params = ['id' => $id, 'status' => $status->value, 'closed_at' => self::sqlDateTime($closedAt), 'updated_by' => $updatedBy];
        $stmt = $this->pdo->prepare(
            'UPDATE mails SET status = :status, closed_at = :closed_at, updated_by = :updated_by
             WHERE id = :id AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
    }

    /**
     * All rows matching the list filters and sort (Excel/PDF export), capped at $limit.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(MailFilter $filter, TableRequest $table, int $limit): array
    {
        return $this->rowsFor($this->pageIds($filter, $table, $limit, 0),
            'm.id, m.reference, m.direction, m.subject, m.confidentiality, c.name AS correspondent_name,
             d.name AS department_name, m.mail_date, m.channel, m.due_date, m.priority, m.status, m.external_reference');
    }

    /**
     * Mail register: one direction, registration order, mail dated in [from, toExclusive).
     *
     * @return list<array<string, mixed>>
     */
    public function register(Direction $direction, DateTimeImmutable $from, DateTimeImmutable $toExclusive, int $limit): array
    {
        $params = ['direction' => $direction->value, 'from' => self::sqlDateTime($from), 'to' => self::sqlDateTime($toExclusive)];
        $stmt = $this->pdo->prepare(
            'SELECT STRAIGHT_JOIN m.id, m.reference, m.received_at, m.sent_at, m.document_date, m.subject, m.confidentiality,
                    c.name AS correspondent_name, d.name AS department_name, m.channel, m.priority, m.status,
                    m.external_reference, m.closed_at'
            . self::LIST_FROM . '
             WHERE m.direction = :direction
               AND m.mail_date >= :from
               AND m.mail_date < :to
               AND ' . $this->scopeSql('m.site_id', $params) . '
             ORDER BY m.sequence_year, m.sequence_number LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Server-side table page (rows are plain arrays for JSON). */
    public function page(MailFilter $filter, TableRequest $table): Page
    {
        $totalParams = [];
        $totalStmt = $this->pdo->prepare('SELECT COUNT(*) FROM mails m WHERE ' . $this->scopeSql('m.site_id', $totalParams));
        $totalStmt->execute($totalParams);

        $params = [];
        $countStmt = $this->pdo->prepare('SELECT STRAIGHT_JOIN COUNT(*)' . $this->filterFrom($table) . $this->where($filter, $table, $params));
        $countStmt->execute($params);

        $rows = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['attachments_count'] = (int) $row['attachments_count'];
            return $row;
        }, $this->rowsFor($this->pageIds($filter, $table, $table->perPage, $table->offset()),
            'm.id, m.reference, m.direction, m.subject, c.name AS correspondent_name, d.name AS department_name, m.mail_date,
             m.due_date, m.priority, m.status, m.confidentiality,
             (SELECT COUNT(*) FROM attachments a WHERE a.mail_id = m.id) AS attachments_count'));

        return new Page($rows, (int) $totalStmt->fetchColumn(), (int) $countStmt->fetchColumn());
    }

    /**
     * Joins needed only to filter or sort: with none, MariaDB walks the mail_date index
     * instead of starting from correspondents and sorting every mail (measured: 2.3 s → ms on 100,000 mails).
     * Queries using it start with STRAIGHT_JOIN (MariaDB/MySQL): mails must drive the join, otherwise
     * the optimizer starts from the small correspondents table and sorts all mail (search: 1.3 s → 2 ms).
     */
    private function filterFrom(TableRequest $table): string
    {
        $from = ' FROM mails m';
        if ($table->search !== null || $table->sort === 'correspondent_name') {
            $from .= ' JOIN correspondents c ON c.id = m.correspondent_id';
        }
        if ($table->sort === 'department_name') {
            $from .= ' LEFT JOIN departments d ON d.id = m.department_id';
        }
        return $from;
    }

    /**
     * Step 1 of a deferred join: ids of the requested page only.
     *
     * @return list<int>
     */
    private function pageIds(MailFilter $filter, TableRequest $table, int $limit, int $offset): array
    {
        $params = [];
        $stmt = $this->pdo->prepare(
            'SELECT STRAIGHT_JOIN m.id' . $this->filterFrom($table) . $this->where($filter, $table, $params)
            . self::orderBy(self::SORTABLE, $table->sort, $table->dir, 'm.id DESC')
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Step 2: full rows for those ids, returned in the same order.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function rowsFor(array $ids, string $columns): array
    {
        if ($ids === []) {
            return [];
        }
        $params = [];
        $in = [];
        foreach ($ids as $i => $id) {
            $in[] = ':id' . $i;
            $params['id' . $i] = $id;
        }
        // Ids come from a scoped query; the scope is applied again as a safeguard.
        $stmt = $this->pdo->prepare('SELECT ' . $columns . self::LIST_FROM . ' WHERE m.id IN (' . implode(', ', $in) . ') AND ' . $this->scopeSql('m.site_id', $params));
        $stmt->execute($params);
        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['id']] = $row;
        }
        return array_values(array_filter(array_map(static fn (int $id): ?array => $byId[$id] ?? null, $ids)));
    }

    /** @param array<string, mixed> $params */
    private function where(MailFilter $filter, TableRequest $table, array &$params): string
    {
        $conditions = [$this->scopeSql('m.site_id', $params)];

        if ($filter->direction !== null) {
            $conditions[] = 'm.direction = :f_direction';
            $params['f_direction'] = $filter->direction->value;
        }
        if ($filter->status !== null) {
            $conditions[] = 'm.status = :f_status';
            $params['f_status'] = $filter->status->value;
        }
        if ($filter->priority !== null) {
            $conditions[] = 'm.priority = :f_priority';
            $params['f_priority'] = $filter->priority->value;
        }
        if ($filter->departmentId !== null) {
            $conditions[] = 'm.department_id = :f_department';
            $params['f_department'] = $filter->departmentId;
        }
        if ($filter->correspondentId !== null) {
            $conditions[] = 'm.correspondent_id = :f_correspondent';
            $params['f_correspondent'] = $filter->correspondentId;
        }
        if ($filter->from !== null) {
            $conditions[] = 'm.mail_date >= :f_from';
            $params['f_from'] = self::sqlDateTime($filter->from);
        }
        if ($filter->toExclusive !== null) {
            $conditions[] = 'm.mail_date < :f_to';
            $params['f_to'] = self::sqlDateTime($filter->toExclusive);
        }
        if ($filter->overdueBefore !== null) {
            $finished = [];
            foreach (MailStatus::finishedValues() as $i => $value) {
                $finished[] = ':f_finished_' . $i;
                $params['f_finished_' . $i] = $value;
            }
            $conditions[] = 'm.due_date < :f_overdue AND m.status NOT IN (' . implode(', ', $finished) . ')';
            $params['f_overdue'] = $filter->overdueBefore;
        }

        if ($filter->assignedToUserIds !== null) {
            $ids = [];
            foreach (array_values($filter->assignedToUserIds) as $i => $userId) {
                $ids[] = ':f_assignee_' . $i;
                $params['f_assignee_' . $i] = $userId;
            }
            $conditions[] = $ids === []
                ? '1 = 0'
                : "EXISTS (SELECT 1 FROM assignments a WHERE a.mail_id = m.id AND a.status = 'active' AND a.user_id IN (" . implode(', ', $ids) . '))';
        }

        if ($filter->ids !== null) {
            $ids = [];
            foreach (array_values($filter->ids) as $i => $mailId) {
                $ids[] = ':f_id_' . $i;
                $params['f_id_' . $i] = $mailId;
            }
            $conditions[] = $ids === [] ? '1 = 0' : 'm.id IN (' . implode(', ', $ids) . ')';
        }

        $like = $table->likePattern();
        if ($like !== null) {
            $fields = ['m.reference', 'm.subject', 'c.name', 'm.external_reference'];
            $parts = [];
            foreach ($fields as $i => $field) {
                $parts[] = "{$field} LIKE :q{$i} ESCAPE '\\\\'";
                $params['q' . $i] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }

        return ' WHERE ' . implode(' AND ', $conditions);
    }

    /** @return array<string, mixed> */
    private static function inputParams(MailInput $input): array
    {
        return [
            'subject' => $input->subject,
            'summary' => $input->summary,
            'correspondent_id' => $input->correspondentId,
            'department_id' => $input->departmentId,
            'channel' => $input->channel->value,
            'priority' => $input->priority->value,
            'confidentiality' => $input->confidentiality->value,
            'document_date' => $input->documentDate,
            'received_at' => self::sqlDateTime($input->receivedAt),
            'sent_at' => self::sqlDateTime($input->sentAt),
            'due_date' => $input->dueDate,
            'external_reference' => $input->externalReference,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Mail
    {
        return new Mail(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            direction: Direction::from((string) $row['direction']),
            reference: (string) $row['reference'],
            sequenceYear: (int) $row['sequence_year'],
            sequenceNumber: (int) $row['sequence_number'],
            subject: (string) $row['subject'],
            summary: $row['summary'] !== null ? (string) $row['summary'] : null,
            correspondentId: (int) $row['correspondent_id'],
            departmentId: $row['department_id'] !== null ? (int) $row['department_id'] : null,
            channel: Channel::from((string) $row['channel']),
            priority: Priority::from((string) $row['priority']),
            confidentiality: Confidentiality::from((string) $row['confidentiality']),
            status: MailStatus::from((string) $row['status']),
            documentDate: $row['document_date'] !== null ? (string) $row['document_date'] : null,
            receivedAt: self::utc($row['received_at']),
            sentAt: self::utc($row['sent_at']),
            dueDate: $row['due_date'] !== null ? (string) $row['due_date'] : null,
            externalReference: $row['external_reference'] !== null ? (string) $row['external_reference'] : null,
            closedAt: self::utc($row['closed_at']),
            createdBy: (int) $row['created_by'],
            updatedBy: $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            createdAt: self::utc($row['created_at']) ?? new DateTimeImmutable('@0'),
            updatedAt: self::utc($row['updated_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
