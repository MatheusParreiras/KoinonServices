<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\PasswordResetService;
use App\Services\RateLimiter;

/**
 * Forgot password / reset password (Phase 5). Works logged out.
 */
final class PasswordResetController extends Controller
{
    /** The only answer "forgot password" ever gives (no account enumeration). */
    private const SENT_MESSAGE = 'Se houver uma conta ativa com este e-mail, enviamos um link para redefinir a senha. Verifique sua caixa de entrada.';

    /** GET /account/forgot-password */
    public function showForgot(): Response
    {
        return $this->view('account/forgot-password', ['title' => 'Esqueci minha senha'], layout: 'layouts/auth');
    }

    /**
     * POST /account/forgot-password
     *
     * Same message and same redirect whatever happened: unknown e-mail,
     * inactive account, rate-limited, or link sent. A malformed address is not
     * even reported as invalid, for the same reason.
     */
    public function sendLink(): Response
    {
        (new PasswordResetService($this->request))->request($this->request->string('email'));
        Session::flash('success', self::SENT_MESSAGE);

        return $this->redirect('/login');
    }

    /** GET /account/reset-password?token=... Read-only: never consumes the token. */
    public function showReset(): Response
    {
        $token = $this->request->query('token', '');
        $token = is_string($token) ? $token : '';

        return $this->resetPage($token, (new PasswordResetService($this->request))->inspect($token), []);
    }

    /** POST /account/reset-password */
    public function reset(): Response
    {
        $token = $this->request->string('token');
        if (!(new RateLimiter())->attempt('password_reset', ['ip' => $this->request->ip()])) {
            Session::flash('error', 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');

            return $this->resetPage($token, PasswordResetService::VALID, [], 429);
        }

        $service = new PasswordResetService($this->request);
        $state = $service->inspect($token);
        if ($state !== PasswordResetService::VALID) {
            return $this->resetPage($token, $state, []);
        }

        $v = new Validator($this->request);
        $password = $v->newPassword();
        if ($v->fails()) {
            return $this->resetPage($token, $state, $v->errors(), 422);
        }

        $state = $service->reset($token, (string) $password);
        if ($state !== PasswordResetService::VALID) {
            return $this->resetPage($token, $state, []);
        }

        Session::flash('success', 'Senha redefinida. Entre com a nova senha; as sessões abertas da sua conta foram encerradas.');

        return $this->redirect('/login');
    }

    /** @param array<string, string> $errors */
    private function resetPage(string $token, string $state, array $errors, int $status = 200): Response
    {
        return $this->view('account/reset-password', [
            'title'  => 'Redefinir senha',
            'state'  => $state,
            'token'  => $token,
            'errors' => $errors,
        ], $status, 'layouts/auth')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
