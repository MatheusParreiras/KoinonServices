<?php

declare(strict_types=1);

namespace App\Core;

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
    /** @return list<array{key: string, label: string, href: string}> */
    public static function items(): array
    {
        $items = [['key' => 'notices', 'label' => 'Mural de avisos', 'href' => '/dashboard']];

        $modules = [
            ['concierge', 'Portaria', '/concierge', ConciergeController::VIEWERS],
            ['reservations', 'Reservas', '/reservations', ReservationController::VIEWERS],
            ['occurrences', 'Ocorrências', '/occurrences', OccurrenceController::VIEWERS],
            ['finance', 'Financeiro', '/finance', FinanceController::VIEWERS],
        ];
        foreach ($modules as [$key, $label, $href, $roles]) {
            if (Auth::hasRole($roles)) {
                $items[] = ['key' => $key, 'label' => $label, 'href' => $href];
            }
        }

        return $items;
    }
}
