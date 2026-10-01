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
use App\Domain\Mail\Direction;
use App\Domain\Retention\RetentionAction;
use App\Domain\RuleViolation;
use App\Services\AuthService;
use App\Services\DailyTaskService;
use App\Services\RetentionService;

final class RetentionController
{
    use HandlesDomain;

    public function __construct(
        private readonly RetentionService $retention,
        private readonly DailyTaskService $dailyTask,
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

    public function store(Request $request): Response
    {
        try {
            $data = $this->validator->validate($request->all(), [
                'name' => 'required|string|max:150',
                'direction' => 'nullable|in:' . implode(',', array_column(Direction::cases(), 'value')),
                'retention_months' => 'required|int|min:1|max:1200',
                'action' => 'required|in:' . implode(',', array_column(RetentionAction::cases(), 'value')),
                'all_sites' => 'nullable|bool',
            ], [
                'name' => 'retention.fields.name',
                'direction' => 'retention.fields.direction',
                'retention_months' => 'retention.fields.retention_months',
                'action' => 'retention.fields.action',
            ]);
            $actor = $this->actor($request);
            $rule = $this->retention->create(
                $actor,
                ($data['all_sites'] ?? false) ? null : $actor->user->siteId,
                $data['name'],
                $data['direction'] !== null ? Direction::from($data['direction']) : null,
                $data['retention_months'],
                RetentionAction::from($data['action']),
            );
        } catch (ValidationException | RuleViolation $e) {
            return $this->page($request, $request->all(), $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get('retention.created', ['name' => $rule->name]));
        return Response::redirect($this->url->route('retention.index'));
    }

    public function toggle(Request $request): Response
    {
        $active = $request->post('active') === '1';
        $this->orNotFound(fn () => $this->retention->setActive($this->actor($request), $this->routeId($request), $active));
        $this->session->flash('flash.success', $this->translator->get($active ? 'retention.enabled' : 'retention.disabled'));
        return Response::redirect($this->url->route('retention.index'));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function page(Request $request, array $values = [], array $errors = []): Response
    {
        return Response::html($this->view->render('retention/index', [
            'rules' => $this->retention->rules(),
            'runs' => $this->dailyTask->latestRuns(10),
            'canGlobal' => $this->actor($request)->user->can(Permission::SitesAll),
            'values' => $values + ['action' => RetentionAction::Archive->value, 'retention_months' => '12'],
            'errors' => $errors,
        ]), $errors === [] ? 200 : 422);
    }
}
