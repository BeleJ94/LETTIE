<?php

declare(strict_types=1);

namespace App\Domain\Assignment;

use App\Domain\RuleViolation;

final class AssignmentRules
{
    /**
     * @param bool $hasActiveForAction the mail already has an active "for action" assignment
     * @param bool $reassign replacing the current "for action" assignment
     * @throws RuleViolation
     */
    public static function check(AssignmentRequest $request, string $today, bool $hasActiveForAction, bool $reassign): void
    {
        $errors = [];
        if ($request->userId === null && $request->departmentId === null) {
            $errors['user_id'][] = ['rules.assignment.target_required', []];
        }
        if ($request->dueDate !== null && $request->dueDate < $today) {
            $errors['due_date'][] = ['rules.assignment.due_in_past', []];
        }
        if ($reassign) {
            if ($request->role !== AssignmentRole::ForAction) {
                $errors['role'][] = ['rules.assignment.reassign_for_action', []];
            }
            if (!$hasActiveForAction) {
                $errors['user_id'][] = ['rules.assignment.nothing_to_reassign', []];
            }
        } elseif ($request->role === AssignmentRole::ForAction && $hasActiveForAction) {
            // Only one person/department is responsible at a time: use reassignment.
            $errors['role'][] = ['rules.assignment.already_assigned', []];
        }
        if ($errors !== []) {
            throw new RuleViolation($errors);
        }
    }
}
