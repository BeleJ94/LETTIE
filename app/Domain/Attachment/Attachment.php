<?php

declare(strict_types=1);

namespace App\Domain\Attachment;

use DateTimeImmutable;

final class Attachment
{
    public function __construct(
        public readonly int $id,
        public readonly int $mailId,
        public readonly int $siteId,
        public readonly string $originalName,
        public readonly string $storedPath,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly int $uploadedBy,
        public readonly DateTimeImmutable $createdAt,
        /** Set when the file was deleted by a retention rule (metadata and hash remain). */
        public readonly ?DateTimeImmutable $purgedAt = null,
    ) {
    }

    public function isPurged(): bool
    {
        return $this->purgedAt !== null;
    }
}
