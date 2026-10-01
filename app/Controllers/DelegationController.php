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
use App\Domain\RuleViolation;
use App\Services\AuthService;
use App\Services\DelegationService;

final class DelegationController
{
    use HandlesDomain;

    public function __construct(
        private readonly DelegationService $delegations,
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
                'delegator_id' => 'nullable|int|min:1',
                'delegate_id' => 'required|int|min:1',
                'starts_on' => 'required|date',
                'ends_on' => 'required|date',
                'reason' => 'nullable|string|max:255',
            ], [
                'delegator_id' => 'delegation.fields.delegator_id',
                'delegate_id' => 'delegation.fields.delegate_id',
                'starts_on' => 'delegation.fields.starts_on',
                'ends_on' => 'delegation.fields.ends_on',
                'reason' => 'delegation.fields.reason',
            ]);
            $delegation = $this->orNotFound(fn () => $this->delegations->create(
                $this->actor($request),
                $data['delegator_id'],
                $data['delegate_id'],
                $data['starts_on'],
                $data['ends_on'],
                self::nullIfEmpty($data['reason']),
            ));
        } catch (ValidationException | RuleViolation $e) {
            return $this->page($request, $request->all(), $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get('delegation.created', ['name' => $delegation->delegateName]));
        return Response::redirect($this->url->route('delegations.index'));
    }

    public function cancel(Request $request): Response
    {
        $this->orNotFound(fn () => $this->delegations->cancel($this->actor($request), $this->routeId($request)));
        $this->session->flash('flash.success', $this->translator->get('delegation.cancelled'));
        return Response::redirect($this->url->route('delegations.index'));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function page(Request $request, array $values = [], array $errors = []): Response
    {
        $actor = $this->actor($request);
        $today = $this->delegations->today();
        $delegations = $this->delegations->current();

        return Response::html($this->view->render('delegations/index', [
            'delegations' => $delegations,
            'canManage' => array_combine(
                array_map(static fn ($d): int => $d->id, $delegations),
                array_map(fn ($d): bool => $this->delegations->canManage($actor, $d), $delegations),
            ) ?: [],
            'users' => $this->delegations->users(),
            'canChooseDelegator' => $actor->user->can(Permission::MailAssign),
            'meId' => $actor->user->id,
            'today' => $today,
            'values' => $values + ['starts_on' => $today, 'ends_on' => $today],
            'errors' => $errors,
        ]), $errors === [] ? 200 : 422);
    }
}
