<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Core\Logger;
use App\Core\View;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * The only class that talks to PHPMailer (Phase 1, NFR-LIB-02).
 *
 * PHPMailer is used because PHP's mail() cannot do SMTP authentication, TLS,
 * multipart HTML/text bodies or proper header encoding, and gets no useful error
 * back. Everything else depends on this adapter, so the library stays replaceable.
 */
final class Mailer
{
    /**
     * Sends one message. Returns false (after logging) instead of throwing, so a
     * mail outage never turns a successful action into an error page.
     */
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        $config = Config::get('mail');

        if (!$config['enabled']) {
            return $this->writeDevelopmentLog($toEmail, $subject, $text);
        }

        if (!class_exists(PHPMailer::class)) {
            Logger::error('PHPMailer is not installed; run "composer install".');

            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) $config['host'];
            $mail->Port = (int) $config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = (string) $config['username'];
            $mail->Password = (string) $config['password'];
            $mail->SMTPSecure = $config['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 10; // seconds; never keep the user waiting on a dead SMTP server
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom((string) $config['from_address'], (string) $config['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;

            $mail->send();

            return true;
        } catch (PHPMailerException $e) {
            Logger::error('E-mail delivery failed', ['to' => $toEmail, 'error' => $mail->ErrorInfo]);

            return false;
        }
    }

    /** Sends the account activation link. */
    public function sendVerification(string $toEmail, string $toName, string $rawToken): bool
    {
        $link = Config::get('app.url') . '/verify-email?token=' . $rawToken;
        $hours = (int) Config::get('security.verification_ttl_hours', 24);

        $html = View::render('emails/verify', ['name' => $toName, 'link' => $link, 'hours' => $hours], null);
        $text = "Olá, {$toName}!\n\n"
            . "Confirme seu e-mail para ativar sua conta no Koinon:\n{$link}\n\n"
            . "O link vale por {$hours} horas e só pode ser usado uma vez.\n"
            . "Se você não criou esta conta, ignore este e-mail.\n";

        return $this->send($toEmail, $toName, 'Confirme seu e-mail - Koinon', $html, $text);
    }

    /**
     * Invitation to join a condominium (Phase 5). The link is built from APP_URL
     * (config app.url), never from the request's Host header: a forged Host
     * would otherwise turn the e-mail into a link to an attacker's site that
     * receives the token.
     */
    public function sendInvitation(
        string $toEmail,
        string $toName,
        string $condominiumName,
        string $roleLabel,
        string $inviterName,
        string $rawToken,
        bool $needsPassword
    ): bool {
        $link = Config::get('app.url') . '/account/invitation?token=' . $rawToken;
        $hours = (int) Config::get('security.invitation_ttl_hours', 72);
        $data = [
            'name'            => $toName,
            'condominiumName' => $condominiumName,
            'roleLabel'       => $roleLabel,
            'inviterName'     => $inviterName,
            'link'            => $link,
            'hours'           => $hours,
            'needsPassword'   => $needsPassword,
        ];

        $html = View::render('emails/invitation', $data, 'layouts/email');
        $text = "Olá, {$toName}!\n\n"
            . "{$inviterName} convidou você para acessar o condomínio {$condominiumName} no Koinon como {$roleLabel}.\n\n"
            . ($needsPassword
                ? "Aceite o convite e crie sua senha:\n"
                : "Aceite o convite (depois, entre com o e-mail e a senha que você já usa no Koinon):\n")
            . "{$link}\n\n"
            . "O convite vale por {$hours} horas e só pode ser usado uma vez.\n"
            . "Se você não esperava este convite, ignore este e-mail.\n";

        return $this->send($toEmail, $toName, "Convite para {$condominiumName} - Koinon", $html, $text);
    }

    /** Password reset link (Phase 5). Same APP_URL rule as sendInvitation(). */
    public function sendPasswordReset(string $toEmail, string $toName, string $rawToken): bool
    {
        $link = Config::get('app.url') . '/account/reset-password?token=' . $rawToken;
        $minutes = (int) Config::get('security.password_reset_ttl_minutes', 60);

        $html = View::render('emails/password-reset', ['name' => $toName, 'link' => $link, 'minutes' => $minutes], 'layouts/email');
        $text = "Olá, {$toName}!\n\n"
            . "Recebemos um pedido para redefinir a senha da sua conta no Koinon:\n{$link}\n\n"
            . "O link vale por {$minutes} minutos e só pode ser usado uma vez.\n"
            . "Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma.\n";

        return $this->send($toEmail, $toName, 'Redefinição de senha - Koinon', $html, $text);
    }

    /** Confirmation link sent to a NEW e-mail address (Phase 5). */
    public function sendEmailChange(string $toEmail, string $toName, string $rawToken): bool
    {
        $link = Config::get('app.url') . '/account/email/confirm?token=' . $rawToken;
        $hours = (int) Config::get('security.email_change_ttl_hours', 24);

        $html = View::render('emails/email-change', [
            'name'     => $toName,
            'newEmail' => $toEmail,
            'link'     => $link,
            'hours'    => $hours,
        ], 'layouts/email');
        $text = "Olá, {$toName}!\n\n"
            . "Foi solicitado que o e-mail de acesso da sua conta no Koinon passe a ser {$toEmail}.\n"
            . "Confirme que este endereço é seu:\n{$link}\n\n"
            . "O link vale por {$hours} horas e só pode ser usado uma vez. Até a confirmação, o e-mail antigo continua valendo.\n"
            . "Se você não pediu esta alteração, ignore este e-mail.\n";

        return $this->send($toEmail, $toName, 'Confirme seu novo e-mail - Koinon', $html, $text);
    }

    /**
     * Security notice to the OLD address after an e-mail change or a password
     * change, so the owner notices if someone else did it.
     */
    public function sendSecurityNotice(string $toEmail, string $toName, string $what): bool
    {
        $text = "Olá, {$toName}!\n\n"
            . "{$what}\n\n"
            . "Se foi você, nada mais é necessário. Se não foi, redefina sua senha imediatamente em "
            . Config::get('app.url') . "/account/forgot-password e avise a administração do condomínio.\n";
        $html = '<p>Olá, ' . e($toName) . '!</p>'
            . '<p>' . e($what) . '</p>'
            . '<p style="font-size:13px;color:#5f6b7a;">Se foi você, nada mais é necessário. Se não foi, '
            . 'redefina sua senha imediatamente em <span style="word-break:break-all;">'
            . e(Config::get('app.url') . '/account/forgot-password')
            . '</span> e avise a administração do condomínio.</p>';

        return $this->send($toEmail, $toName, 'Alteração na sua conta - Koinon', View::render('emails/raw', ['html' => $html], 'layouts/email'), $text);
    }

    /**
     * Tells a resident that a package is waiting at the desk, with the pickup code.
     * All values are escaped with e(): carrier and description are typed by staff
     * and could contain HTML.
     */
    public function sendPackageNotice(
        string $toEmail,
        string $toName,
        string $condominiumName,
        string $unitLabel,
        ?string $carrier,
        string $pickupCode
    ): bool {
        $from = $carrier !== null && $carrier !== '' ? " ({$carrier})" : '';
        $text = "Olá, {$toName}!\n\n"
            . "Chegou uma encomenda{$from} para a unidade {$unitLabel} no {$condominiumName}.\n"
            . "Retire na portaria informando o código: {$pickupCode}\n\n"
            . "Não compartilhe este código com quem não deve retirar a encomenda.\n";
        $html = '<p>Olá, ' . e($toName) . '!</p>'
            . '<p>Chegou uma encomenda' . e($from) . ' para a unidade <strong>' . e($unitLabel)
            . '</strong> no ' . e($condominiumName) . '.</p>'
            . '<p>Retire na portaria informando o código: <strong style="font-size:20px;letter-spacing:3px;">'
            . e($pickupCode) . '</strong></p>'
            . '<p style="color:#5f6b7a;font-size:13px;">Não compartilhe este código com quem não deve retirar a encomenda.</p>';

        return $this->send($toEmail, $toName, 'Encomenda na portaria - Koinon', $html, $text);
    }

    /**
     * Placeholder transport for development (MAIL_ENABLED=false): writes the
     * message to storage/logs/mail-dev.log so the activation flow can be tested
     * without an SMTP server.
     *
     * Refused unless APP_ENV=local, because that log contains raw activation
     * tokens, which must never be persisted in production (NFR-SEC-08).
     */
    private function writeDevelopmentLog(string $toEmail, string $subject, string $text): bool
    {
        if (Config::get('app.env') !== 'local') {
            Logger::error('MAIL_ENABLED=false outside APP_ENV=local: e-mail not sent.', ['to' => $toEmail]);

            return false;
        }

        $entry = sprintf("==== %s\nTo: %s\nSubject: %s\n\n%s\n", gmdate('c'), $toEmail, $subject, $text);
        file_put_contents(BASE_PATH . '/storage/logs/mail-dev.log', $entry, FILE_APPEND | LOCK_EX);

        return true;
    }
}
