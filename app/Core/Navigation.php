<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\Admin\AdminController;
use App\Controllers\Community\CommunityController;
use App\Controllers\ConciergeController;
use App\Controllers\FinanceController;
use App\Controllers\OccurrenceController;
use App\Controllers\ReservationController;

/**
 * Sidebar entries visible to the current role.
 *
 * Built from the same role constants the routes use, so a link is shown
 * exactly when the route would let the user in. This is convenience only;
 * the routes and controllers enforce access.
 */
final class Navigation
{
    /**
     * Entries in display order. "section" (optional) starts a labelled group in
     * the sidebar (Phase 5: Plataforma, Administração).
     *
     * @return list<array{key: string, label: string, href: string, section?: string}>
     */
    public static function items(): array
    {
        $items = [];

        // Phase 5: the Super Admin's platform area comes first.
        if (Auth::isSuperAdmin()) {
            $items[] = ['key' => 'platform', 'label' => 'Visão geral', 'href' => '/platform', 'section' => 'Plataforma'];
            $items[] = ['key' => 'platform-condominiums', 'label' => 'Condomínios', 'href' => '/platform/condominiums'];
            $items[] = ['key' => 'platform-audit', 'label' => 'Auditoria', 'href' => '/platform/audit'];
        }

        $items[] = ['key' => 'notices', 'label' => 'Mural de avisos', 'href' => '/dashboard', 'section' => 'Condomínio'];

        $modules = [
            ['concierge', 'Portaria', '/concierge', ConciergeController::VIEWERS],
            ['reservations', 'Reservas', '/reservations', ReservationController::VIEWERS],
            ['occurrences', 'Ocorrências', '/occurrences', OccurrenceController::VIEWERS],
            ['finance', 'Financeiro', '/finance', FinanceController::VIEWERS],
            ['community', 'Comunidade', '/community', CommunityController::MEMBERS],
        ];
        foreach ($modules as [$key, $label, $href, $roles]) {
            if (Auth::hasRole($roles)) {
                $items[] = ['key' => $key, 'label' => $label, 'href' => $href];
            }
        }

        // Super Admin is not a community member but may review the moderation queue.
        if (!Auth::hasRole(CommunityController::MEMBERS) && Auth::hasRole(CommunityController::MODERATION_VIEWERS)) {
            $items[] = ['key' => 'community', 'label' => 'Moderação', 'href' => '/community/moderation'];
        }

        // Phase 5: the Property Manager's administration area.
        if (Auth::hasRole(AdminController::MANAGERS)) {
            $admin = [
                ['admin-users', 'Usuários', '/admin/users'],
                ['admin-units', 'Unidades', '/admin/units'],
                ['admin-areas', 'Áreas comuns', '/admin/common-areas'],
                ['admin-notices', 'Avisos', '/admin/notices'],
                ['admin-invoices', 'Cobranças', '/admin/finance/invoices'],
                ['admin-finance', 'Relatórios financeiros', '/admin/finance/reports'],
                ['admin-audit', 'Auditoria', '/admin/audit'],
            ];
            foreach ($admin as $i => [$key, $label, $href]) {
                $items[] = ['key' => $key, 'label' => $label, 'href' => $href] + ($i === 0 ? ['section' => 'Administração'] : []);
            }
        }

        $items[] = ['key' => 'account', 'label' => 'Minha conta', 'href' => '/account', 'section' => 'Conta'];

        return $items;
    }
}
