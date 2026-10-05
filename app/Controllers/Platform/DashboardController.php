<?php

declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\Condominium;

/**
 * Platform overview of the Super Admin (/platform, Phase 5): condominiums by
 * status, members per condominium and the latest audit events.
 */
final class DashboardController extends Controller
{
    /** GET /platform */
    public function index(): Response
    {
        $condominiums = new Condominium();

        return $this->view('platform/dashboard', [
            'title'        => 'Plataforma',
            'activeNav'    => 'platform',
            'counts'       => $condominiums->countsByStatus(),
            'statuses'     => Condominium::STATUSES,
            'condominiums' => $condominiums->largest(10),
            'events'       => (new AuditLog())->latest(15),
        ]);
    }
}
