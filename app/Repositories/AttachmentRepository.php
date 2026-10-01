<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Attachment\Attachment;
use DateTimeImmutable;

/** Attachments inherit the site of their mail (filtered through the join). */
final class AttachmentRepository extends Repository
{
    private const SELECT = 'SELECT a.*, m.site_id FROM attachments a JOIN mails m ON m.id = a.mail_id';

    public function create(
        int $mailId,
        int $siteId,
        string $originalName,
        string $storedPath,
        string $mimeType,
        int $sizeBytes,
        string $sha256,
        int $uploadedBy,
    ): int {
        $this->assertInScope($siteId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO attachments (mail_id, original_name, stored_path, mime_type, size_bytes, sha256, uploaded_by)
             SELECT m.id, :name, :path, :mime, :size, :sha, :user FROM mails m WHERE m.id = :mail_id AND m.site_id = :site_id'
        );
        $stmt->execute([
            'mail_id' => $mailId,
            'site_id' => $siteId,
            'name' => $originalName,
            'path' => $storedPath,
            'mime' => $mimeType,
            'size' => $sizeBytes,
            'sha' => $sha256,
            'user' => $uploadedBy,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("Mail {$mailId} not found on site {$siteId}.");
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Attachment
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE a.id = :id AND ' . $this->scopeSql('m.site_id', $params));
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return list<Attachment> */
    public function listForMail(int $mailId): array
    {
        $params = ['mail_id' => $mailId];
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE a.mail_id = :mail_id AND ' . $this->scopeSql('m.site_id', $params) . ' ORDER BY a.created_at, a.id'
        );
        $stmt->execute($params);
        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /** @param array<string, mixed> $r */
    private static function hydrate(array $r): Attachment
    {
        return new Attachment(
            id: (int) $r['id'],
            mailId: (int) $r['mail_id'],
            siteId: (int) $r['site_id'],
            originalName: (string) $r['original_name'],
            storedPath: (string) $r['stored_path'],
            mimeType: (string) $r['mime_type'],
            sizeBytes: (int) $r['size_bytes'],
            sha256: (string) $r['sha256'],
            uploadedBy: (int) $r['uploaded_by'],
            createdAt: self::utc($r['created_at']) ?? new DateTimeImmutable('@0'),
            purgedAt: self::utc($r['purged_at'] ?? null),
        );
    }
}
