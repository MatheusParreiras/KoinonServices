<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Mail\Mailer;
use App\Models\EmailChangeToken;
use App\Models\PasswordResetToken;
use App\Models\User;
use finfo;
use PDOException;

/**
 * Self-service for every role (Phase 5): profile, avatar, password and e-mail.
 *
 * OWNERSHIP: every method takes the user id from the caller, and every caller
 * passes Auth::id() (the session's user, re-validated against the database on
 * each request). No method accepts a user id from the request, so one user can
 * never edit another's profile. Role and condominium are not editable here at
 * all: they live in condominium_users and are changed only by a manager.
 */
final class AccountService
{
    public const EMAIL_VALID = 'valid';
    public const EMAIL_EXPIRED = 'expired';
    public const EMAIL_TAKEN = 'taken';
    public const EMAIL_INVALID = 'invalid';

    /** Allowed avatar types, detected from the file CONTENT (finfo), not from the name. */
    private const AVATAR_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly Request $request,
        private readonly User $users = new User(),
        private readonly EmailChangeToken $emailTokens = new EmailChangeToken(),
        private readonly TokenService $tokenService = new TokenService(),
        private readonly RateLimiter $limiter = new RateLimiter(),
        private readonly Mailer $mailer = new Mailer()
    ) {
    }

    /** Name and phone. */
    public function updateProfile(int $userId, string $fullName, ?string $phone): void
    {
        $this->users->updateProfile($userId, $fullName, $phone);
        (new AuditLogger($this->request))->record('account.profile_updated', $userId, null, 'user', $userId);
    }

    /**
     * Replaces the avatar with an uploaded image.
     *
     * The upload is checked by content (finfo + getimagesize), stored under a
     * random name OUTSIDE the web root (storage/uploads/avatars) and only ever
     * served by AccountController::avatar() with a fixed Content-Type. The
     * browser-supplied file name and type are ignored.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @throws BusinessRuleException
     */
    public function replaceAvatar(int $userId, array $file): void
    {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new BusinessRuleException('Não foi possível receber a imagem. Tente novamente.', 422, 'avatar');
        }
        $maxBytes = (int) Config::get('security.avatar_max_bytes', 1_048_576);
        if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
            throw new BusinessRuleException('A imagem deve ter no máximo ' . intdiv($maxBytes, 1024) . ' KB.', 422, 'avatar');
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $size = @getimagesize($file['tmp_name']);
        if (!isset(self::AVATAR_TYPES[$mime]) || $size === false || $size[0] > 4000 || $size[1] > 4000) {
            throw new BusinessRuleException('Envie uma imagem JPG, PNG ou WebP de até 4000×4000 pixels.', 422, 'avatar');
        }

        $relative = 'avatars/' . bin2hex(random_bytes(16)) . '.' . self::AVATAR_TYPES[$mime];
        $directory = self::uploadsPath() . '/avatars';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new BusinessRuleException('Não foi possível salvar a imagem agora.', 500, 'avatar');
        }
        if (!move_uploaded_file($file['tmp_name'], self::uploadsPath() . '/' . $relative)) {
            throw new BusinessRuleException('Não foi possível salvar a imagem agora.', 500, 'avatar');
        }

        $previous = $this->users->find($userId)['avatar_path'] ?? null;
        $this->users->updateAvatar($userId, $relative);
        self::deleteUpload(is_string($previous) ? $previous : null);
        (new AuditLogger($this->request))->record('account.avatar_updated', $userId, null, 'user', $userId);
    }

    /** Removes the avatar (file and column). */
    public function removeAvatar(int $userId): void
    {
        $previous = $this->users->find($userId)['avatar_path'] ?? null;
        $this->users->updateAvatar($userId, null);
        self::deleteUpload(is_string($previous) ? $previous : null);
    }

    /**
     * Absolute path of a user's avatar file, or null. The stored path is
     * re-validated against the exact format this class writes, so a tampered
     * database value cannot point the file server at "../../.env".
     */
    public static function avatarFile(?string $relative): ?string
    {
        if ($relative === null || preg_match('#^avatars/[a-f0-9]{32}\.(jpg|png|webp)$#', $relative) !== 1) {
            return null;
        }
        $path = self::uploadsPath() . '/' . $relative;

        return is_file($path) ? $path : null;
    }

    /**
     * Changes the password after checking the current one.
     *
     * session_version is incremented (all OTHER sessions end); the caller then
     * calls Auth::refreshAfterCredentialChange() so the current session keeps
     * working under a new session id.
     *
     * @throws BusinessRuleException 422 when the current password is wrong.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->find($userId) ?? throw new BusinessRuleException('Conta não encontrada.', 404);
        if ($user['password_hash'] === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            (new AuditLogger($this->request))->record('account.password_change_failed', $userId, null, 'user', $userId);
            throw new BusinessRuleException('A senha atual está incorreta.', 422, 'current_password');
        }
        if (password_verify($newPassword, (string) $user['password_hash'])) {
            throw new BusinessRuleException('A nova senha deve ser diferente da atual.', 422, 'password');
        }

        $hash = password_hash(
            $newPassword,
            Config::get('security.password_algo', PASSWORD_DEFAULT),
            Config::get('security.password_options', [])
        );
        Database::transaction(function () use ($userId, $hash): void {
            $this->users->updatePassword($userId, $hash);
            // Any reset link e-mailed earlier must not be usable to undo this change.
            (new PasswordResetToken())->revokeOutstanding($userId);
            (new AuditLogger($this->request))->record('auth.password_changed', $userId, null, 'user', $userId);
        });

        $this->mailer->sendSecurityNotice(
            (string) $user['email'],
            (string) $user['full_name'],
            'A senha da sua conta no Koinon foi alterada.'
        );
    }

    /**
     * Starts an e-mail change: checks the password, then e-mails a confirmation
     * link to the NEW address. users.email changes only when that link is used.
     *
     * Whether the new address already belongs to another account is NOT
     * revealed here (that would let any user probe which e-mails are
     * registered); it is checked again when the link is confirmed.
     *
     * @throws BusinessRuleException
     */
    public function requestEmailChange(int $userId, string $currentPassword, string $newEmail): void
    {
        $user = $this->users->find($userId) ?? throw new BusinessRuleException('Conta não encontrada.', 404);
        if ($user['password_hash'] === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new BusinessRuleException('A senha atual está incorreta.', 422, 'email_password');
        }
        if ($newEmail === $user['email']) {
            throw new BusinessRuleException('Este já é o seu e-mail atual.', 422, 'new_email');
        }
        if (!$this->limiter->attempt('email_change', ['account' => (string) $userId])) {
            throw new BusinessRuleException('Muitas solicitações. Tente novamente mais tarde.', 429, 'new_email');
        }

        ['raw' => $raw, 'hash' => $hash] = $this->tokenService->generate();
        $ttl = (int) Config::get('security.email_change_ttl_hours', 24) * 60;
        Database::transaction(function () use ($userId, $newEmail, $hash, $ttl): void {
            $this->emailTokens->revokeOutstanding($userId);
            $this->emailTokens->create($userId, $newEmail, $hash, $this->request->ip(), $ttl);
        });
        (new AuditLogger($this->request))->record('account.email_change_requested', $userId, null, 'user', $userId);

        $this->mailer->sendEmailChange($newEmail, (string) $user['full_name'], $raw);
    }

    /** Read-only state of an e-mail change link (GET page). */
    public function inspectEmailChange(string $rawToken): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::EMAIL_INVALID;
        }

        return $this->evaluateEmailToken($this->emailTokens->findByHash($this->tokenService->hash($rawToken)), $rawToken);
    }

    /**
     * Consumes the link and moves the account to the new address.
     *
     * @return string One of the EMAIL_* constants.
     */
    public function confirmEmailChange(string $rawToken): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::EMAIL_INVALID;
        }

        try {
            $result = $this->applyEmailChange($rawToken);
        } catch (PDOException $e) {
            // 1062: someone registered the address between the check and the UPDATE.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return self::EMAIL_TAKEN;
            }
            throw $e;
        }

        [$state, $user] = $result;
        if ($state === self::EMAIL_VALID && is_array($user)) {
            $this->mailer->sendSecurityNotice(
                (string) $user['email'],
                (string) $user['full_name'],
                'O e-mail de acesso da sua conta no Koinon foi alterado. Este endereço não será mais usado para entrar.'
            );
        }

        return $state;
    }

    /**
     * The transactional part of confirmEmailChange().
     *
     * @return array{0: string, 1: array<string, mixed>|null} state and the user row before the change
     */
    private function applyEmailChange(string $rawToken): array
    {
        return Database::transaction(function () use ($rawToken): array {
            $token = $this->emailTokens->findByHash($this->tokenService->hash($rawToken), true);
            $state = $this->evaluateEmailToken($token, $rawToken);
            if ($state !== self::EMAIL_VALID) {
                return [$state, null];
            }

            $userId = (int) $token['user_id'];
            $user = $this->users->findForUpdate($userId);
            if ($user === null || $user['status'] !== 'active') {
                return [self::EMAIL_INVALID, null];
            }
            // The address may have been registered by someone else since the request.
            $owner = $this->users->findByEmail((string) $token['new_email']);
            if ($owner !== null && (int) $owner['id'] !== $userId) {
                $this->emailTokens->revokeOutstanding($userId);

                return [self::EMAIL_TAKEN, null];
            }

            $this->emailTokens->markConsumed((int) $token['id']);
            $this->users->changeEmail($userId, (string) $token['new_email']);
            $this->emailTokens->revokeOutstanding($userId);
            (new AuditLogger($this->request))->record('account.email_changed', $userId, null, 'user', $userId);

            return [self::EMAIL_VALID, $user];
        });
    }

    /** @param array<string, mixed>|null $token */
    private function evaluateEmailToken(?array $token, string $rawToken): string
    {
        if ($token === null || !$this->tokenService->matches($rawToken, (string) $token['token_hash'])) {
            return self::EMAIL_INVALID;
        }
        if ($token['consumed_at'] !== null || $token['revoked_at'] !== null) {
            return self::EMAIL_INVALID;
        }

        return (int) $token['is_expired'] === 1 ? self::EMAIL_EXPIRED : self::EMAIL_VALID;
    }

    private static function uploadsPath(): string
    {
        return BASE_PATH . '/storage/uploads';
    }

    private static function deleteUpload(?string $relative): void
    {
        $path = self::avatarFile($relative);
        if ($path !== null && !@unlink($path)) {
            Logger::warning('Could not delete old avatar', ['path' => $relative]);
        }
    }
}
