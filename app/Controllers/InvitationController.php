<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\InvitationService;

/**
 * Invitation landing page and acceptance (Phase 5). Works logged out.
 */
final class InvitationController extends Controller
{
    /** GET /account/invitation?token=... Read-only (mail scanners open links). */
    public function show(): Response
    {
        $token = $this->request->query('token', '');
        $token = is_string($token) ? $token : '';
        $result = (new InvitationService($this->request))->inspect($token);

        return $this->page($token, $result['state'], $result['invitation'], []);
    }

    /** POST /account/invitation */
    public function accept(): Response
    {
        $token = $this->request->string('token');
        $service = new InvitationService($this->request);
        $inspection = $service->inspect($token);
        if ($inspection['state'] !== InvitationService::ACCEPT_VALID) {
            return $this->page($token, $inspection['state'], $inspection['invitation'], []);
        }

        $passwordHash = null;
        if ((int) $inspection['invitation']['needs_password'] === 1) {
            $v = new Validator($this->request);
            $password = $v->newPassword();
            if ($v->fails()) {
                return $this->page($token, $inspection['state'], $inspection['invitation'], $v->errors(), 422);
            }
            $passwordHash = password_hash(
                (string) $password,
                Config::get('security.password_algo', PASSWORD_DEFAULT),
                Config::get('security.password_options', [])
            );
        }

        $state = $service->accept($token, $passwordHash);
        if ($state !== InvitationService::ACCEPT_VALID) {
            return $this->page($token, $state, $inspection['invitation'], []);
        }

        // Whoever was logged in in this browser is logged out: the invitee
        // should start a clean session as themself.
        if (Auth::check()) {
            Auth::logout();
        }
        Session::flash('success', 'Convite aceito! Entre com seu e-mail e senha para acessar o condomínio.');

        return $this->redirect('/login');
    }

    /**
     * @param array<string, mixed>|null $invitation
     * @param array<string, string>     $errors
     */
    private function page(string $token, string $state, ?array $invitation, array $errors, int $status = 200): Response
    {
        $usable = in_array($state, [InvitationService::ACCEPT_VALID, InvitationService::ACCEPT_PASSWORD_REQUIRED], true);

        return $this->view('account/invitation', [
            'title'           => 'Aceitar convite',
            'state'           => $state,
            'token'           => $token,
            // Details are shown only for a usable token: an expired or revoked
            // link reveals nothing about the account or the condominium.
            'condominiumName' => $usable ? (string) $invitation['condominium_name'] : null,
            'roleLabel'       => $usable ? Auth::labelFor((string) $invitation['role_code']) : null,
            'email'           => $usable ? (string) $invitation['email'] : null,
            'needsPassword'   => $usable && (int) $invitation['needs_password'] === 1,
            'passwordMin'     => (int) Config::get('security.password_min', 10),
            'errors'          => $errors,
        ], $status, 'layouts/auth')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
