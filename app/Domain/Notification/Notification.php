<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use DateTimeImmutable;

final class Notification
{
    /** @param array<string, mixed> $data values for the translated message (reference, subject, due_date…) */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly NotificationType $type,
        public readonly ?int $mailId,
        public readonly array $data,
        public readonly ?DateTimeImmutable $readAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
