<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Domain\Audit\Actor;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationType;
use App\Repositories\NotificationRepository;

/** In-app notifications. A user only ever reads or changes their own. */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return bool false when an identical notification (same dedupe key) already exists
     */
    public function notify(int $userId, NotificationType $type, ?int $mailId, array $data, ?string $dedupeKey = null): bool
    {
        return $this->notifications->createOnce($userId, $type, $mailId, $data, $dedupeKey, $this->clock->now());
    }

    /** @return list<Notification> */
    public function forUser(Actor $actor, int $limit = 50): array
    {
        return $this->notifications->listForUser($actor->user->id, $limit);
    }

    public function unreadCount(Actor $actor): int
    {
        return $this->notifications->countUnread($actor->user->id);
    }

    /**
     * @return ?int null when the notification is not the actor's; 0 when it has no mail; the mail id otherwise
     */
    public function markRead(Actor $actor, int $id): ?int
    {
        return $this->notifications->markRead($actor->user->id, $id, $this->clock->now());
    }

    public function markAllRead(Actor $actor): int
    {
        return $this->notifications->markAllRead($actor->user->id, $this->clock->now());
    }
}
