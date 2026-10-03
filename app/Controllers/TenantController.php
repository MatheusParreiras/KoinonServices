<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Models\AuditLog;
use App\Models\Condominium;
use App\Models\Membership;

/**
 * Choosing the condominium to work in: the ONLY way the tenant of a session changes.
 */
final class TenantController extends Controller
{
    /** GET /select-condominium */
    public function select(): Response
    {
        $userId = (int) Auth::id();
        $options = Auth::isSuperAdmin()
            ? array_map(
                static fn (array $c): array => ['id' => (int) $c['id'], 'name' => $c['name'], 'detail' => $c['city']],
                (new Condominium())->allActive()
            )
            : array_map(
                static fn (array $m): array => [
                    'id'     => (int) $m['condominium_id'],
                    'name'   => $m['condominium_name'],
                    'detail' => $m['role_name'],
                ],
                (new Membership())->activeForUser($userId)
            );

        return $this->view('tenant/select', [
            'title'   => 'Escolher condomínio',
            'options' => $options,
        ], layout: 'layouts/auth');
    }

    /**
     * POST /select-condominium
     *
     * The submitted id is only a REQUEST. It is accepted after checking an
     * active membership (tenant users) or an active condominium (Super Admin).
     * Otherwise the answer is 404, the same as for an id that does not exist.
     */
    public function choose(): Response
    {
        $condominiumId = (int) $this->request->string('condominium_id');

        if (Auth::isSuperAdmin()) {
            $condominium = (new Condominium())->findActive($condominiumId) ?? throw new HttpException(404);
            Session::regenerate();
            Session::set('admin_condominium_id', (int) $condominium['id']);
            // Super Admin access to tenant data is always traceable (Phase 1, NFR-SEC-13).
            (new AuditLog())->record('support.tenant_entered', $this->request, Auth::id(), (int) $condominium['id']);

            return $this->redirect('/dashboard');
        }

        $membership = (new Membership())->findActive((int) Auth::id(), $condominiumId)
            ?? throw new HttpException(404);
        Session::regenerate();
        Auth::enterMembership($membership);

        return $this->redirect('/dashboard');
    }
}
