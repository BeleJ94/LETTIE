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
use App\Domain\Correspondent\Correspondent;
use App\Domain\Correspondent\CorrespondentInput;
use App\Domain\Correspondent\CorrespondentType;
use App\Domain\RuleViolation;
use App\Services\AuthService;
use App\Services\CorrespondentService;

final class CorrespondentController
{
    use HandlesDomain;

    public function __construct(
        private readonly CorrespondentService $correspondents,
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
        return Response::html($this->view->render('correspondents/index'));
    }

    public function data(Request $request): Response
    {
        $table = TableRequest::fromRequest($request, CorrespondentService::sortKeys(), 'name');
        return Response::json($this->correspondents->page($table, $request->query('inactive') === '1')->toArray());
    }

    /** Autocomplete for the mail form: [{id, label}]. */
    public function search(Request $request): Response
    {
        $term = (string) $request->query('q', '');
        if (mb_strlen(trim($term)) < 2) {
            return Response::json(['data' => []]);
        }
        $siteId = filter_var($request->query('site_id'), FILTER_VALIDATE_INT) ?: null;
        $rows = array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'label' => $r['name'],
            'detail' => implode(' · ', array_filter([$r['organization'], $r['city']])),
        ], $this->correspondents->search($term, 10, $siteId));
        return Response::json(['data' => $rows]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, ['type' => CorrespondentType::Organization->value, 'country' => 'FR', 'is_active' => '1']);
    }

    public function store(Request $request): Response
    {
        try {
            $correspondent = $this->correspondents->create($this->actor($request), $this->parseInput($request));
        } catch (ValidationException | RuleViolation $e) {
            return $this->form(null, $request->all(), $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get('correspondent.created', ['name' => $correspondent->name]));
        return Response::redirect($this->url->route('correspondents.index'));
    }

    public function edit(Request $request): Response
    {
        $c = $this->orNotFound(fn (): Correspondent => $this->correspondents->find($this->routeId($request)));
        return $this->form($c, [
            'type' => $c->type->value,
            'name' => $c->name,
            'organization' => $c->organization,
            'email' => $c->email,
            'phone' => $c->phone,
            'address_line1' => $c->addressLine1,
            'address_line2' => $c->addressLine2,
            'postal_code' => $c->postalCode,
            'city' => $c->city,
            'country' => $c->country,
            'notes' => $c->notes,
            'is_active' => $c->isActive ? '1' : '0',
        ]);
    }

    public function update(Request $request): Response
    {
        $c = $this->orNotFound(fn (): Correspondent => $this->correspondents->find($this->routeId($request)));
        try {
            $c = $this->orNotFound(fn (): Correspondent => $this->correspondents->update($this->actor($request), $c->id, $this->parseInput($request)));
        } catch (ValidationException | RuleViolation $e) {
            return $this->form($c, $request->all(), $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get('correspondent.updated', ['name' => $c->name]));
        return Response::redirect($this->url->route('correspondents.index'));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    private function form(?Correspondent $correspondent, array $values, array $errors = []): Response
    {
        return Response::html($this->view->render('correspondents/form', [
            'correspondent' => $correspondent,
            'values' => $values,
            'errors' => $errors,
        ]), $errors === [] ? 200 : 422);
    }

    private function parseInput(Request $request): CorrespondentInput
    {
        $rules = [
            'type' => 'required|in:' . implode(',', array_column(CorrespondentType::cases(), 'value')),
            'name' => 'required|string|max:190',
            'organization' => 'nullable|string|max:190',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:50',
            'address_line1' => 'nullable|string|max:190',
            'address_line2' => 'nullable|string|max:190',
            'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:100',
            'country' => 'required|regex:/^[A-Za-z]{2}$/',
            'notes' => 'nullable|string|max:5000',
            'is_active' => 'nullable|bool',
        ];
        $labels = array_combine(array_keys($rules), array_map(static fn (string $f): string => 'correspondent.fields.' . $f, array_keys($rules)));
        $d = $this->validator->validate($request->all(), $rules, $labels);

        return new CorrespondentInput(
            type: CorrespondentType::from($d['type']),
            name: $d['name'],
            organization: self::nullIfEmpty($d['organization']),
            email: self::nullIfEmpty($d['email'] !== null ? mb_strtolower($d['email']) : null),
            phone: self::nullIfEmpty($d['phone']),
            addressLine1: self::nullIfEmpty($d['address_line1']),
            addressLine2: self::nullIfEmpty($d['address_line2']),
            postalCode: self::nullIfEmpty($d['postal_code']),
            city: self::nullIfEmpty($d['city']),
            country: strtoupper($d['country']),
            notes: self::nullIfEmpty($d['notes']),
            isActive: $d['is_active'] ?? false,
        );
    }
}
