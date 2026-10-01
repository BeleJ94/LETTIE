<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Auth\User;

/** Who performs an action, and from where (for activity_log). */
final class Actor
{
    public function __construct(
        public readonly User $user,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {
    }
}
