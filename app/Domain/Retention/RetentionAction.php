<?php

declare(strict_types=1);

namespace App\Domain\Retention;

enum RetentionAction: string
{
    /** Closed mail → archived (frozen). */
    case Archive = 'archive';
    /** Archived mail: attachment files deleted; their metadata and SHA-256 are kept. */
    case PurgeAttachments = 'purge_attachments';
    /** Administrators are notified to decide (destruction is never automatic). */
    case Review = 'review';
}
