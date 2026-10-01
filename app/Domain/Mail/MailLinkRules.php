<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\RuleViolation;

final class MailLinkRules
{
    /**
     * An outgoing mail answers an incoming mail of the same site that is not archived.
     *
     * @throws RuleViolation
     */
    public static function checkReply(Mail $outgoing, Mail $incoming, bool $alreadyLinked): void
    {
        $errors = [];
        if ($outgoing->id === $incoming->id) {
            $errors['reference'][] = ['rules.link.same_mail', []];
        }
        if ($outgoing->direction !== Direction::Outgoing) {
            $errors['reference'][] = ['rules.link.source_outgoing', []];
        }
        if ($incoming->direction !== Direction::Incoming) {
            $errors['reference'][] = ['rules.link.target_incoming', []];
        }
        if ($outgoing->siteId !== $incoming->siteId) {
            $errors['reference'][] = ['rules.link.same_site', []];
        }
        if ($incoming->status === MailStatus::Archived) {
            $errors['reference'][] = ['rules.link.target_archived', []];
        }
        if ($alreadyLinked) {
            $errors['reference'][] = ['rules.link.duplicate', []];
        }
        if ($errors !== []) {
            throw new RuleViolation($errors);
        }
    }
}
