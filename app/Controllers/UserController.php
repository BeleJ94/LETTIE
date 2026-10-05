<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TableRequest;
use App\Core\Translator;
use App\Core\Url;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Domain\Auth\UserImport;
use App\Domain\Auth\UserInput;
use App\Domain\Auth\UserRules;
use App\Domain\RuleViolation;
use App\Services\AuthService;
use App\Services\ReferenceDataService;
use App\Services\UserAdminService;
use App\Services\UserImportService;

/** User administration: list, creation, modification, new password (permission users.manage). */
final class UserController
{
    use HandlesDomain;

    /** Checked lines of an import, kept between the check and the confirmation. */
    private const IMPORT_KEY = 'users.import';

    public function __construct(
        private readonly UserAdminService $users,
        private readonly UserImportService $import,
        private readonly ReferenceDataService $referenceData,
        private readonly AuthService $auth,
        private readonly Validator $validator,
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly View $view,
        private readonly Url $url,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('users/index', ['sites' => $this->users->sites()]));
    }

    /** GET /users/data?role=&site_id=&inactive=1 (server-side table) */
    public function data(Request $request): Response
    {
        $table = TableRequest::fromRequest($request, UserAdminService::sortKeys(), 'name');
        $siteId = filter_var($request->query('site_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $page = $this->users->page(
            $table,
            Role::tryFrom((string) $request->query('role')),
            $siteId,
            $request->query('inactive') === '1',
        )->toArray();
        // The role is shown by its label; the code stays the filter and sort key.
        $page['data'] = array_map(function (array $row): array {
            $row['role'] = $this->translator->get('roles.' . $row['role']);
            return $row;
        }, $page['data']);
        return Response::json($page);
    }

    /** GET /roles: what each role may do (read-only, defined in the code) */
    public function roles(Request $request): Response
    {
        return Response::html($this->view->render('users/roles'));
    }

    public function importForm(Request $request): Response
    {
        $this->session->remove(self::IMPORT_KEY);
        return $this->importPage();
    }

    /** POST /users/import: reads and checks the file, writes nothing */
    public function importCheck(Request $request): Response
    {
        $file = $request->file('file');
        try {
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_file($file['tmp_name'])) {
                throw RuleViolation::single('file', 'rules.import.no_file');
            }
            if ((int) filesize($file['tmp_name']) > UserImport::MAX_BYTES) {
                throw RuleViolation::single('file', 'rules.import.too_big', ['max' => intdiv(UserImport::MAX_BYTES, 1000)]);
            }
            $labels = [];
            foreach (Role::cases() as $role) {
                $labels[$this->translator->get($role->labelKey())] = $role->value;
            }
            $checked = $this->import->check($this->actor($request), (string) file_get_contents($file['tmp_name']), $labels);
        } catch (RuleViolation $e) {
            return $this->importPage($this->errorsOf($e));
        }
        $this->session->set(self::IMPORT_KEY, $checked);
        return $this->importPage([], $checked);
    }

    /** POST /users/import/confirm: creates the accounts of the valid lines */
    public function importConfirm(Request $request): Response
    {
        $checked = $this->session->get(self::IMPORT_KEY);
        $this->session->remove(self::IMPORT_KEY);
        if (!is_array($checked) || $checked === []) {
            return Response::redirect($this->url->route('users.import'));
        }
        return $this->importPage([], null, $this->import->import($this->actor($request), $checked));
    }

    /**
     * @param array<string, list<string>> $errors
     * @param ?list<array<string, mixed>> $checked
     * @param ?list<array<string, mixed>> $results
     */
    private function importPage(array $errors = [], ?array $checked = null, ?array $results = null): Response
    {
        return Response::html(
            $this->view->render('users/import', ['errors' => $errors, 'checked' => $checked, 'results' => $results]),
            $errors === [] ? 200 : 422,
        );
    }

    /** GET /users/export: same filters as the list, without paging */
    public function export(Request $request): Response
    {
        $table = TableRequest::fromRequest($request, UserAdminService::sortKeys(), 'name');
        $siteId = filter_var($request->query('site_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $export = $this->users->export($table, Role::tryFrom((string) $request->query('role')), $siteId, $request->query('inactive') === '1');
        $export['data'] = array_map(function (array $row): array {
            $row['role'] = $this->translator->get('roles.' . $row['role']);
            return $row;
        }, $export['data']);
        return Response::json($export);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        return $this->form(null, ['role' => Role::Agent->value, 'site_id' => (string) $actor->user->siteId, 'locale' => 'fr', 'is_active' => '1']);
    }

    public function store(Request $request): Response
    {
        try {
            // One validation for the account and its password: every error comes back at once.
            $data = $this->validated($request, ['password' => 'required|string']);
            $user = $this->users->create($this->actor($request), self::inputFrom($data), $data['password']);
        } catch (ValidationException | RuleViolation $e) {
            return $this->form(null, $this->withoutPasswords($request), $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get('user.created', ['name' => $user->fullName()]));
        return Response::redirect($this->url->route('users.index'));
    }

    public function edit(Request $request): Response
    {
        $user = $this->orNotFound(fn (): User => $this->users->find($this->routeId($request)));
        return $this->form($user, $this->valuesOf($user));
    }

    public function update(Request $request): Response
    {
        $user = $this->orNotFound(fn (): User => $this->users->find($this->routeId($request)));
        try {
            $user = $this->orNotFound(fn (): User => $this->saveChanges($request, $user));
        } catch (ValidationException | RuleViolation $e) {
            return $this->form($user, $this->withoutPasswords($request), $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get('user.updated', ['name' => $user->fullName()]));
        return Response::redirect($this->url->route('users.index'));
    }

    private function saveChanges(Request $request, User $user): User
    {
        $d = $this->validated($request, ['reassign_to' => 'nullable|int|min:1']);
        return $this->users->update($this->actor($request), $user->id, self::inputFrom($d), $d['reassign_to']);
    }

    /** POST /users/{id}/unlock */
    public function unlock(Request $request): Response
    {
        $user = $this->orNotFound(fn (): User => $this->users->unlock($this->actor($request), $this->routeId($request)));
        $this->session->flash('flash.success', $this->translator->get('user.unlocked', ['name' => $user->fullName()]));
        return Response::redirect($this->url->route('users.edit', ['id' => $user->id]));
    }

    /** POST /users/{id}/2fa/reset */
    public function resetTotp(Request $request): Response
    {
        $user = $this->orNotFound(fn (): User => $this->users->resetTotp($this->actor($request), $this->routeId($request)));
        $this->session->flash('flash.success', $this->translator->get('user.totp_reset', ['name' => $user->fullName()]));
        return Response::redirect($this->url->route('users.edit', ['id' => $user->id]));
    }

    /** POST /users/{id}/password */
    public function password(Request $request): Response
    {
        $user = $this->orNotFound(fn (): User => $this->users->find($this->routeId($request)));
        try {
            $password = $this->validator->validate($request->all(), ['new_password' => 'required|string'], ['new_password' => 'user.fields.new_password'])['new_password'];
            $this->orNotFound(fn (): User => $this->users->resetPassword($this->actor($request), $user->id, $password));
        } catch (ValidationException | RuleViolation $e) {
            return $this->form($user, $this->valuesOf($user), $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get('user.password_reset', ['name' => $user->fullName()]));
        return Response::redirect($this->url->route('users.edit', ['id' => $user->id]));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function form(?User $user, array $values, array $errors = []): Response
    {
        return Response::html($this->view->render('users/form', [
            'user' => $user,
            'values' => $values,
            'errors' => $errors,
            'sites' => $this->users->sites(),
            'departments' => $this->referenceData->departments(),
            'details' => $user !== null ? $this->users->details($user) : null,
        ]), $errors === [] ? 200 : 422);
    }

    /** @return array<string, string> */
    private function valuesOf(User $user): array
    {
        return [
            'first_name' => $user->firstName,
            'last_name' => $user->lastName,
            'email' => $user->email,
            'role' => $user->role->value,
            'site_id' => (string) $user->siteId,
            'department_id' => (string) ($user->departmentId ?? ''),
            'locale' => $user->locale,
            'is_active' => $user->isActive ? '1' : '0',
        ];
    }

    /**
     * A password is never sent back to the page.
     *
     * @return array<string, mixed>
     */
    private function withoutPasswords(Request $request): array
    {
        return array_diff_key($request->all(), ['password' => true, 'new_password' => true]);
    }

    /**
     * @param array<string, string> $extraRules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $extraRules = []): array
    {
        $rules = $extraRules + [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|max:190',
            'role' => 'required|in:' . implode(',', array_column(Role::cases(), 'value')),
            'site_id' => 'required|int|min:1',
            'department_id' => 'nullable|int|min:1',
            'locale' => 'required|in:' . implode(',', UserRules::LOCALES),
            'is_active' => 'nullable|bool',
        ];
        $labels = array_combine(array_keys($rules), array_map(static fn (string $f): string => 'user.fields.' . $f, array_keys($rules)));
        return $this->validator->validate($request->all(), $rules, $labels);
    }

    /** @param array<string, mixed> $d validated fields */
    private static function inputFrom(array $d): UserInput
    {
        return new UserInput(
            firstName: $d['first_name'],
            lastName: $d['last_name'],
            email: mb_strtolower($d['email']),
            role: Role::from($d['role']),
            siteId: $d['site_id'],
            departmentId: $d['department_id'],
            locale: $d['locale'],
            isActive: $d['is_active'] ?? false,
        );
    }
}
