<?php

declare(strict_types=1);

namespace App\Core;

/**
 * View helpers for UI5 Web Components (docs/FIORI_DESIGN.md §5).
 * Every method returns escaped HTML, ready to be echoed in a view.
 */
final class Ui5
{
    /**
     * Semantic state of the enum values shown as tags. Same table as
     * LT.listReport.TAG_DESIGNS (public/assets/js/lt-listreport.js); kept in step by
     * tests/Architecture/ArchitectureTest.php.
     */
    public const TAG_DESIGNS = [
        'status' => [
            'registered' => 'Information',
            'assigned' => 'Information',
            'in_progress' => 'Information',
            'awaiting_reply' => 'Critical',
            'answered' => 'Positive',
            'closed' => 'Positive',
            'archived' => 'Neutral',
        ],
        'priority' => ['urgent' => 'Negative', 'high' => 'Critical', 'normal' => 'Neutral', 'low' => 'Neutral'],
        'direction' => ['incoming' => 'Set2', 'outgoing' => 'Set2'],
    ];

    /** Colour scheme of the "Set2" tags (categories, not states). */
    private const TAG_SCHEMES = ['direction' => ['incoming' => '6', 'outgoing' => '9']];

    /** Due date states (App\Domain\Deadline\DueStatus values). */
    public const DUE_DESIGNS = ['overdue' => 'Negative', 'today' => 'Critical', 'soon' => 'Critical'];

    /** <ui5-tag> of an enum value: status, priority, direction… (label from enums.<enum>.<value>). */
    public static function tag(string $enum, string $value, ?string $label = null): string
    {
        $design = self::TAG_DESIGNS[$enum][$value] ?? 'Neutral';
        $scheme = self::TAG_SCHEMES[$enum][$value] ?? null;
        return '<ui5-tag design="' . e($design) . '"'
            . ($scheme !== null ? ' color-scheme="' . e($scheme) . '"' : '')
            . (in_array($design, ['Set2', 'Neutral'], true) ? ' hide-state-icon' : '')
            . '>' . e($label ?? __("enums.{$enum}.{$value}")) . '</ui5-tag>';
    }

    /** value-state attribute of a field in error (empty string otherwise). */
    public static function state(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' value-state="Negative"' : '';
    }

    /** Message shown under a field in error: goes inside the field element. */
    public static function stateMessage(array $errors, string $field): string
    {
        if (!isset($errors[$field])) {
            return '';
        }
        return '<div slot="valueStateMessage">' . e(implode(' ', (array) $errors[$field])) . '</div>';
    }

    /**
     * Options of a <ui5-select>.
     *
     * @param iterable<string|int, string> $options value => label
     */
    public static function options(iterable $options, string $current): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<ui5-option value="' . e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . e($label) . '</ui5-option>';
        }
        return $html;
    }

    /**
     * Options of a <ui5-select> for the cases of a backed enum, labelled by enums.<enum>.<value>.
     *
     * @param list<\BackedEnum> $cases
     */
    public static function enumOptions(array $cases, string $enum, string $current): string
    {
        $options = [];
        foreach ($cases as $case) {
            $options[$case->value] = __("enums.{$enum}.{$case->value}");
        }
        return self::options($options, $current);
    }

    /** Human-readable file size ("1,2 Mo" / "1.2 MB"). */
    public static function fileSize(int $bytes, string $locale): string
    {
        $units = $locale === 'en' ? ['B', 'KB', 'MB', 'GB'] : ['o', 'Ko', 'Mo', 'Go'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < 3) {
            $n /= 1024;
            $i++;
        }
        return number_format($n, $i === 0 ? 0 : 1, $locale === 'en' ? '.' : ',', $locale === 'en' ? ',' : ' ') . ' ' . $units[$i];
    }
}
