<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Domain\RuleViolation;
use App\Domain\Stats\Granularity;
use App\Services\AuthService;
use App\Services\ReferenceDataService;
use App\Services\StatsService;

final class StatisticsController
{
    use HandlesDomain;

    public function __construct(
        private readonly StatsService $stats,
        private readonly ReferenceDataService $reference,
        private readonly AuthService $auth,
        private readonly Validator $validator,
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        [$from, $to] = $this->stats->defaultRange();
        return Response::html($this->view->render('statistics/index', [
            'from' => $from,
            'to' => $to,
            'departments' => $this->reference->departments(),
        ]));
    }

    /** GET /statistics/data?from=&to=&granularity=&department_id= */
    public function data(Request $request): Response
    {
        [$defaultFrom, $defaultTo] = $this->stats->defaultRange();
        try {
            $d = $this->validator->validate($request->all(), [
                'from' => 'nullable|date',
                'to' => 'nullable|date',
                'granularity' => 'nullable|in:' . implode(',', array_column(Granularity::cases(), 'value')),
                'department_id' => 'nullable|int|min:1',
            ], ['from' => 'stats.from', 'to' => 'stats.to']);
            return Response::json($this->stats->dashboard(
                $d['from'] ?? $defaultFrom,
                $d['to'] ?? $defaultTo,
                $d['granularity'] !== null ? Granularity::from($d['granularity']) : null,
                $d['department_id'],
            ));
        } catch (ValidationException | RuleViolation $e) {
            return Response::json(['error' => $this->translator->get('js.errors.validation'), 'errors' => $this->errorsOf($e)], 422);
        }
    }
}
