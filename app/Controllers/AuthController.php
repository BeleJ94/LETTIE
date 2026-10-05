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
use App\Domain\Auth\SecondFactorRequiredException;
use App\Middleware\Authenticate;
use App\Core\Env;
use App\Core\Mailer;
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
        private readonly Mailer $mailer,
        private readonly Env $env,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        return Response::html($this->view->render('auth/login', [
            'error' => $this->session->getFlash('auth.error'),
            'email' => $this->session->getFlash('auth.email', ''),
            'notice' => $this->session->getFlash('auth.notice'),
            // "Mot de passe oublié" needs a mail server and the public address of the application.
            'canRecover' => $this->mailer->isConfigured() && trim((string) $this->env->get('APP_URL', '')) !== '',
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
        } catch (SecondFactorRequiredException) {
            return Response::redirect($this->url->route('login.code'));
        }

        return $this->enter();
    }

    /** GET /login/code: second step, for the accounts with two-factor authentication */
    public function showCode(Request $request): Response
    {
        if (!$this->auth->hasPendingSecondFactor()) {
            return Response::redirect($this->url->route('login'));
        }
        return Response::html($this->view->render('auth/code', ['error' => $this->session->getFlash('auth.error')]));
    }

    public function code(Request $request): Response
    {
        try {
            $this->auth->completeSecondFactor((string) $request->post('code', ''), $request->ip());
        } catch (InvalidCredentialsException) {
            if (!$this->auth->hasPendingSecondFactor()) {
                return $this->backToLogin($this->translator->get('auth.code.expired'), '');
            }
            $this->session->flash('auth.error', $this->translator->get('auth.code.failed'));
            return Response::redirect($this->url->route('login.code'));
        } catch (AccountLockedException $e) {
            return $this->backToLogin($this->translator->get('auth.locked', ['minutes' => $e->minutesRemaining($this->auth->now())]), '');
        }
        return $this->enter();
    }

    /** After a complete sign-in: the page asked before it, or the home page. */
    private function enter(): Response
    {
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
