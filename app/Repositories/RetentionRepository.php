<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Mail\Direction;
use App\Domain\Retention\RetentionAction;
use App\Domain\Retention\RetentionRule;
use DateTimeImmutable;

/** Retention rules and the mail they apply to. */
final class RetentionRepository extends Repository
{
    /** @return list<RetentionRule> rules visible in the scope (global rules included) */
    public function rules(bool $activeOnly = false): array
    {
        $params = [];
        $stmt = $this->pdo->prepare(
            'SELECT * FROM retention_rules r WHERE (r.site_id IS NULL OR ' . $this->scopeSql('r.site_id', $params) . ')'
            . ($activeOnly ? ' AND r.is_active = 1' : '') . ' ORDER BY r.action, r.site_id, r.direction, r.retention_months, r.id'
        );
        $stmt->execute($params);
        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function findRule(int $id): ?RetentionRule
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare(
            'SELECT * FROM retention_rules r WHERE r.id = :id AND (r.site_id IS NULL OR ' . $this->scopeSql('r.site_id', $params) . ')'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function createRule(?int $siteId, string $name, ?Direction $direction, int $months, RetentionAction $action, int $createdBy): int
    {
        if ($siteId === null) {
            $this->assertUnrestricted();
        } else {
            $this->assertInScope($siteId);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO retention_rules (site_id, name, direction, retention_months, action, created_by)
             VALUES (:site_id, :name, :direction, :months, :action, :created_by)'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'name' => $name,
            'direction' => $direction?->value,
            'months' => $months,
            'action' => $action->value,
            'created_by' => $createdBy,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setActive(RetentionRule $rule, bool $active): void
    {
        if ($rule->siteId === null) {
            $this->assertUnrestricted();
        } else {
            $this->assertInScope($rule->siteId);
        }
        $stmt = $this->pdo->prepare('UPDATE retention_rules SET is_active = :active WHERE id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $rule->id]);
    }

    /**
     * Mail a rule may apply to, closed on or before $cutoff.
     * archive: closed mail; purge_attachments: archived mail with files left; review: closed or archived.
     *
     * @return list<array{id: int, site_id: int, direction: Direction, reference: string, subject: string, status: string}>
     */
    public function candidates(RetentionRule $rule, DateTimeImmutable $cutoff, int $limit): array
    {
        $params = ['cutoff' => self::sqlDateTime($cutoff)];
        $conditions = ['m.closed_at IS NOT NULL', 'm.closed_at <= :cutoff', $this->scopeSql('m.site_id', $params)];
        $conditions[] = match ($rule->action) {
            RetentionAction::Archive => "m.status = 'closed'",
            RetentionAction::PurgeAttachments => "m.status = 'archived' AND EXISTS (SELECT 1 FROM attachments a WHERE a.mail_id = m.id AND a.purged_at IS NULL)",
            RetentionAction::Review => "m.status IN ('closed', 'archived')",
        };
        if ($rule->siteId !== null) {
            $conditions[] = 'm.site_id = :rule_site';
            $params['rule_site'] = $rule->siteId;
        }
        if ($rule->direction !== null) {
            $conditions[] = 'm.direction = :rule_direction';
            $params['rule_direction'] = $rule->direction->value;
        }
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.site_id, m.direction, m.reference, m.subject, m.status FROM mails m WHERE '
            . implode(' AND ', $conditions) . ' ORDER BY m.closed_at, m.id LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'site_id' => (int) $r['site_id'],
            'direction' => Direction::from((string) $r['direction']),
            'reference' => (string) $r['reference'],
            'subject' => (string) $r['subject'],
            'status' => (string) $r['status'],
        ], $stmt->fetchAll());
    }

    /** @return list<array{id: int, stored_path: string, original_name: string, sha256: string}> */
    public function unpurgedAttachments(int $mailId): array
    {
        $params = ['mail_id' => $mailId];
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.stored_path, a.original_name, a.sha256 FROM attachments a JOIN mails m ON m.id = a.mail_id
             WHERE a.mail_id = :mail_id AND a.purged_at IS NULL AND ' . $this->scopeSql('m.site_id', $params) . ' ORDER BY a.id'
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'stored_path' => (string) $r['stored_path'],
            'original_name' => (string) $r['original_name'],
            'sha256' => (string) $r['sha256'],
        ], $stmt->fetchAll());
    }

    public function markPurged(int $attachmentId, int $ruleId, DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE attachments SET purged_at = :at, purged_by_rule_id = :rule WHERE id = :id AND purged_at IS NULL');
        $stmt->execute(['at' => self::sqlDateTime($at), 'rule' => $ruleId, 'id' => $attachmentId]);
    }

    /** @return list<int> active administrators (they see every site) */
    public function administrators(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND r.code = 'admin' ORDER BY u.id"
        );
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $r */
    private static function hydrate(array $r): RetentionRule
    {
        return new RetentionRule(
            id: (int) $r['id'],
            siteId: $r['site_id'] !== null ? (int) $r['site_id'] : null,
            name: (string) $r['name'],
            direction: $r['direction'] !== null ? Direction::from((string) $r['direction']) : null,
            retentionMonths: (int) $r['retention_months'],
            action: RetentionAction::from((string) $r['action']),
            isActive: (bool) $r['is_active'],
        );
    }
}
