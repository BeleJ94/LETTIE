<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\HandlesDomain;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\Url;
use App\Core\View;
use App\Domain\Auth\User;
use App\Domain\RuleViolation;
use App\Services\AuthService;
use App\Services\PasswordResetService;

/** "Mot de passe oublié": request of a link by e-mail, then choice of a new password. */
final class PasswordResetController
{
    use HandlesDomain;

    public function __construct(
        private readonly PasswordResetService $resets,
        private readonly AuthService $auth,
        private readonly Env $env,
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly View $view,
        private readonly Url $url,
    ) {
    }

    public function showForgot(Request $request): Response
    {
        $this->assertAvailable();
        return Response::html($this->view->render('auth/forgot', ['sent' => $this->session->getFlash('reset.sent') === true]));
    }

    public function forgot(Request $request): Response
    {
        $this->assertAvailable();
        $email = $request->post('email');
        if (is_string($email) && $email !== '') {
            $this->resets->request(mb_substr($email, 0, 190), $request->ip(), fn (User $user, string $token): array => [
                'subject' => $this->translator->get('reset.mail.subject', ['app' => $this->translator->get('app.name')]),
                'text' => $this->translator->get('reset.mail.text', [
                    'name' => $user->firstName,
                    'app' => $this->translator->get('app.name'),
                    'link' => $this->link($token),
                    'minutes' => PasswordResetService::LINK_MINUTES,
                ]),
            ]);
        }
        // The same answer whether the address is known or not.
        $this->session->flash('reset.sent', true);
        return Response::redirect($this->url->route('password.forgot'));
    }

    public function showReset(Request $request): Response
    {
        $this->assertAvailable();
        return $this->resetPage((string) $request->param('token'));
    }

    public function reset(Request $request): Response
    {
        $this->assertAvailable();
        $token = (string) $request->param('token');
        $password = (string) $request->post('new_password', '');
        try {
            if ($password !== (string) $request->post('new_password_confirmation', '')) {
                throw RuleViolation::single('new_password_confirmation', 'rules.user.password_confirmation');
            }
            $this->resets->reset($token, $password, $request->ip(), $request->header('User-Agent'));
        } catch (RuleViolation $e) {
            return $this->resetPage($token, $this->errorsOf($e));
        }
        $this->session->flash('auth.notice', $this->translator->get('reset.done'));
        return Response::redirect($this->url->route('login'));
    }

    /** @param array<string, list<string>> $errors */
    private function resetPage(string $token, array $errors = []): Response
    {
        $valid = $this->resets->userFor($token) !== null;
        return Response::html($this->view->render('auth/reset', [
            'token' => $token,
            'valid' => $valid,
            'errors' => $errors,
        ]), $errors === [] && $valid ? 200 : 422);
    }

    /** Absolute address of the link: the application itself only knows paths (APP_URL in .env). */
    private function link(string $token): string
    {
        return rtrim((string) $this->env->get('APP_URL', ''), '/') . $this->url->route('password.reset', ['token' => $token]);
    }

    /** Without a mail server and a public address, the feature does not exist. */
    private function assertAvailable(): void
    {
        if (!$this->resets->isAvailable() || trim((string) $this->env->get('APP_URL', '')) === '') {
            throw HttpException::notFound();
        }
    }
}
