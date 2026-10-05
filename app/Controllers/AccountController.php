<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AccountService;
use App\Services\BusinessRuleException;

/**
 * "Minha conta" for every role (Phase 5): profile, avatar, password, e-mail.
 *
 * OWNERSHIP: every action works on Auth::id(), the session's user re-validated
 * against the database on each request. There is no user id in these URLs or
 * forms, so a user can only ever change their own account. Role and
 * condominium are not editable here (they belong to the manager's screens).
 */
final class AccountController extends Controller
{
    /** GET /account */
    public function show(): Response
    {
        $user = $this->user();

        return $this->view('account/index', [
            'title'       => 'Minha conta',
            'activeNav'   => 'account',
            'account'     => $user,
            'hasAvatar'   => AccountService::avatarFile($user['avatar_path']) !== null,
            'passwordMin' => (int) Config::get('security.password_min', 10),
        ]);
    }

    /** POST /account/profile (multipart: the avatar is optional) */
    public function updateProfile(): Response
    {
        $userId = (int) Auth::id();
        $v = new Validator($this->request);
        $name = $v->string('full_name', 'Nome', 3, 150);
        $phone = $v->phone('phone');
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/account', 422, ['full_name', 'phone']);
        }

        $service = new AccountService($this->request);
        $service->updateProfile($userId, (string) $name, $phone);

        try {
            $avatar = $this->request->file('avatar');
            if ($avatar !== null) {
                $service->replaceAvatar($userId, $avatar);
            } elseif ($this->request->boolean('remove_avatar')) {
                $service->removeAvatar($userId);
            }
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'avatar' => $e->getMessage()], '/account', $e->status());
        }

        return $this->done('Perfil atualizado.', '/account');
    }

    /**
     * GET /account/avatar: the current user's own avatar.
     *
     * Served from storage/ (outside the web root) with the Content-Type of the
     * stored extension, nosniff (set globally) and a restrictive CSP, so even a
     * crafted image cannot be interpreted as HTML or script.
     */
    public function avatar(): Response
    {
        $path = AccountService::avatarFile($this->user()['avatar_path']) ?? throw new HttpException(404);
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

        return Response::file((string) file_get_contents($path), $types[pathinfo($path, PATHINFO_EXTENSION)])
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, max-age=300');
    }

    /** POST /account/password */
    public function changePassword(): Response
    {
        $userId = (int) Auth::id();
        $v = new Validator($this->request);
        $current = $this->request->string('current_password');
        if ($current === '') {
            $v->addError('current_password', 'Informe a senha atual.');
        }
        $new = $v->newPassword();
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/account');
        }

        try {
            (new AccountService($this->request))->changePassword($userId, $current, (string) $new);
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/account', $e->status());
        }

        // session_version was incremented: every OTHER session of this account is
        // now dead. This one continues under a new session id and CSRF token.
        Auth::refreshAfterCredentialChange($userId);

        return $this->done('Senha alterada. As outras sessões abertas da sua conta foram encerradas.', '/account');
    }

    /** POST /account/email: e-mails a confirmation link to the new address. */
    public function requestEmailChange(): Response
    {
        $v = new Validator($this->request);
        $email = $v->email('new_email', 'Novo e-mail');
        $password = $this->request->string('email_password');
        if ($password === '') {
            $v->addError('email_password', 'Informe sua senha.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/account', 422, ['new_email']);
        }

        try {
            (new AccountService($this->request))->requestEmailChange((int) Auth::id(), $password, (string) $email);
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/account', $e->status(), ['new_email']);
        }

        return $this->done("Enviamos um link de confirmação para {$email}. O e-mail só muda depois da confirmação.", '/account');
    }

    /**
     * GET /account/email/confirm?token=... (works logged out: the link may be
     * opened on another device). Read-only: shows a confirmation button.
     */
    public function showEmailConfirmation(): Response
    {
        $token = $this->request->query('token', '');
        $token = is_string($token) ? $token : '';
        $state = (new AccountService($this->request))->inspectEmailChange($token);

        return $this->emailConfirmationPage($token, $state);
    }

    /** POST /account/email/confirm */
    public function confirmEmail(): Response
    {
        $token = $this->request->string('token');
        $state = (new AccountService($this->request))->confirmEmailChange($token);
        if ($state !== AccountService::EMAIL_VALID) {
            return $this->emailConfirmationPage($token, $state);
        }

        // The login identifier changed and session_version was incremented, so
        // every session (including this browser's, if any) ends.
        Auth::logout();
        Session::flash('success', 'E-mail alterado. Entre novamente usando o novo endereço.');

        return $this->redirect('/login');
    }

    private function emailConfirmationPage(string $token, string $state): Response
    {
        return $this->view('account/email-confirm', [
            'title' => 'Confirmar novo e-mail',
            'state' => $state,
            'token' => $token,
        ], layout: 'layouts/auth')
            // The token is in the URL: never leak it to other sites via Referer.
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
