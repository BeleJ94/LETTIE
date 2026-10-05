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
use App\Domain\Auth\Totp;
use App\Domain\RuleViolation;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\ReferenceDataService;

/** "Mon profil": the signed-in user's own account. */
final class ProfileController
{
    use HandlesDomain;

    /** Secret shown to the user, not saved until a code proves the application is set up. */
    private const TOTP_KEY = 'profile.totp_secret';

    public function __construct(
        private readonly AccountService $account,
        private readonly ReferenceDataService $referenceData,
        private readonly AuthService $auth,
        private readonly Validator $validator,
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly View $view,
        private readonly Url $url,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->page($request);
    }

    /** PUT /profile/password */
    public function password(Request $request): Response
    {
        $actor = $this->actor($request);
        try {
            $d = $this->validator->validate($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string',
                'new_password_confirmation' => 'required|string',
            ], [
                'current_password' => 'profile.fields.current_password',
                'new_password' => 'profile.fields.new_password',
                'new_password_confirmation' => 'profile.fields.new_password_confirmation',
            ]);
            if ($d['new_password'] !== $d['new_password_confirmation']) {
                throw RuleViolation::single('new_password_confirmation', 'rules.user.password_confirmation');
            }
            $user = $this->account->changePassword($actor, $d['current_password'], $d['new_password']);
        } catch (ValidationException | RuleViolation $e) {
            return $this->page($request, $this->errorsOf($e));
        }
        // The other sessions of the account are closed; this one goes on.
        $this->auth->keepSession($user);
        $this->session->flash('flash.success', $this->translator->get('profile.password_changed'));
        return Response::redirect($this->url->route($actor->user->mustChangePassword ? 'home' : 'profile'));
    }

    /** POST /profile/2fa/start: shows a new secret to enter in the authenticator application */
    public function totpStart(Request $request): Response
    {
        $this->session->set(self::TOTP_KEY, $this->account->newTotpSecret());
        return Response::redirect($this->url->route('profile') . '#two-factor');
    }

    public function totpCancel(Request $request): Response
    {
        $this->session->remove(self::TOTP_KEY);
        return Response::redirect($this->url->route('profile') . '#two-factor');
    }

    public function totpEnable(Request $request): Response
    {
        $secret = $this->session->get(self::TOTP_KEY);
        if (!is_string($secret)) {
            return Response::redirect($this->url->route('profile'));
        }
        try {
            $this->account->enableTotp($this->actor($request), $secret, (string) $request->post('code', ''));
        } catch (RuleViolation $e) {
            return $this->page($request, $this->errorsOf($e));
        }
        $this->session->remove(self::TOTP_KEY);
        $this->session->flash('flash.success', $this->translator->get('profile.totp.enabled_message'));
        return Response::redirect($this->url->route('profile') . '#two-factor');
    }

    public function totpDisable(Request $request): Response
    {
        try {
            $this->account->disableTotp($this->actor($request), (string) $request->post('totp_password', ''));
        } catch (RuleViolation $e) {
            return $this->page($request, $this->errorsOf($e));
        }
        $this->session->flash('flash.success', $this->translator->get('profile.totp.disabled_message'));
        return Response::redirect($this->url->route('profile') . '#two-factor');
    }

    /** @param array<string, list<string>> $errors */
    private function page(Request $request, array $errors = []): Response
    {
        $user = $this->actor($request)->user;
        return Response::html($this->view->render('profile/show', [
            'user' => $user,
            'siteName' => $this->referenceData->siteName($user->siteId),
            'departmentName' => $user->departmentId !== null ? $this->referenceData->departmentName($user->departmentId) : null,
            'errors' => $errors,
            'totpSecret' => ($secret = $this->session->get(self::TOTP_KEY)) !== null && !$user->totpEnabled ? Totp::grouped((string) $secret) : null,
            'totpUri' => is_string($secret) ? Totp::uri($this->translator->get('app.name'), $user->email, $secret) : null,
        ]), $errors === [] ? 200 : 422);
    }
}
