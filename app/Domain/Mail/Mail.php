<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Deadline\DueDatePolicy;
use App\Domain\Deadline\DueStatus;
use DateTimeImmutable;

final class Mail
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly Direction $direction,
        public readonly string $reference,
        public readonly int $sequenceYear,
        public readonly int $sequenceNumber,
        public readonly string $subject,
        public readonly ?string $summary,
        public readonly int $correspondentId,
        public readonly ?int $departmentId,
        public readonly Channel $channel,
        public readonly Priority $priority,
        public readonly Confidentiality $confidentiality,
        public readonly MailStatus $status,
        public readonly ?string $documentDate,
        public readonly ?DateTimeImmutable $receivedAt,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly ?string $dueDate,
        public readonly ?string $externalReference,
        public readonly ?DateTimeImmutable $closedAt,
        public readonly int $createdBy,
        public readonly ?int $updatedBy,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isEditable(): bool
    {
        return $this->status !== MailStatus::Archived;
    }

    /** Overdue: due date strictly before today (application time zone) and still pending. */
    public function isOverdue(string $today): bool
    {
        return $this->dueStatus($today) === DueStatus::Overdue;
    }

    public function dueStatus(string $today): DueStatus
    {
        return DueDatePolicy::status($this->dueDate, $today, $this->status->isFinished());
    }

    /**
     * Scalar snapshot of every field a user can change (for activity_log diffs).
     *
     * @return array<string, string|int|null>
     */
    public function auditValues(): array
    {
        return self::auditValuesOf(
            $this->subject,
            $this->summary,
            $this->correspondentId,
            $this->departmentId,
            $this->channel,
            $this->priority,
            $this->confidentiality,
            $this->status,
            $this->documentDate,
            $this->receivedAt,
            $this->sentAt,
            $this->dueDate,
            $this->externalReference,
        );
    }

    /** @return array<string, string|int|null> */
    public static function auditValuesOf(
        string $subject,
        ?string $summary,
        int $correspondentId,
        ?int $departmentId,
        Channel $channel,
        Priority $priority,
        Confidentiality $confidentiality,
        MailStatus $status,
        ?string $documentDate,
        ?DateTimeImmutable $receivedAt,
        ?DateTimeImmutable $sentAt,
        ?string $dueDate,
        ?string $externalReference,
    ): array {
        return [
            'subject' => $subject,
            'summary' => $summary,
            'correspondent_id' => $correspondentId,
            'department_id' => $departmentId,
            'channel' => $channel->value,
            'priority' => $priority->value,
            'confidentiality' => $confidentiality->value,
            'status' => $status->value,
            'document_date' => $documentDate,
            'received_at' => $receivedAt?->format('Y-m-d H:i:s'),
            'sent_at' => $sentAt?->format('Y-m-d H:i:s'),
            'due_date' => $dueDate,
            'external_reference' => $externalReference,
        ];
    }
}
