<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationType;
use DateTimeImmutable;

/**
 * Notifications belong to one user: every read or change is filtered by
 * user_id (the service passes the current user). They carry no site, so
 * the scope adds no filter here.
 */
final class NotificationRepository extends Repository
{
    /**
     * Creates the notification unless (user, dedupe key) already exists.
     *
     * @param array<string, mixed> $data
     * @return bool true when created
     */
    public function createOnce(int $userId, NotificationType $type, ?int $mailId, array $data, ?string $dedupeKey, DateTimeImmutable $at): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO notifications (user_id, type, mail_id, data, dedupe_key, created_at)
             VALUES (:user_id, :type, :mail_id, :data, :dedupe_key, :at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type->value,
            'mail_id' => $mailId,
            'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'dedupe_key' => $dedupeKey,
            'at' => self::sqlDateTime($at),
        ]);
        return $stmt->rowCount() === 1;
    }

    public function exists(int $userId, string $dedupeKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM notifications WHERE user_id = :user_id AND dedupe_key = :key');
        $stmt->execute(['user_id' => $userId, 'key' => $dedupeKey]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<Notification> most recent first */
    public function listForUser(int $userId, int $limit = 50, bool $unreadOnly = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notifications WHERE user_id = :user_id' . ($unreadOnly ? ' AND read_at IS NULL' : '')
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min($limit, 200))
        );
        $stmt->execute(['user_id' => $userId]);
        return array_map(static fn (array $r): Notification => new Notification(
            id: (int) $r['id'],
            userId: (int) $r['user_id'],
            type: NotificationType::from((string) $r['type']),
            mailId: $r['mail_id'] !== null ? (int) $r['mail_id'] : null,
            data: $r['data'] !== null ? (array) json_decode((string) $r['data'], true) : [],
            readAt: self::utc($r['read_at']),
            createdAt: self::utc($r['created_at']) ?? new DateTimeImmutable('@0'),
        ), $stmt->fetchAll());
    }

    public function countUnread(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL');
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return ?int the mail id of the notification, when it belongs to $userId */
    public function markRead(int $userId, int $id, DateTimeImmutable $at): ?int
    {
        $stmt = $this->pdo->prepare('SELECT mail_id FROM notifications WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $update = $this->pdo->prepare('UPDATE notifications SET read_at = :at WHERE id = :id AND user_id = :user_id AND read_at IS NULL');
        $update->execute(['at' => self::sqlDateTime($at), 'id' => $id, 'user_id' => $userId]);
        return $row['mail_id'] !== null ? (int) $row['mail_id'] : 0;
    }

    public function markAllRead(int $userId, DateTimeImmutable $at): int
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET read_at = :at WHERE user_id = :user_id AND read_at IS NULL');
        $stmt->execute(['at' => self::sqlDateTime($at), 'user_id' => $userId]);
        return $stmt->rowCount();
    }

    /** Housekeeping: read notifications older than $before. */
    public function purgeReadBefore(DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < :before');
        $stmt->execute(['before' => self::sqlDateTime($before)]);
        return $stmt->rowCount();
    }
}
