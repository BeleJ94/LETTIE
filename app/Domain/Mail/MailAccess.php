<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Auth\Permission;
use App\Domain\Auth\User;

/**
 * Who may drive a mail through its workflow. Dispatchers (mail.assign)
 * act on any mail of their scope; other users with mail.update only on
 * mail currently assigned to them (directly or as a delegate).
 */
final class MailAccess
{
    public static function canAct(User $user, bool $isAssignee): bool
    {
        if ($user->can(Permission::MailAssign)) {
            return true;
        }
        return $user->can(Permission::MailUpdate) && $isAssignee;
    }

    public static function canPerform(User $user, MailAction $action, bool $isAssignee): bool
    {
        return match ($action) {
            MailAction::Assign, MailAction::Reassign, MailAction::Archive => $user->can(Permission::MailAssign),
            default => self::canAct($user, $isAssignee),
        };
    }
}
