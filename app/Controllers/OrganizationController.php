<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\Url;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Domain\Auth\Permission;
use App\Domain\OutOfScopeException;
use App\Domain\RuleViolation;
use App\Services\AuthService;
use App\Services\OrganizationService;
use Closure;

/** Sites and departments (permission settings.manage). */
final class OrganizationController
{
    use HandlesDomain;

    public function __construct(
        private readonly OrganizationService $organization,
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
        return $this->page($request);
    }

    public function storeSite(Request $request): Response
    {
        return $this->save($request, 'site-new', 'organization.site_created', function (array $d) use ($request): void {
            $this->organization->createSite($this->actor($request), $d['code'], $d['name']);
        });
    }

    public function updateSite(Request $request): Response
    {
        $id = $this->routeId($request);
        return $this->save($request, 'site-' . $id, 'organization.site_updated', function (array $d) use ($request, $id): void {
            $this->organization->updateSite($this->actor($request), $id, $d['code'], $d['name']);
        });
    }

    public function toggleSite(Request $request): Response
    {
        return $this->toggle($request, fn (bool $active) => $this->organization->setSiteActive($this->actor($request), $this->routeId($request), $active));
    }

    public function storeDepartment(Request $request): Response
    {
        return $this->save($request, 'department-new', 'organization.department_created', function (array $d) use ($request): void {
            $this->organization->createDepartment($this->actor($request), $d['site_id'], $d['code'], $d['name']);
        }, ['site_id' => 'required|int|min:1']);
    }

    public function updateDepartment(Request $request): Response
    {
        $id = $this->routeId($request);
        return $this->save($request, 'department-' . $id, 'organization.department_updated', function (array $d) use ($request, $id): void {
            $this->organization->updateDepartment($this->actor($request), $id, $d['code'], $d['name']);
        });
    }

    public function toggleDepartment(Request $request): Response
    {
        return $this->toggle($request, fn (bool $active) => $this->organization->setDepartmentActive($this->actor($request), $this->routeId($request), $active));
    }

    /**
     * Validates code and name, runs the change, and reopens the dialog with its errors when refused.
     *
     * @param Closure(array<string, mixed>): void $change
     * @param array<string, string> $extraRules
     */
    private function save(Request $request, string $dialog, string $message, Closure $change, array $extraRules = []): Response
    {
        try {
            $d = $this->validator->validate($request->all(), $extraRules + [
                'code' => 'required|string|max:20',
                'name' => 'required|string|max:150',
            ], ['code' => 'organization.fields.code', 'name' => 'organization.fields.name', 'site_id' => 'organization.fields.site_id']);
            $this->orNotFound(function () use ($change, $d): void {
                try {
                    $change($d);
                } catch (OutOfScopeException) {
                    throw \App\Core\HttpException::forbidden();
                }
            });
        } catch (ValidationException | RuleViolation $e) {
            return $this->page($request, $dialog, $request->all(), $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get($message, ['name' => $d['name']]));
        return Response::redirect($this->url->route('organization.index'));
    }

    /** @param Closure(bool): void $change */
    private function toggle(Request $request, Closure $change): Response
    {
        $active = $request->post('active') === '1';
        try {
            $this->orNotFound(fn () => $change($active));
            $this->session->flash('flash.success', $this->translator->get($active ? 'organization.enabled' : 'organization.disabled'));
        } catch (RuleViolation $e) {
            $this->session->flash('flash.error', implode(' ', array_merge(...array_values($this->errorsOf($e)))));
        }
        return Response::redirect($this->url->route('organization.index'));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function page(Request $request, ?string $openDialog = null, array $values = [], array $errors = []): Response
    {
        $actor = $this->actor($request);
        return Response::html($this->view->render('organization/index', [
            'sites' => $this->organization->sites(),
            'departments' => $this->organization->departments(),
            'canManageSites' => $actor->user->can(Permission::SitesAll),
            'ownSiteId' => $actor->user->siteId,
            'openDialog' => $openDialog,
            'values' => $values,
            'errors' => $errors,
        ]), $errors === [] ? 200 : 422);
    }
}
