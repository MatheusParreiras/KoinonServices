<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\LoginResult;
use App\Services\RateLimiter;

/**
 * Login and logout.
 */
final class AuthController extends Controller
{
    /** One message for every failure cause, so the form reveals nothing about accounts. */
    private const GENERIC_FAILURE = 'E-mail ou senha inválidos.';

    /** GET /login */
    public function showLogin(): Response
    {
        return $this->view('auth/login', [
            'title'           => 'Entrar',
            'email'           => Session::pullFlash('old_email') ?? '',
            'unverifiedEmail' => Session::pullFlash('unverified_email'),
        ], layout: 'layouts/auth');
    }

    /** POST /login (CSRF-protected by the route). */
    public function login(): Response
    {
        $email = $this->request->string('email');

        // Phase 5: database rate limit per IP and per submitted e-mail, checked
        // before the password. Counted for unknown e-mails too, so the answer
        // (429) does not reveal whether an account exists. The per-account
        // lockout (5 wrong passwords) still applies on top of this.
        $limited = !(new RateLimiter())->attempt('login', [
            'ip'      => $this->request->ip(),
            'account' => User::normalizeEmail($email),
        ]);
        if ($limited) {
            (new AuditLogger($this->request))->record('auth.login_rate_limited', null, null, null, null, [
                'email' => User::normalizeEmail($email),
            ]);
            Session::flash('error', 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.');

            // Rendered directly (not redirected) so the response carries 429.
            return $this->view('auth/login', [
                'title'           => 'Entrar',
                'email'           => $email,
                'unverifiedEmail' => null,
            ], 429, 'layouts/auth');
        }

        $result = (new AuthService())->attempt($email, $this->request->string('password'), $this->request);

        if ($result->status === LoginResult::INVALID) {
            Session::flash('error', self::GENERIC_FAILURE);
            Session::flash('old_email', $email);

            return $this->redirect('/login');
        }

        if ($result->status === LoginResult::UNVERIFIED) {
            // Only reached with the correct password, so this tells the owner,
            // not a stranger, that the account is waiting for confirmation.
            Session::flash('warning', 'Você precisa confirmar seu e-mail antes de entrar. Verifique sua caixa de entrada.');
            Session::flash('unverified_email', (string) $result->user['email']);
            Session::flash('old_email', $email);

            return $this->redirect('/login');
        }

        $user = $result->user;

        // Super Admin: global, no membership. Chooses a condominium to manage.
        if ((bool) $user['is_super_admin']) {
            Auth::login($user, null);

            return $this->redirect('/select-condominium');
        }

        $memberships = (new Membership())->activeForUser((int) $user['id']);
        if ($memberships === []) {
            Session::flash('error', 'Sua conta ainda não tem acesso ativo a nenhum condomínio. Aguarde a aprovação da administração.');

            return $this->redirect('/login');
        }

        // Exactly one condominium: enter it. Several: let the user choose.
        $single = count($memberships) === 1 ? $memberships[0] : null;
        Auth::login($user, $single);

        return $this->redirect($single !== null ? '/dashboard' : '/select-condominium');
    }

    /** POST /logout. A POST with CSRF token, so another site cannot log users out. */
    public function logout(): Response
    {
        Auth::logout();
        Session::flash('success', 'Você saiu com segurança.');

        return $this->redirect('/login');
    }
}
