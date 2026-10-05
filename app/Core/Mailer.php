<?php

declare(strict_types=1);

namespace App\Core;

/** Sends plain-text e-mails (password recovery). The application works without it: features that need it hide. */
interface Mailer
{
    /** False when no mail server is configured (MAIL_HOST and MAIL_FROM in .env). */
    public function isConfigured(): bool;

    /** @throws MailException when the message could not be handed to the mail server */
    public function send(string $to, string $subject, string $text): void;
}
