<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Services\EmailVerificationService;
use App\Services\VerificationResult;

/**
 * E-mail verification: link landing page, activation and resend.
 */
final class VerificationController extends Controller
{
    /**
     * GET /verify-email?token=...
     *
     * Only displays a confirmation button; nothing is consumed on GET because
     * e-mail security scanners open links automatically and would otherwise
     * burn the single-use token before the user clicks it.
     */
    public function show(): Response
    {
        $token = (string) $this->request->query('token', '');
        $result = (new EmailVerificationService())->inspect($token);

        return $this->verificationPage($token, $result, []);
    }

    /** POST /verify-email: consumes the token and activates the account. */
    public function verify(): Response
    {
        $token = $this->request->string('token');
        $service = new EmailVerificationService();
        $inspection = $service->inspect($token);

        $passwordHash = null;
        if ($inspection->isValid() && $inspection->needsPassword()) {
            $errors = $this->validateNewPassword();
            if ($errors !== []) {
                return $this->verificationPage($token, $inspection, $errors, 422);
            }
            $passwordHash = password_hash(
                $this->request->string('password'),
                Config::get('security.password_algo', PASSWORD_DEFAULT),
                Config::get('security.password_options', [])
            );
        }

        $result = $service->verify($token, $passwordHash, $this->request);
        if (!$result->isValid()) {
            return $this->verificationPage($token, $result, []);
        }

        Session::flash('success', 'E-mail confirmado! Sua conta está ativa, você já pode entrar.');

        return $this->redirect('/login');
    }

    /** GET /verify-email/resend */
    public function showResend(): Response
    {
        return $this->view('auth/resend', [
            'title' => 'Reenviar link de ativação',
            'email' => (string) $this->request->query('email', ''),
        ], layout: 'layouts/auth');
    }

    /**
     * POST /verify-email/resend. Always answers with the same message, whether
     * or not the e-mail exists, is pending, or was throttled.
     */
    public function resend(): Response
    {
        (new EmailVerificationService())->resend($this->request->string('email'), $this->request);
        Session::flash('success', 'Se houver uma conta aguardando confirmação para este e-mail, enviamos um novo link de ativação.');

        return $this->redirect('/login');
    }

    /** @return array<string, string> Field => message. */
    private function validateNewPassword(): array
    {
        $password = $this->request->string('password');
        $min = (int) Config::get('security.password_min', 10);
        $max = (int) Config::get('security.password_max', 128);

        if (mb_strlen($password) < $min || mb_strlen($password) > $max) {
            return ['password' => "A senha deve ter entre {$min} e {$max} caracteres."];
        }
        if (!hash_equals($password, $this->request->string('password_confirmation'))) {
            return ['password_confirmation' => 'As senhas não conferem.'];
        }

        return [];
    }

    /** @param array<string, string> $errors */
    private function verificationPage(string $token, VerificationResult $result, array $errors, int $status = 200): Response
    {
        return $this->view('auth/verify', [
            'title'         => 'Confirmar e-mail',
            'state'         => $result->state,
            'token'         => $token,
            'needsPassword' => $result->needsPassword(),
            'passwordMin'   => (int) Config::get('security.password_min', 10),
            'errors'        => $errors,
        ], $status, 'layouts/auth')
            // The token is in this page's URL: never leak it to other sites via Referer.
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
