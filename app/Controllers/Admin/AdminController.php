<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Pagination;
use App\Core\Response;
use App\Core\TenantContext;
use App\Services\BusinessRuleException;

/**
 * Base of the Property Manager's administration area (/admin, Phase 5).
 *
 * Every /admin route runs auth → tenant → role:manager (→ csrf on writes), and
 * every action repeats requireRole(self::MANAGERS) as defence in depth.
 *
 * TENANT ISOLATION: the condominium is always TenantContext::id(), which
 * TenantMiddleware resolved from $_SESSION['condominium_id'] and re-validated
 * against an ACTIVE membership in an ACTIVE condominium. No /admin action reads
 * a condominium id from the URL, form or JSON body. Record ids in URLs are only
 * lookup keys, resolved through tenant-scoped models (404 when not ours).
 */
abstract class AdminController extends Controller
{
    /** Only Property Managers administer a condominium; the Super Admin uses /platform. */
    public const MANAGERS = ['manager'];

    /** The session's condominium (never a request value). */
    protected function tenantId(): int
    {
        return TenantContext::id();
    }

    /**
     * Turns a service's BusinessRuleException into the right answer: 404 as a
     * real "not found", anything else as a form/JSON error.
     *
     * @param list<string> $keep
     */
    protected function ruleFailure(BusinessRuleException $e, string $redirectTo, array $keep = []): Response
    {
        if ($e->status() === 404) {
            throw new HttpException(404);
        }

        return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], $redirectTo, $e->status(), $keep);
    }

    /** Pagination of the current request. */
    protected function pagination(int $perPage = 25): Pagination
    {
        return Pagination::fromRequest($this->request, $perPage);
    }
}
