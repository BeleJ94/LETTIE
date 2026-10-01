<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Input format validation for controllers (business rules belong to Domain).
 *
 * Rules, pipe-separated: required, nullable, string, int, numeric, bool, email,
 * date (Y-m-d), datetime (Y-m-d H:i[:s]), min:n, max:n (length for strings,
 * value for numbers), in:a,b,c, regex:/.../, same:field, array.
 */
final class Validator
{
    public function __construct(private readonly Translator $translator)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels field => translation key for the field name
     * @return array<string, mixed> only the validated fields (empty strings become null)
     * @throws ValidationException
     */
    public function validate(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = self::parseRules($ruleString);
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '' || $value === [];
            $label = isset($labels[$field]) ? $this->translator->get($labels[$field]) : $field;

            if ($empty) {
                if (array_key_exists('required', $fieldRules)) {
                    $errors[$field][] = $this->message('required', $label);
                } else {
                    $validated[$field] = null;
                }
                continue;
            }

            $numeric = array_key_exists('int', $fieldRules) || array_key_exists('numeric', $fieldRules);
            foreach ($fieldRules as $rule => $param) {
                if (!$this->passes($rule, $param, $value, $data, $numeric)) {
                    $errors[$field][] = $this->message($rule, $label, $param);
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = self::cast($value, $fieldRules);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $validated;
    }

    /** @return array<string, ?string> */
    private static function parseRules(string $ruleString): array
    {
        $rules = [];
        $parts = explode('|', $ruleString);
        while (($rule = array_shift($parts)) !== null) {
            if ($rule === '') {
                continue;
            }
            // A regex may contain "|": it must be the last rule and takes the rest of the string.
            if (str_starts_with($rule, 'regex:')) {
                $rule = implode('|', [$rule, ...$parts]);
                $parts = [];
            }
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            $rules[$name] = $param;
        }
        return $rules;
    }

    /** @param array<string, mixed> $data */
    private function passes(string $rule, ?string $param, mixed $value, array $data, bool $numeric): bool
    {
        return match ($rule) {
            'required', 'nullable' => true,
            'string' => is_string($value),
            'array' => is_array($value),
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'numeric' => is_numeric($value),
            'bool' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off'], true),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'date' => self::isDate($value, ['Y-m-d']),
            'datetime' => self::isDate($value, ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i']),
            'min' => self::size($value, $numeric) >= (float) $param,
            'max' => self::size($value, $numeric) <= (float) $param,
            'in' => in_array((string) (is_scalar($value) ? $value : ''), explode(',', (string) $param), true),
            'regex' => is_string($value) && preg_match((string) $param, $value) === 1,
            'same' => $value === ($data[(string) $param] ?? null),
            default => throw new InvalidArgumentException("Unknown validation rule: {$rule}"),
        };
    }

    /** @param list<string> $formats */
    private static function isDate(mixed $value, array $formats): bool
    {
        if (!is_string($value)) {
            return false;
        }
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return true;
            }
        }
        return false;
    }

    private static function size(mixed $value, bool $numeric): float
    {
        if (is_array($value)) {
            return count($value);
        }
        if ($numeric && is_numeric($value)) {
            return (float) $value;
        }
        return mb_strlen((string) $value);
    }

    /** @param array<string, ?string> $rules */
    private static function cast(mixed $value, array $rules): mixed
    {
        if (array_key_exists('int', $rules)) {
            return (int) $value;
        }
        if (array_key_exists('bool', $rules)) {
            return in_array($value, [true, 1, '1', 'true', 'on'], true);
        }
        return $value;
    }

    private function message(string $rule, string $label, ?string $param = null): string
    {
        return $this->translator->get('validation.' . $rule, [
            'field' => $label,
            'param' => (string) $param,
        ]);
    }
}
