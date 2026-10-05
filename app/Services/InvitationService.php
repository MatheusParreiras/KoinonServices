<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\TenantContext;
use App\Mail\Mailer;
use App\Models\Condominium;
use App\Models\EmailVerificationToken;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Models\User;
use LogicException;
use PDOException;

/**
 * Invitation lifecycle (Phase 5): invite, resend, accept.
 *
 * WHERE THE CONDOMINIUM ID COMES FROM. Every public method takes it explicitly:
 *  - Admin\UserController passes TenantContext::id(), i.e. the session's
 *    condominium re-validated by TenantMiddleware. Never a request value.
 *  - Platform\CondominiumController passes the id from the URL AFTER loading the
 *    condominium (Condominium::find) and checking it exists; that Super Admin
 *    action is audited with the condominium id.
 *  - accept() takes no id at all: it comes from the invitation row the token
 *    points to.
 *
 * PRIVILEGE: the role is a role CODE checked against ASSIGNABLE_ROLES here, and
 * the controllers narrow it further (a Property Manager's allowlist, the
 * platform's "manager only"). A Super Admin can never be created through an
 * invitation: that is users.is_super_admin, which no code path here writes.
 *
 * A new e-mail gets a users row in status 'pending_verification' with no
 * password. An e-mail that already has an account gets only the membership: the
 * invitation never touches the existing account's name or password.
 */
final class InvitationService
{
    /** Every role an invitation can grant. */
    public const ASSIGNABLE_ROLES = ['manager', 'concierge', 'resident'];

    public const RELATIONSHIPS = ['owner', 'tenant', 'dependent'];

    public const ACCEPT_VALID = 'valid';
    public const ACCEPT_EXPIRED = 'expired';
    public const ACCEPT_USED = 'already_used';
    public const ACCEPT_INVALID = 'invalid';
    public const ACCEPT_PASSWORD_REQUIRED = 'password_required';

    public function __construct(
        private readonly Request $request,
        private readonly User $users = new User(),
        private readonly Membership $memberships = new Membership(),
        private readonly Invitation $invitations = new Invitation(),
        private readonly Role $roles = new Role(),
        private readonly TokenService $tokenService = new TokenService(),
        private readonly RateLimiter $limiter = new RateLimiter(),
        private readonly Mailer $mailer = new Mailer()
    ) {
    }

    /**
     * Invites $email into a condominium with a role (and, for residents, a unit).
     *
     * One transaction: user (if new) + membership + unit link + invitation +
     * audit entry. The e-mail is sent after the commit.
     *
     * @param int|null    $unitId       Must be a unit of $condominiumId; only allowed in a
     *                                  tenant request (TenantContext::id() === $condominiumId).
     * @return array{user_id: int, email_sent: bool}
     * @throws BusinessRuleException 409 when the e-mail already belongs to this condominium.
     */
    public function invite(
        int $condominiumId,
        string $email,
        string $fullName,
        string $roleCode,
        ?int $unitId,
        ?string $relationship,
        int $actorId
    ): array {
        if (!in_array($roleCode, self::ASSIGNABLE_ROLES, true)) {
            throw new LogicException('Role is not assignable through an invitation.');
        }
        if ($unitId !== null && (!TenantContext::has() || TenantContext::id() !== $condominiumId)) {
            // Unit links use the tenant-scoped UnitResident model; refuse rather than
            // write a link under a tenant the request is not operating on.
            throw new LogicException('A unit can only be linked inside its own tenant context.');
        }
        $roleId = $this->roles->idByCode($roleCode) ?? throw new LogicException("Role {$roleCode} missing.");
        $condominium = (new Condominium())->find($condominiumId) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
        $email = User::normalizeEmail($email);
        ['raw' => $raw, 'hash' => $hash] = $this->tokenService->generate();

        try {
            $user = Database::transaction(function () use (
                $condominiumId, $email, $fullName, $roleId, $roleCode, $unitId, $relationship, $actorId, $hash
            ): array {
                $user = $this->users->findByEmail($email);
                $isNew = $user === null;
                if ($isNew) {
                    $userId = $this->users->insert([
                        'full_name'     => $fullName,
                        'email'         => $email,
                        'password_hash' => null,
                        'status'        => 'pending_verification',
                    ]);
                    $user = (array) $this->users->find($userId);
                } elseif (!in_array($user['status'], ['active', 'pending_verification'], true)) {
                    // Blocked/deleted accounts cannot be revived by an invitation.
                    throw new BusinessRuleException('Não foi possível convidar este e-mail.', 409, 'email');
                }
                $userId = (int) $user['id'];

                if ($this->memberships->exists($userId, $condominiumId)) {
                    throw new BusinessRuleException(
                        'Este e-mail já está vinculado a este condomínio. Reative ou reenvie o convite pela lista de usuários.',
                        409,
                        'email'
                    );
                }

                $this->memberships->createInvited($condominiumId, $userId, $roleId);
                if ($unitId !== null) {
                    (new UnitResident())->assign($userId, $unitId, $relationship ?? 'owner');
                }
                $this->invitations->create(
                    $condominiumId,
                    $userId,
                    $hash,
                    $actorId,
                    $this->request->ip(),
                    (int) Config::get('security.invitation_ttl_hours', 72)
                );

                (new AuditLogger($this->request))->record('invitation.created', $actorId, $condominiumId, 'user', $userId, [
                    'role'        => $roleCode,
                    'unit_id'     => $unitId,
                    'new_account' => $isNew,
                ]);

                return $user;
            });
        } catch (PDOException $e) {
            // 1062: the same e-mail was invited by a concurrent request.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new BusinessRuleException('Este e-mail acabou de ser convidado. Atualize a lista.', 409, 'email');
            }
            throw $e;
        }

        $sent = $this->sendEmail($user, (string) $condominium['name'], $roleCode, $raw);

        return ['user_id' => (int) $user['id'], 'email_sent' => $sent];
    }

    /**
     * E-mails a fresh invitation link (the previous one stops working).
     *
     * Throttled three ways: per actor and per IP (database rate limiter) and per
     * invitation (cooldown + daily cap), so a manager's account cannot be used
     * to flood someone's inbox.
     *
     * @throws BusinessRuleException 404 not a member, 409 not pending, 429 throttled.
     */
    public function resend(int $condominiumId, int $userId, int $actorId): bool
    {
        if (!$this->limiter->attempt('invitation_resend', ['ip' => $this->request->ip(), 'account' => (string) $actorId])) {
            throw new BusinessRuleException('Muitos reenvios em pouco tempo. Aguarde alguns minutos.', 429);
        }
        $cooldown = (int) Config::get('security.invitation_resend_cooldown', 60);
        $maxPerDay = (int) Config::get('security.invitation_max_per_day', 5);
        $sinceLast = $this->invitations->secondsSinceLast($condominiumId, $userId);
        if (($sinceLast !== null && $sinceLast < $cooldown) || $this->invitations->countSince($condominiumId, $userId, 24) >= $maxPerDay) {
            throw new BusinessRuleException('Este convite foi enviado há pouco tempo ou atingiu o limite diário de envios.', 429);
        }

        $condominium = (new Condominium())->find($condominiumId) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
        ['raw' => $raw, 'hash' => $hash] = $this->tokenService->generate();

        [$user, $roleCode] = Database::transaction(function () use ($condominiumId, $userId, $actorId, $hash): array {
            $membership = $this->memberships->lockFor($condominiumId, $userId)
                ?? throw new BusinessRuleException('Usuário não encontrado.', 404);
            if ($membership['status'] !== 'invited') {
                throw new BusinessRuleException('Este usuário não tem convite pendente.', 409);
            }

            $this->invitations->revokeOutstanding($condominiumId, $userId);
            $this->invitations->create(
                $condominiumId,
                $userId,
                $hash,
                $actorId,
                $this->request->ip(),
                (int) Config::get('security.invitation_ttl_hours', 72)
            );
            (new AuditLogger($this->request))->record('invitation.resent', $actorId, $condominiumId, 'user', $userId);

            return [(array) $this->users->find($userId), (string) $this->roles->codeById((int) $membership['role_id'])];
        });

        return $this->sendEmail($user, (string) $condominium['name'], $roleCode, $raw);
    }

    /**
     * Read-only view of a token for the GET landing page.
     *
     * @return array{state: string, invitation: array<string, mixed>|null}
     */
    public function inspect(string $rawToken): array
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return ['state' => self::ACCEPT_INVALID, 'invitation' => null];
        }
        $invitation = $this->invitations->findByHash($this->tokenService->hash($rawToken));

        return ['state' => $this->evaluate($invitation, $rawToken), 'invitation' => $invitation];
    }

    /**
     * Accepts an invitation.
     *
     * For a NEW account the invitee chooses a password here, and the e-mail is
     * marked verified through the Phase 2 logic (User::markEmailVerified, the
     * same call EmailVerificationService::verify() makes): receiving the token
     * proves control of the address. An EXISTING account keeps its password;
     * $passwordHash is ignored for it, so this page can never reset a password.
     *
     * @param string|null $passwordHash Already validated and hashed; required for new accounts.
     * @return string One of the ACCEPT_* constants.
     */
    public function accept(string $rawToken, ?string $passwordHash): string
    {
        if (!$this->tokenService->isWellFormed($rawToken)) {
            return self::ACCEPT_INVALID;
        }
        if (!$this->limiter->attempt('invitation_accept', ['ip' => $this->request->ip()])) {
            return self::ACCEPT_INVALID;
        }

        return Database::transaction(function () use ($rawToken, $passwordHash): string {
            // Lock order: invitation → membership → user (always the same order,
            // so concurrent accepts cannot deadlock).
            $invitation = $this->invitations->lockByHash($this->tokenService->hash($rawToken));
            if ($invitation === null || !$this->tokenService->matches($rawToken, (string) $invitation['token_hash'])) {
                return self::ACCEPT_INVALID;
            }
            $condominiumId = (int) $invitation['condominium_id'];
            $userId = (int) $invitation['user_id'];
            $membership = $this->memberships->lockFor($condominiumId, $userId);
            $user = $this->users->findForUpdate($userId);
            $condominium = (new Condominium())->find($condominiumId);

            $context = $invitation + [
                'membership_status'  => $membership['status'] ?? null,
                'user_status'        => $user['status'] ?? null,
                'condominium_status' => $condominium['status'] ?? null,
            ];
            $state = $this->evaluate($context, $rawToken);
            if ($state !== self::ACCEPT_VALID) {
                return $state;
            }

            $isNewAccount = $user['password_hash'] === null;
            if ($isNewAccount && $passwordHash === null) {
                return self::ACCEPT_PASSWORD_REQUIRED;
            }

            $this->invitations->markConsumed((int) $invitation['id']);
            $this->invitations->revokeOutstanding($condominiumId, $userId);
            $this->memberships->activateInvited($condominiumId, $userId, (int) $invitation['invited_by_user_id']);

            if ($user['email_verified_at'] === null || $user['status'] !== 'active') {
                // Phase 2 verification logic, reused: verified + active (+ password
                // for a new account; COALESCE keeps an existing one).
                $this->users->markEmailVerified($userId, $isNewAccount ? $passwordHash : null);
                (new EmailVerificationToken())->revokeOutstanding($userId);
            }

            (new AuditLogger($this->request))->record('invitation.accepted', $userId, $condominiumId, 'user', $userId, [
                'new_account' => $isNewAccount,
            ]);

            return self::ACCEPT_VALID;
        });
    }

    /** @param array<string, mixed>|null $invitation */
    private function evaluate(?array $invitation, string $rawToken): string
    {
        if ($invitation === null || !$this->tokenService->matches($rawToken, (string) $invitation['token_hash'])) {
            return self::ACCEPT_INVALID;
        }
        if ($invitation['consumed_at'] !== null) {
            return self::ACCEPT_USED;
        }
        if ($invitation['revoked_at'] !== null
            || $invitation['membership_status'] !== 'invited'
            || !in_array($invitation['user_status'], ['active', 'pending_verification'], true)
            || $invitation['condominium_status'] !== 'active'
        ) {
            return self::ACCEPT_INVALID;
        }

        return (int) $invitation['is_expired'] === 1 ? self::ACCEPT_EXPIRED : self::ACCEPT_VALID;
    }

    /** @param array<string, mixed> $user */
    private function sendEmail(array $user, string $condominiumName, string $roleCode, string $rawToken): bool
    {
        $inviter = Auth::user();

        return $this->mailer->sendInvitation(
            (string) $user['email'],
            (string) $user['full_name'],
            $condominiumName,
            Auth::labelFor($roleCode),
            (string) ($inviter['full_name'] ?? 'A administração'),
            $rawToken,
            $user['password_hash'] === null
        );
    }
}
