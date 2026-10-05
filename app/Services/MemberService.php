<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\Invitation;
use App\Models\Member;
use App\Models\Role;
use App\Models\UnitResident;
use LogicException;

/**
 * A Property Manager's changes to the members of THEIR condominium (Phase 5):
 * role, unit, deactivation and reactivation.
 *
 * Every model used here is tenant-scoped (TenantModel) except Invitation, which
 * receives TenantContext::id() through $condominiumId. The caller has already
 * checked the "manager" role; this class enforces the privilege rules that
 * depend on the target record:
 *
 *  - The new role must be in MANAGEABLE_ROLES (an allowlist in code, compared
 *    as role CODES). A Super Admin is not a role and can never be granted.
 *  - Nobody changes their own role or deactivates themselves (a manager could
 *    otherwise demote the last manager by accident, or escape the audit trail).
 *  - The last active Property Manager can be neither demoted nor deactivated:
 *    the condominium would be left without anyone able to manage it. This is
 *    checked with the managers' rows locked (Member::lockActiveManagerCount).
 */
final class MemberService
{
    /** Roles a Property Manager may grant (other managers of the same condominium included). */
    public const MANAGEABLE_ROLES = ['manager', 'concierge', 'resident'];

    public function __construct(
        private readonly Request $request,
        private readonly int $condominiumId,
        private readonly Member $members = new Member(),
        private readonly Role $roles = new Role(),
        private readonly UnitResident $unitResidents = new UnitResident(),
        private readonly Invitation $invitations = new Invitation()
    ) {
    }

    /**
     * Changes a member's role and/or unit.
     *
     * @param int|null $unitId Already validated by the caller to be an active unit of this tenant.
     * @throws BusinessRuleException
     */
    public function update(int $userId, string $roleCode, ?int $unitId, string $relationship, int $actorId): void
    {
        if (!in_array($roleCode, self::MANAGEABLE_ROLES, true)) {
            throw new BusinessRuleException('Perfil inválido.', 422, 'role');
        }
        if ($roleCode === 'resident' && $unitId === null) {
            throw new BusinessRuleException('Moradores precisam de uma unidade.', 422, 'unit_id');
        }
        $roleIds = $this->roles->idsByCodes(self::MANAGEABLE_ROLES);

        Database::transaction(function () use ($userId, $roleCode, $unitId, $relationship, $actorId, $roleIds): void {
            $member = $this->members->lockByUser($userId) ?? throw new BusinessRuleException('Usuário não encontrado.', 404);
            $audit = new AuditLogger($this->request);

            if ($member['role_code'] !== $roleCode) {
                if ($userId === $actorId) {
                    throw new BusinessRuleException('Você não pode alterar o seu próprio perfil.', 403, 'role');
                }
                if (!in_array($member['role_code'], self::MANAGEABLE_ROLES, true)) {
                    throw new LogicException('Unexpected role on a membership.');
                }
                if ($member['role_code'] === 'manager' && $member['status'] === 'active') {
                    $this->guardLastManager($roleIds['manager']);
                }
                $this->members->changeRole($userId, $roleIds[$roleCode]);
                $audit->tenant('member.role_changed', 'user', $userId, ['from' => $member['role_code'], 'to' => $roleCode]);
            }

            $current = $this->unitResidents->currentUnits([$userId])[$userId] ?? null;
            if (($current['unit_id'] ?? null) !== $unitId || ($unitId !== null && ($current['relationship'] ?? null) !== $relationship)) {
                $this->unitResidents->assign($userId, $unitId, $relationship);
                $audit->tenant('member.unit_changed', 'user', $userId, [
                    'from_unit_id' => $current['unit_id'] ?? null,
                    'to_unit_id'   => $unitId,
                    'relationship' => $unitId === null ? null : $relationship,
                ]);
            }
        });
    }

    /**
     * Deactivates a membership (or cancels a pending invitation). History is
     * kept; the person's session ends on their next request (Auth/TenantMiddleware).
     *
     * @throws BusinessRuleException
     */
    public function deactivate(int $userId, int $actorId): void
    {
        if ($userId === $actorId) {
            throw new BusinessRuleException('Você não pode desativar a sua própria conta.', 403);
        }
        $managerRoleId = $this->roles->idByCode('manager') ?? throw new LogicException('Role manager missing.');

        Database::transaction(function () use ($userId, $actorId, $managerRoleId): void {
            $member = $this->members->lockByUser($userId) ?? throw new BusinessRuleException('Usuário não encontrado.', 404);
            if ($member['status'] === 'inactive') {
                throw new BusinessRuleException('Este usuário já está desativado.', 409);
            }
            if (!in_array($member['status'], ['active', 'invited'], true)) {
                throw new BusinessRuleException('Este cadastro não pode ser desativado por aqui.', 409);
            }
            if ($member['role_code'] === 'manager' && $member['status'] === 'active') {
                $this->guardLastManager($managerRoleId);
            }

            $this->members->deactivate($userId, $actorId);
            // A pending invitation link must stop working too.
            $this->invitations->revokeOutstanding($this->condominiumId, $userId);
            (new AuditLogger($this->request))->tenant('member.deactivated', 'user', $userId, [
                'role'            => $member['role_code'],
                'previous_status' => $member['status'],
            ]);
        });
    }

    /**
     * Reactivates a membership. Returns the new status ('active', or 'invited'
     * when the account was never activated and needs a new invitation).
     *
     * @throws BusinessRuleException
     */
    public function reactivate(int $userId, int $actorId): string
    {
        return Database::transaction(function () use ($userId, $actorId): string {
            $member = $this->members->lockByUser($userId) ?? throw new BusinessRuleException('Usuário não encontrado.', 404);
            if ($member['status'] !== 'inactive') {
                throw new BusinessRuleException('Este usuário não está desativado.', 409);
            }
            $account = $this->members->findByUser($userId);
            // A globally blocked/deleted account stays out, whatever this tenant decides.
            if (!in_array($account['user_status'] ?? null, ['active', 'pending_verification'], true)) {
                throw new BusinessRuleException('Esta conta está bloqueada na plataforma. Fale com o suporte.', 409);
            }
            $ready = $account['user_status'] === 'active';

            $this->members->reactivate($userId, $actorId, $ready);
            (new AuditLogger($this->request))->tenant('member.reactivated', 'user', $userId, ['account_ready' => $ready]);

            return $ready ? 'active' : 'invited';
        });
    }

    /** @throws BusinessRuleException 409 when only one active manager is left. */
    private function guardLastManager(int $managerRoleId): void
    {
        if ($this->members->lockActiveManagerCount($managerRoleId) <= 1) {
            throw new BusinessRuleException(
                'Este é o último síndico ativo do condomínio. Convide ou promova outro síndico antes.',
                409
            );
        }
    }
}
