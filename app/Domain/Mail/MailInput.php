<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use DateTimeImmutable;

/**
 * Editable fields of a mail, already parsed: datetimes in UTC, dates as "Y-m-d".
 * The status is not editable: it changes through MailWorkflow actions only.
 */
final class MailInput
{
    public function __construct(
        public readonly string $subject,
        public readonly ?string $summary,
        public readonly int $correspondentId,
        public readonly ?int $departmentId,
        public readonly Channel $channel,
        public readonly Priority $priority,
        public readonly Confidentiality $confidentiality,
        public readonly ?string $documentDate,
        public readonly ?DateTimeImmutable $receivedAt,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly ?string $dueDate,
        public readonly ?string $externalReference,
    ) {
    }

    public function withDueDate(?string $dueDate): self
    {
        return new self($this->subject, $this->summary, $this->correspondentId, $this->departmentId, $this->channel,
            $this->priority, $this->confidentiality, $this->documentDate, $this->receivedAt, $this->sentAt,
            $dueDate, $this->externalReference);
    }
}
