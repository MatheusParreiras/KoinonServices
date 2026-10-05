<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Invitation;
use App\Models\Member;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Services\BusinessRuleException;
use App\Services\InvitationService;
use App\Services\MemberService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * User management of the current condominium (Property Manager, Phase 5).
 *
 * {id} in these routes is a USER id. It is resolved with Member::findByUser(),
 * which is tenant-scoped: the id of someone who belongs only to another
 * condominium answers 404, exactly like an id that does not exist.
 */
final class UserController extends AdminController
{
    public const RELATIONSHIP_LABELS = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'dependent' => 'Dependente'];

    private const KEEP = ['full_name', 'email', 'role', 'unit_id', 'relationship'];

    /** GET /admin/users: the list is filled by users.js from GET /admin/users/search. */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);

        return $this->view('admin/users/index', [
            'title'         => 'Usuários',
            'activeNav'     => 'admin-users',
            'units'         => (new Unit())->active(),
            'roles'         => self::roleOptions(),
            'relationships' => self::RELATIONSHIP_LABELS,
            'scripts'       => ['js/admin/users.js'],
        ]);
    }

    /**
     * GET /admin/users/search?q=&role=&unit_id=&status=&page= (JSON).
     * Every filter is validated against an allowlist; anything else is ignored.
     */
    public function search(): Response
    {
        $this->requireRole(self::MANAGERS);

        $filters = [];
        $q = mb_substr($this->request->queryString('q'), 0, 100);
        if ($q !== '') {
            $filters['q'] = $q;
        }
        $role = $this->request->queryString('role');
        if (in_array($role, MemberService::MANAGEABLE_ROLES, true)) {
            $filters['role'] = $role;
        }
        $status = $this->request->queryString('status');
        if (in_array($status, Member::STATUS_FILTERS, true)) {
            $filters['status'] = $status;
        }
        $unitId = $this->request->queryString('unit_id');
        if (preg_match('/^[1-9]\d{0,9}$/', $unitId) === 1) {
            $filters['unit_id'] = (int) $unitId;   // tenant-scoped inside the query
        }

        $members = new Member();
        $pagination = $this->pagination(20)->withTotal($members->count($filters));
        $rows = $members->search($filters, $pagination);

        $userIds = array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
        $units = (new UnitResident())->currentUnits($userIds);
        $invitations = (new Invitation())->latestForUsers($this->tenantId(), $userIds);

        return $this->success([
            'users'      => array_map(fn (array $r): array => $this->present($r, $units, $invitations), $rows),
            'pagination' => $pagination->toArray(),
        ]);
    }

    /**
     * POST /admin/users/invitations: invites a resident, concierge or manager.
     *
     * Order: CSRF (middleware) → role → validation (role allowlist, unit belongs
     * to THIS condominium) → service (transaction + audit) → e-mail.
     */
    public function invite(): Response
    {
        $this->requireRole(self::MANAGERS);

        $v = new Validator($this->request);
        $name = $v->string('full_name', 'Nome', 3, 150);
        $email = $v->email('email');
        // Allowlist of role CODES: "super_admin" or any unknown value fails here.
        $role = $v->enum('role', 'Perfil', MemberService::MANAGEABLE_ROLES);
        $unitId = $v->filled('unit_id') ? $v->id('unit_id', 'a unidade') : null;
        $relationship = $v->filled('relationship')
            ? $v->enum('relationship', 'Vínculo', InvitationService::RELATIONSHIPS)
            : 'owner';

        if ($role === 'resident' && $unitId === null && !isset($v->errors()['unit_id'])) {
            $v->addError('unit_id', 'Selecione a unidade do morador.');
        }
        // Ownership: the unit must be an active unit of the session's condominium.
        if ($unitId !== null && (new Unit())->findActive($unitId) === null) {
            $v->addError('unit_id', 'Unidade inválida.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/admin/users', 422, self::KEEP);
        }

        try {
            $result = (new InvitationService($this->request))->invite(
                $this->tenantId(),            // session tenant, never a request value
                (string) $email,
                (string) $name,
                (string) $role,
                $unitId,
                $unitId === null ? null : $relationship,
                (int) Auth::id()
            );
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, '/admin/users', self::KEEP);
        }

        $message = $result['email_sent']
            ? 'Convite enviado para ' . $email . '.'
            : 'Usuário criado, mas o e-mail não pôde ser enviado agora. Use "Reenviar convite" em instantes.';

        return $this->done($message, '/admin/users', ['user_id' => $result['user_id']], 201);
    }

    /** GET /admin/users/{id}/edit */
    public function edit(string $id): Response
    {
        $this->requireRole(self::MANAGERS);

        $member = (new Member())->findByUser((int) $id) ?? throw new HttpException(404);
        $unit = (new UnitResident())->currentUnits([(int) $id])[(int) $id] ?? null;

        return $this->view('admin/users/edit', [
            'title'         => 'Editar usuário',
            'activeNav'     => 'admin-users',
            'member'        => $member,
            'currentUnit'   => $unit,
            'units'         => (new Unit())->active(),
            'roles'         => self::roleOptions(),
            'relationships' => self::RELATIONSHIP_LABELS,
            'isSelf'        => (int) $id === (int) Auth::id(),
            'scripts'       => ['js/admin/confirm.js'],
        ]);
    }

    /**
     * POST /admin/users/{id}: role and unit. Name and e-mail are NOT editable by
     * a manager: they belong to the person's global account (they may live in
     * other condominiums too) and are changed only by the person (/account).
     */
    public function update(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $userId = (int) $id;
        (new Member())->findByUser($userId) ?? throw new HttpException(404);
        $back = "/admin/users/{$userId}/edit";

        $v = new Validator($this->request);
        $role = $v->enum('role', 'Perfil', MemberService::MANAGEABLE_ROLES);
        $unitId = $v->filled('unit_id') ? $v->id('unit_id', 'a unidade') : null;
        $relationship = $v->filled('relationship') ? $v->enum('relationship', 'Vínculo', InvitationService::RELATIONSHIPS) : 'owner';
        if ($unitId !== null && (new Unit())->findActive($unitId) === null) {
            $v->addError('unit_id', 'Unidade inválida.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new MemberService($this->request, $this->tenantId()))
                ->update($userId, (string) $role, $unitId, (string) $relationship, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, $back);
        }

        return $this->done('Usuário atualizado.', $back);
    }

    /** POST /admin/users/{id}/deactivate (form or fetch) */
    public function deactivate(string $id): Response
    {
        $this->requireRole(self::MANAGERS);

        try {
            (new MemberService($this->request, $this->tenantId()))->deactivate((int) $id, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, "/admin/users/{$id}/edit");
        }

        return $this->done('Usuário desativado. O acesso dele termina na próxima ação.', "/admin/users/{$id}/edit", [
            'user' => ['user_id' => (int) $id, 'status' => 'inactive'],
        ]);
    }

    /** POST /admin/users/{id}/reactivate (form or fetch) */
    public function reactivate(string $id): Response
    {
        $this->requireRole(self::MANAGERS);

        try {
            $status = (new MemberService($this->request, $this->tenantId()))->reactivate((int) $id, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, "/admin/users/{$id}/edit");
        }

        $message = $status === 'active'
            ? 'Usuário reativado.'
            : 'Cadastro reativado. A pessoa ainda não aceitou o convite: reenvie-o.';

        return $this->done($message, "/admin/users/{$id}/edit", ['user' => ['user_id' => (int) $id, 'status' => $status]]);
    }

    /** POST /admin/users/{id}/invitation/resend (form or fetch) */
    public function resendInvitation(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        (new Member())->findByUser((int) $id) ?? throw new HttpException(404);

        try {
            $sent = (new InvitationService($this->request))->resend($this->tenantId(), (int) $id, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, "/admin/users/{$id}/edit");
        }

        return $this->done(
            $sent ? 'Convite reenviado.' : 'Novo convite gerado, mas o e-mail não pôde ser enviado agora.',
            "/admin/users/{$id}/edit",
            ['user' => ['user_id' => (int) $id, 'status' => 'invited']]
        );
    }

    /** @return array<string, string> code => label, for <select> */
    private static function roleOptions(): array
    {
        $options = [];
        foreach (MemberService::MANAGEABLE_ROLES as $code) {
            $options[$code] = Auth::labelFor($code);
        }

        return $options;
    }

    /**
     * Shapes one member for JSON: raw text only (the browser inserts it with
     * textContent), no internal columns.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $units
     * @param array<int, array<string, mixed>> $invitations
     * @return array<string, mixed>
     */
    private function present(array $row, array $units, array $invitations): array
    {
        $userId = (int) $row['user_id'];
        $status = (string) $row['status'];
        if ($status === 'invited' && (($invitations[$userId]['is_expired'] ?? 1) === 1)) {
            $status = 'invitation_expired';
        }

        return [
            'user_id'       => $userId,
            'full_name'     => (string) $row['full_name'],
            'email'         => (string) $row['email'],
            'role'          => (string) $row['role_code'],
            'role_label'    => Auth::labelFor((string) $row['role_code']),
            'status'        => $status,
            'unit_label'    => $units[$userId]['unit_label'] ?? null,
            'last_login_at' => $row['last_login_at'] === null
                ? null
                : (new DateTimeImmutable((string) $row['last_login_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'is_self'       => $userId === (int) Auth::id(),
        ];
    }
}
