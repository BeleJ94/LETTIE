<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/** Workflow actions: the only way a mail's status changes. */
enum MailAction: string
{
    case Assign = 'assign';
    case Reassign = 'reassign';
    case Start = 'start';
    case AwaitReply = 'await_reply';
    case Answer = 'answer';
    case Close = 'close';
    case Reopen = 'reopen';
    case Archive = 'archive';
}
