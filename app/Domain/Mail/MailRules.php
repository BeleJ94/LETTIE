<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\RuleViolation;
use DateTimeImmutable;

/** Business rules for creating or changing a mail. */
final class MailRules
{
    /** Clock drift tolerated between the user's device and the server. */
    private const FUTURE_TOLERANCE = '+5 minutes';

    /**
     * @param string $today current date in the application time zone ("Y-m-d")
     * @throws RuleViolation
     */
    public static function check(Direction $direction, MailInput $input, DateTimeImmutable $nowUtc, string $today, ?Mail $current = null): void
    {
        $errors = [];
        $limit = $nowUtc->modify(self::FUTURE_TOLERANCE);

        if ($current !== null && !$current->isEditable()) {
            throw RuleViolation::single('status', 'rules.mail.archived');
        }

        if ($direction === Direction::Incoming && $input->receivedAt === null) {
            $errors['received_at'][] = ['rules.mail.received_at_required', []];
        }
        if ($direction === Direction::Outgoing && $input->receivedAt !== null) {
            $errors['received_at'][] = ['rules.mail.received_at_outgoing', []];
        }
        if ($direction === Direction::Incoming && $input->sentAt !== null) {
            $errors['sent_at'][] = ['rules.mail.sent_at_incoming', []];
        }
        if ($input->receivedAt !== null && $input->receivedAt > $limit) {
            $errors['received_at'][] = ['rules.mail.not_in_future', []];
        }
        if ($input->sentAt !== null && $input->sentAt > $limit) {
            $errors['sent_at'][] = ['rules.mail.not_in_future', []];
        }
        if ($input->documentDate !== null && $input->documentDate > $today) {
            $errors['document_date'][] = ['rules.mail.not_in_future', []];
        }
        if ($input->dueDate !== null && $input->documentDate !== null && $input->dueDate < $input->documentDate) {
            $errors['due_date'][] = ['rules.mail.due_before_document', []];
        }

        if ($errors !== []) {
            throw new RuleViolation($errors);
        }
    }
}
