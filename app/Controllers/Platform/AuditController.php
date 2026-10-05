<?php

declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Controllers\Concerns\ReadsAuditFilters;
use App\Core\Controller;
use App\Core\Pagination;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\Condominium;
use DateTimeZone;

/**
 * Platform-wide audit log (Super Admin, Phase 5): every entry, including
 * platform-level ones (condominium_id NULL), optionally narrowed to one
 * condominium. Times are shown in UTC because entries span every tenant's zone.
 */
final class AuditController extends Controller
{
    use ReadsAuditFilters;

    /** GET /platform/audit?condominium_id=&module=&q=&from=&to=&page= */
    public function index(): Response
    {
        [$filters, $query] = $this->auditFilters($this->request, new DateTimeZone('UTC'));

        $condominiums = (new Condominium())->options();
        $condominiumId = (int) $this->request->queryString('condominium_id');
        if (isset($condominiums[$condominiumId])) {   // allowlist: existing condominiums only
            $filters['condominium_id'] = $condominiumId;
            $query['condominium_id'] = (string) $condominiumId;
        }

        $log = new AuditLog();
        $pagination = Pagination::fromRequest($this->request, 50)->withTotal($log->count(null, $filters));

        return $this->view('admin/audit/index', [
            'title'        => 'Auditoria da plataforma',
            'activeNav'    => 'platform-audit',
            'entries'      => $log->search(null, $filters, $pagination),
            'modules'      => AuditLog::MODULES,
            'query'        => $query,
            'pagination'   => $pagination->toArray(),
            'basePath'     => '/platform/audit',
            'showTenant'   => true,
            'condominiums' => $condominiums,
        ]);
    }
}
