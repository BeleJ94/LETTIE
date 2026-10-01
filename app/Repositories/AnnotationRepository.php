<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Annotation\Annotation;
use DateTimeImmutable;

final class AnnotationRepository extends Repository
{
    public function create(int $mailId, int $siteId, int $userId, string $body, bool $isPrivate, DateTimeImmutable $at): int
    {
        $this->assertInScope($siteId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO annotations (mail_id, user_id, body, is_private, created_at)
             SELECT m.id, :user_id, :body, :private, :at FROM mails m WHERE m.id = :mail_id AND m.site_id = :site_id'
        );
        $stmt->execute([
            'mail_id' => $mailId,
            'site_id' => $siteId,
            'user_id' => $userId,
            'body' => $body,
            'private' => $isPrivate ? 1 : 0,
            'at' => self::sqlDateTime($at),
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("Mail {$mailId} not found on site {$siteId}.");
        }
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Annotations $viewerId may read: shared ones and their own private ones. Oldest first.
     *
     * @return list<Annotation>
     */
    public function listVisible(int $mailId, int $viewerId): array
    {
        $params = ['mail_id' => $mailId, 'viewer' => $viewerId];
        $stmt = $this->pdo->prepare(
            "SELECT n.*, CONCAT(u.first_name, ' ', u.last_name) AS user_name
             FROM annotations n
             JOIN mails m ON m.id = n.mail_id
             JOIN users u ON u.id = n.user_id
             WHERE n.mail_id = :mail_id AND (n.is_private = 0 OR n.user_id = :viewer)
               AND " . $this->scopeSql('m.site_id', $params) . '
             ORDER BY n.created_at, n.id'
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): Annotation => new Annotation(
            id: (int) $r['id'],
            mailId: (int) $r['mail_id'],
            userId: (int) $r['user_id'],
            userName: (string) $r['user_name'],
            body: (string) $r['body'],
            isPrivate: (bool) $r['is_private'],
            createdAt: self::utc($r['created_at']) ?? new DateTimeImmutable('@0'),
        ), $stmt->fetchAll());
    }
}
