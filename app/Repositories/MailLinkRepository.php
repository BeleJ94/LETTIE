<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Mail\LinkType;
use DateTimeImmutable;

final class MailLinkRepository extends Repository
{
    public function create(int $sourceId, int $targetId, LinkType $type, int $siteId, int $createdBy, DateTimeImmutable $at): int
    {
        $this->assertInScope($siteId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO mail_links (source_mail_id, target_mail_id, type, created_by, created_at)
             SELECT s.id, t.id, :type, :created_by, :at FROM mails s JOIN mails t ON t.id = :target
             WHERE s.id = :source AND s.site_id = :site1 AND t.site_id = :site2'
        );
        $stmt->execute([
            'source' => $sourceId,
            'target' => $targetId,
            'type' => $type->value,
            'created_by' => $createdBy,
            'at' => self::sqlDateTime($at),
            'site1' => $siteId,
            'site2' => $siteId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Linked mails must exist on the same site.');
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function exists(int $sourceId, int $targetId, LinkType $type): bool
    {
        $params = ['source' => $sourceId, 'target' => $targetId, 'type' => $type->value];
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM mail_links l JOIN mails s ON s.id = l.source_mail_id
             WHERE l.source_mail_id = :source AND l.target_mail_id = :target AND l.type = :type AND '
            . $this->scopeSql('s.site_id', $params)
        );
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Links of a mail in both directions, with the other mail's summary.
     * "outbound": this mail is the source (e.g. it answers the other one).
     *
     * @return list<array{link_id: int, type: string, side: string, mail_id: int, reference: string, subject: string, status: string, direction: string}>
     */
    public function linksFor(int $mailId): array
    {
        $params = ['m1' => $mailId, 'm2' => $mailId];
        $scopeA = $this->scopeSql('o.site_id', $params);
        $scopeB = $this->scopeSql('o.site_id', $params);
        $stmt = $this->pdo->prepare(
            "SELECT l.id AS link_id, l.type, 'outbound' AS side, o.id AS mail_id, o.reference, o.subject, o.status, o.direction
               FROM mail_links l JOIN mails o ON o.id = l.target_mail_id
              WHERE l.source_mail_id = :m1 AND {$scopeA}
             UNION ALL
             SELECT l.id, l.type, 'inbound', o.id, o.reference, o.subject, o.status, o.direction
               FROM mail_links l JOIN mails o ON o.id = l.source_mail_id
              WHERE l.target_mail_id = :m2 AND {$scopeB}
             ORDER BY link_id"
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'link_id' => (int) $r['link_id'],
            'type' => (string) $r['type'],
            'side' => (string) $r['side'],
            'mail_id' => (int) $r['mail_id'],
            'reference' => (string) $r['reference'],
            'subject' => (string) $r['subject'],
            'status' => (string) $r['status'],
            'direction' => (string) $r['direction'],
        ], $stmt->fetchAll());
    }
}
