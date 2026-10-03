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
