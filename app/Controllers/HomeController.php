<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Core\Env;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Translator $translator,
        private readonly Validator $validator,
        private readonly Env $env,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('home/index'));
    }

    public function switchLocale(Request $request): Response
    {
        try {
            $data = $this->validator->validate($request->all(), [
                'locale' => 'required|in:' . implode(',', $this->translator->available()),
            ]);
            $this->session->set('locale', $data['locale']);
        } catch (ValidationException) {
            // Unknown locale: keep the current one.
        }

        $base = rtrim($this->env->get('APP_BASE_PATH', '') ?? '', '/');
        return Response::redirect($base . '/');
    }
}
