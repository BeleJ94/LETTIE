<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\RuleViolation;

/**
 * Reads a CSV file of user accounts (pure: no file, no database).
 *
 * First line: the column names, in any order, in French or in English.
 * Separator ";" or "," (detected on the first line). UTF-8, with or without BOM.
 */
final class UserImport
{
    public const MAX_ROWS = 200;
    public const MAX_BYTES = 200_000;

    /** Column => accepted header names (lower case, without accents). */
    private const HEADERS = [
        'first_name' => ['prenom', 'first_name', 'firstname', 'first name'],
        'last_name' => ['nom', 'last_name', 'lastname', 'last name'],
        'email' => ['email', 'e-mail', 'mail', 'courriel'],
        'role' => ['role'],
        'site' => ['site'],
        'department' => ['service', 'department'],
    ];
    private const REQUIRED = ['first_name', 'last_name', 'email', 'role'];

    /**
     * @return list<array{line: int, first_name: string, last_name: string, email: string, role: string, site: string, department: string}>
     *
     * @throws RuleViolation when the file itself cannot be used (no header, too long…)
     */
    public static function parse(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw RuleViolation::single('file', 'rules.import.too_big', ['max' => intdiv(self::MAX_BYTES, 1000)]);
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        if (!mb_check_encoding($csv, 'UTF-8')) {
            throw RuleViolation::single('file', 'rules.import.encoding');
        }
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $first = (string) ($lines[0] ?? '');
        $separator = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';

        $columns = [];
        foreach (str_getcsv($first, $separator, '"', '') as $index => $name) {
            $key = self::column((string) $name);
            if ($key !== null) {
                $columns[$key] = $index;
            }
        }
        $missing = array_diff(self::REQUIRED, array_keys($columns));
        if ($missing !== []) {
            throw RuleViolation::single('file', 'rules.import.header', ['columns' => 'prenom;nom;email;role;site;service']);
        }

        $rows = [];
        foreach (array_slice($lines, 1, null, true) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $separator, '"', '');
            $cell = static fn (string $key): string => isset($columns[$key]) ? trim((string) ($cells[$columns[$key]] ?? '')) : '';
            $rows[] = [
                'line' => $number + 1,
                'first_name' => $cell('first_name'),
                'last_name' => $cell('last_name'),
                'email' => mb_strtolower($cell('email')),
                'role' => $cell('role'),
                'site' => mb_strtoupper($cell('site')),
                'department' => mb_strtoupper($cell('department')),
            ];
            if (count($rows) > self::MAX_ROWS) {
                throw RuleViolation::single('file', 'rules.import.too_many', ['max' => self::MAX_ROWS]);
            }
        }
        if ($rows === []) {
            throw RuleViolation::single('file', 'rules.import.empty');
        }
        return $rows;
    }

    /**
     * Role named by its code ("agent") or by one of its labels ("Chef de service"), whatever the case.
     *
     * @param array<string, string> $labels label (any language) => role code
     */
    public static function role(string $value, array $labels = []): ?Role
    {
        $normalized = self::normalize($value);
        foreach (Role::cases() as $role) {
            if ($normalized === $role->value) {
                return $role;
            }
        }
        foreach ($labels as $label => $code) {
            if ($normalized === self::normalize($label)) {
                return Role::tryFrom($code);
            }
        }
        return null;
    }

    private static function column(string $header): ?string
    {
        $header = self::normalize($header);
        foreach (self::HEADERS as $key => $names) {
            if (in_array($header, $names, true)) {
                return $key;
            }
        }
        return null;
    }

    /** Lower case, trimmed, without accents: "Prénom " → "prenom". */
    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return strtr($value, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ô' => 'o', 'î' => 'i', 'ï' => 'i', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'ù' => 'u', 'û' => 'u']);
    }
}
