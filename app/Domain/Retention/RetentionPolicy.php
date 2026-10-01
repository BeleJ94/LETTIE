<?php

declare(strict_types=1);

namespace App\Domain\Retention;

use App\Domain\Mail\Direction;
use App\Domain\RuleViolation;

final class RetentionPolicy
{
    /**
     * The rule governing $action for a mail: the most specific active matching rule
     * (ties: the shortest retention, then the oldest rule).
     *
     * @param list<RetentionRule> $rules
     */
    public static function ruleFor(int $siteId, Direction $direction, RetentionAction $action, array $rules): ?RetentionRule
    {
        $best = null;
        foreach ($rules as $rule) {
            if ($rule->action !== $action || !$rule->matches($siteId, $direction)) {
                continue;
            }
            if ($best === null
                || $rule->specificity() > $best->specificity()
                || ($rule->specificity() === $best->specificity() && [$rule->retentionMonths, $rule->id] < [$best->retentionMonths, $best->id])
            ) {
                $best = $rule;
            }
        }
        return $best;
    }

    /** @throws RuleViolation */
    public static function checkNewRule(string $name, int $months): void
    {
        $errors = [];
        if (trim($name) === '') {
            $errors['name'][] = ['rules.retention.name_required', []];
        }
        if ($months < 1 || $months > 1200) {
            $errors['retention_months'][] = ['rules.retention.months_range', ['min' => 1, 'max' => 1200]];
        }
        if ($errors !== []) {
            throw new RuleViolation($errors);
        }
    }
}
