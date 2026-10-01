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
use App\Domain\Mail\Direction;
use App\Domain\RuleViolation;
use App\Services\AuthService;
use App\Services\DocumentService;
use App\Services\StatsService;

/** Mail register: chronological list of registered mail, exported to Excel or PDF by lt-export.js. */
final class RegisterController
{
    use HandlesDomain;

    public function __construct(
        private readonly DocumentService $documents,
        private readonly StatsService $stats,
        private readonly AuthService $auth,
        private readonly Validator $validator,
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $today = $this->stats->today();
        return Response::html($this->view->render('register/index', [
            'from' => substr($today, 0, 8) . '01',
            'to' => $today,
        ]));
    }

    /** GET /register/data?direction=incoming&from=&to= */
    public function data(Request $request): Response
    {
        try {
            $d = $this->validator->validate($request->all(), [
                'direction' => 'required|in:' . implode(',', array_column(Direction::cases(), 'value')),
                'from' => 'required|date',
                'to' => 'required|date',
            ], ['from' => 'stats.from', 'to' => 'stats.to']);
            return Response::json($this->documents->register(Direction::from($d['direction']), $d['from'], $d['to']));
        } catch (ValidationException | RuleViolation $e) {
            return Response::json(['error' => $this->translator->get('js.errors.validation'), 'errors' => $this->errorsOf($e)], 422);
        }
    }
}
