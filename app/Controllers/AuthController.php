<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\Url;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Domain\Auth\AccountLockedException;
use App\Domain\Auth\InvalidCredentialsException;
use App\Middleware\Authenticate;
use App\Services\AuthService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
        private readonly Validator $validator,
        private readonly Translator $translator,
        private readonly View $view,
        private readonly Url $url,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        return Response::html($this->view->render('auth/login', [
            'error' => $this->session->getFlash('auth.error'),
            'email' => $this->session->getFlash('auth.email', ''),
        ]));
    }

    public function login(Request $request): Response
    {
        $email = $request->post('email');
        try {
            $data = $this->validator->validate($request->all(), [
                'email' => 'required|string|max:190',
                'password' => 'required|string|max:4096',
            ]);
            $this->auth->attempt($data['email'], (string) $request->post('password'), $request->ip());
        } catch (ValidationException | InvalidCredentialsException) {
            return $this->backToLogin($this->translator->get('auth.failed'), $email);
        } catch (AccountLockedException $e) {
            $minutes = $e->minutesRemaining($this->auth->now());
            return $this->backToLogin($this->translator->get('auth.locked', ['minutes' => $minutes]), $email);
        }

        $intended = $this->session->get(Authenticate::INTENDED_KEY);
        $this->session->remove(Authenticate::INTENDED_KEY);
        $target = is_string($intended) && \App\Core\Url::isSafeLocalPath($intended)
            ? $this->url->to($intended)
            : $this->url->route('home');
        return Response::redirect($target);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::redirect($this->url->route('login'));
    }

    private function backToLogin(string $error, mixed $email): Response
    {
        $this->session->flash('auth.error', $error);
        $this->session->flash('auth.email', is_string($email) ? mb_substr($email, 0, 190) : '');
        return Response::redirect($this->url->route('login'));
    }
}
