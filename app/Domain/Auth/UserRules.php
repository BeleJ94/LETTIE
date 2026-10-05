<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\RuleViolation;

/** Business rules of user administration (pure: the facts are looked up by the service). */
final class UserRules
{
    public const LOCALES = ['fr', 'en'];

    /**
     * @param ?User $target the account being modified, null for a creation
     * @param bool $emailTaken another account already uses the e-mail
     * @param bool $siteKnown the site exists and the administrator may use it
     * @param ?int $departmentSiteId site of the chosen department (null: none chosen or unknown)
     *
     * @throws RuleViolation
     */
    public static function assertValid(
        UserInput $input,
        User $administrator,
        ?User $target,
        bool $emailTaken,
        bool $siteKnown,
        ?int $departmentSiteId,
    ): void {
        $violations = [];
        if ($emailTaken) {
            $violations['email'][] = ['rules.user.email_taken', []];
        }
        if (!$siteKnown) {
            $violations['site_id'][] = ['rules.user.site_unknown', []];
        }
        if ($input->departmentId !== null && $departmentSiteId !== $input->siteId) {
            $violations['department_id'][] = ['rules.user.department_other_site', []];
        }
        if (!in_array($input->locale, self::LOCALES, true)) {
            $violations['locale'][] = ['rules.user.locale_unknown', []];
        }
        // Administrators cannot lock themselves out: another administrator must do it.
        if ($target !== null && $target->id === $administrator->id) {
            if (!$input->isActive) {
                $violations['is_active'][] = ['rules.user.self_deactivate', []];
            }
            if ($input->role !== $target->role) {
                $violations['role'][] = ['rules.user.self_role', []];
            }
        }
        if ($violations !== []) {
            throw new RuleViolation($violations);
        }
    }

    /**
     * Words a password must not contain: first name, last name, name part of the e-mail.
     *
     * @return list<string>
     */
    public static function personalWords(string $firstName, string $lastName, string $email): array
    {
        return [$firstName, $lastName, explode('@', $email)[0]];
    }

    /**
     * @param list<string> $personalWords see personalWords()
     *
     * @throws RuleViolation
     */
    public static function assertPassword(string $password, string $field = 'password', array $personalWords = []): void
    {
        if (mb_strlen($password) < PasswordPolicy::MIN_LENGTH) {
            throw RuleViolation::single($field, 'rules.user.password_min', ['min' => PasswordPolicy::MIN_LENGTH]);
        }
        if (strlen($password) > PasswordPolicy::MAX_BYTES) {
            throw RuleViolation::single($field, 'rules.user.password_max', ['max' => PasswordPolicy::MAX_BYTES]);
        }
        if (PasswordPolicy::isCommon($password)) {
            throw RuleViolation::single($field, 'rules.user.password_common');
        }
        if (PasswordPolicy::personalWordIn($password, $personalWords) !== null) {
            throw RuleViolation::single($field, 'rules.user.password_personal');
        }
    }
}
