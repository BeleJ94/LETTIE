<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Audit\Actor;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\Role;
use App\Domain\Auth\UserImport;
use App\Domain\Auth\UserInput;
use App\Domain\Auth\UserRules;
use App\Domain\RuleViolation;
use App\Repositories\DepartmentRepository;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;

/**
 * Import of user accounts from a CSV file, in two steps: check (nothing is written),
 * then creation of the valid lines. Each account gets its own temporary password,
 * shown once to the administrator and to be replaced at the first sign-in.
 */
final class UserImportService
{
    public function __construct(
        private readonly UserAdminService $admin,
        private readonly UserRepository $users,
        private readonly SiteRepository $sites,
        private readonly DepartmentRepository $departments,
    ) {
    }

    /**
     * @param array<string, string> $roleLabels label => role code (both languages)
     * @return list<array{line: int, first_name: string, last_name: string, email: string, role: ?string, site_id: ?int, site_name: string,
     *                    department_id: ?int, department_name: string, errors: list<array{0: string, 1: array<string, string|int>}>}>
     *
     * @throws RuleViolation when the file cannot be read as a list of accounts
     */
    public function check(Actor $actor, string $csv, array $roleLabels): array
    {
        $sites = [];
        foreach ($this->sites->rows() as $site) {
            $sites[$site['code']] = $site;
        }
        $departments = [];
        foreach ($this->departments->rows() as $department) {
            $departments[$department['site_id']][$department['code']] = $department;
        }
        $ownSite = null;
        foreach ($sites as $site) {
            if ($site['id'] === $actor->user->siteId) {
                $ownSite = $site;
            }
        }

        $seen = [];
        $checked = [];
        foreach (UserImport::parse($csv) as $row) {
            $errors = [];
            if ($row['first_name'] === '' || $row['last_name'] === '' || mb_strlen($row['first_name']) > 100 || mb_strlen($row['last_name']) > 100) {
                $errors[] = ['rules.import.name', []];
            }
            if (filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($row['email']) > 190) {
                $errors[] = ['rules.import.email', []];
            } elseif (isset($seen[$row['email']])) {
                $errors[] = ['rules.import.email_twice', ['line' => $seen[$row['email']]]];
            } elseif ($this->users->emailTaken($row['email'])) {
                $errors[] = ['rules.user.email_taken', []];
            }
            $seen[$row['email']] ??= $row['line'];

            $role = UserImport::role($row['role'], $roleLabels);
            if ($role === null) {
                $errors[] = ['rules.import.role', ['roles' => implode(', ', array_column(Role::cases(), 'value'))]];
            }
            // No site column, or an empty cell: the administrator's own site.
            $site = $row['site'] === '' ? $ownSite : ($sites[$row['site']] ?? null);
            if ($site === null || !$site['is_active']) {
                $errors[] = ['rules.import.site', ['code' => $row['site']]];
            }
            $department = null;
            if ($row['department'] !== '') {
                $department = $site !== null ? ($departments[$site['id']][$row['department']] ?? null) : null;
                if ($department === null || !$department['is_active']) {
                    $errors[] = ['rules.import.department', ['code' => $row['department']]];
                }
            }

            $checked[] = [
                'line' => $row['line'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'role' => $role?->value,
                'site_id' => $site['id'] ?? null,
                'site_name' => $site['name'] ?? $row['site'],
                'department_id' => $department['id'] ?? null,
                'department_name' => $department['name'] ?? $row['department'],
                'errors' => $errors,
            ];
        }
        return $checked;
    }

    /**
     * Creates the accounts of the lines without error. A line refused at this stage
     * (an e-mail taken in the meantime) is reported and does not stop the others.
     *
     * @param list<array<string, mixed>> $rows result of check()
     * @return list<array{line: int, name: string, email: string, password: ?string, error: ?string}>
     */
    public function import(Actor $actor, array $rows): array
    {
        $results = [];
        foreach ($rows as $row) {
            if ($row['errors'] !== [] || $row['role'] === null || $row['site_id'] === null) {
                continue;
            }
            $input = new UserInput($row['first_name'], $row['last_name'], $row['email'], Role::from($row['role']), $row['site_id'], $row['department_id']);
            $password = self::temporaryPassword($input);
            $result = ['line' => $row['line'], 'name' => trim($row['first_name'] . ' ' . $row['last_name']), 'email' => $row['email'], 'password' => null, 'error' => null];
            try {
                $this->admin->create($actor, $input, $password);
                $result['password'] = $password;
            } catch (RuleViolation $e) {
                $result['error'] = array_values($e->violations())[0][0][0];
            }
            $results[] = $result;
        }
        return $results;
    }

    /** Random password that passes the policy (groups of letters and digits, easy to copy by hand). */
    private static function temporaryPassword(UserInput $input): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        do {
            $groups = [];
            for ($g = 0; $g < 4; $g++) {
                $group = '';
                for ($i = 0; $i < 4; $i++) {
                    $group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $groups[] = $group;
            }
            $password = implode('-', $groups);
        } while (PasswordPolicy::isCommon($password) || PasswordPolicy::personalWordIn($password, UserRules::personalWords($input->firstName, $input->lastName, $input->email)) !== null);
        return $password;
    }
}
