<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use DateTimeImmutable;

/** List criteria. Date bounds are UTC instants: [from, toExclusive). */
final class MailFilter
{
    public function __construct(
        public readonly ?Direction $direction = null,
        public readonly ?MailStatus $status = null,
        public readonly ?Priority $priority = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $correspondentId = null,
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $toExclusive = null,
        /** When set, only pending mail with due_date before this "Y-m-d" date. */
        public readonly ?string $overdueBefore = null,
        /** When set, only mail with an active assignment to one of these users ("my mail"). @var list<int>|null */
        public readonly ?array $assignedToUserIds = null,
        /** When set, only these mails (export of the rows selected in the list). @var list<int>|null */
        public readonly ?array $ids = null,
    ) {
    }
}
