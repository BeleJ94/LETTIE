<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use DomainException;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    /** bcrypt ignores bytes beyond 72. */
    public const MAX_BYTES = 72;
    /** Shortest personal word (name, e-mail) searched in a password. */
    public const MIN_PERSONAL_WORD = 4;

    /** Long enough but found in every list of leaked passwords (compared in lower case). */
    private const COMMON = [
        '123456789012', '1234567890123', '12345678901234', '123456123456', '123123123123', '111111111111', '000000000000',
        'aaaaaaaaaaaa', 'abcdefghijkl', 'abcdefghijklm', 'qwertyuiopas', 'qwertyuiop12', 'qwerty123456', 'azertyuiop12',
        'azertyuiopqs', 'azerty123456', 'password1234', 'password12345', 'passwordpassword', 'motdepasse12', 'motdepasse123',
        'motdepasse1234', 'administrateur', 'administrator', 'bienvenue123', 'bienvenue1234', 'welcome12345', 'iloveyou1234',
        'letmein12345', 'changeme1234', 'changezmoi123', 'azertyazerty', 'qwertyqwerty', 'soleil123456', 'lettie123456',
    ];

    /** True when the password is a well-known one or a single repeated character. */
    public static function isCommon(string $password): bool
    {
        $lower = mb_strtolower($password);
        return in_array($lower, self::COMMON, true) || count(array_unique(mb_str_split($lower))) === 1;
    }

    /**
     * Personal word (first name, last name, e-mail name) found in the password, or null.
     *
     * @param list<string> $words
     */
    public static function personalWordIn(string $password, array $words): ?string
    {
        $lower = mb_strtolower($password);
        foreach ($words as $word) {
            $word = mb_strtolower(trim($word));
            if (mb_strlen($word) >= self::MIN_PERSONAL_WORD && str_contains($lower, $word)) {
                return $word;
            }
        }
        return null;
    }

    public static function assertAcceptable(string $password): void
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw new DomainException('Password must contain at least ' . self::MIN_LENGTH . ' characters.');
        }
        if (strlen($password) > self::MAX_BYTES) {
            throw new DomainException('Password must not exceed ' . self::MAX_BYTES . ' bytes.');
        }
        if (self::isCommon($password)) {
            throw new DomainException('Password is too common.');
        }
    }
}
