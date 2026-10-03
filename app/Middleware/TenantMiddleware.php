<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Models\Condominium;
use App\Models\Membership;

/**
 * Route spec "tenant" (always after "auth"): resolves which condominium this
 * request operates on and stores it in TenantContext.
 *
 * The tenant comes ONLY from the server-side session, and is re-validated
 * against the database on every request:
 *  - Tenant users: the session's condominium_id must still be an ACTIVE
 *    membership in an ACTIVE condominium. The role is re-read at the same time,
 *    so a demotion or deactivation by a manager takes effect on the next click.
 *  - Super Admin: has no membership (condominium_id is NULL). They pick a
 *    condominium in the selector; that choice is kept separately in
 *    admin_condominium_id and checked to still be an active condominium.
 *
 * No URL, form field or JSON property can change the tenant. The only way is
 * the POST /select-condominium endpoint, which checks the membership first.
 */
final class TenantMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::isSuperAdmin()) {
            $condominiumId = Session::get('admin_condominium_id');
            $condominium = is_int($condominiumId) ? (new Condominium())->findActive($condominiumId) : null;
            if ($condominium === null) {
                return $this->chooseTenant($request);
            }
            TenantContext::set((int) $condominium['id'], (string) $condominium['name'], (string) $condominium['timezone']);

            return $next($request);
        }

        $condominiumId = Session::get('condominium_id');
        $userId = (int) Auth::id();
        $membership = is_int($condominiumId) ? (new Membership())->findActive($userId, $condominiumId) : null;
        if ($membership === null) {
            Auth::enterMembership(null);

            return $this->chooseTenant($request);
        }

        Auth::enterMembership($membership); // refresh role from the database
        TenantContext::set(
            (int) $membership['condominium_id'],
            (string) $membership['condominium_name'],
            (string) $membership['condominium_timezone']
        );

        return $next($request);
    }

    private function chooseTenant(Request $request): Response
    {
        if ($request->wantsJson()) {
            throw new HttpException(403, 'Selecione um condomínio para continuar.');
        }

        return Response::redirect('/select-condominium');
    }
}
