<?php

declare(strict_types=1);

namespace App\Domain\Mail;

enum LinkType: string
{
    /** Outgoing mail (source) answers an incoming mail (target). */
    case ReplyTo = 'reply_to';
    case Related = 'related';
}
