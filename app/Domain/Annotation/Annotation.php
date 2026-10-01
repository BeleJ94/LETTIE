<?php

declare(strict_types=1);

namespace App\Domain\Annotation;

use App\Domain\RuleViolation;
use DateTimeImmutable;

final class Annotation
{
    public const MAX_LENGTH = 5000;

    public function __construct(
        public readonly int $id,
        public readonly int $mailId,
        public readonly int $userId,
        public readonly string $userName,
        public readonly string $body,
        public readonly bool $isPrivate,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /** A private annotation is visible to its author only. */
    public function isVisibleTo(int $userId): bool
    {
        return !$this->isPrivate || $this->userId === $userId;
    }

    /** @throws RuleViolation */
    public static function checkBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            throw RuleViolation::single('body', 'rules.annotation.empty');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw RuleViolation::single('body', 'rules.annotation.too_long', ['max' => self::MAX_LENGTH]);
        }
        return $body;
    }
}
