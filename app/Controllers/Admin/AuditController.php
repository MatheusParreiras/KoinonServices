<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Concerns\ReadsAuditFilters;
use App\Core\Response;
use App\Core\TenantContext;
use App\Models\AuditLog;

/**
 * Audit log of the current condominium (Property Manager, Phase 5).
 *
 * TENANT ISOLATION: AuditLog::search() receives TenantContext::id() as a
 * mandatory condition, so only this condominium's entries are listed.
 * Platform-level entries (logins, password resets: condominium_id NULL) and
 * other tenants' entries are never shown here.
 */
final class AuditController extends AdminController
{
    use ReadsAuditFilters;

    /** GET /admin/audit?module=&q=&from=&to=&page= */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$filters, $query] = $this->auditFilters($this->request, TenantContext::timezone());

        $log = new AuditLog();
        $pagination = $this->pagination(50)->withTotal($log->count($this->tenantId(), $filters));

        return $this->view('admin/audit/index', [
            'title'      => 'Auditoria',
            'activeNav'  => 'admin-audit',
            'entries'    => $log->search($this->tenantId(), $filters, $pagination),
            'modules'    => AuditLog::MODULES,
            'query'      => $query,
            'pagination' => $pagination->toArray(),
            'basePath'   => '/admin/audit',
            'showTenant' => false,
            'condominiums' => [],
        ]);
    }
}
