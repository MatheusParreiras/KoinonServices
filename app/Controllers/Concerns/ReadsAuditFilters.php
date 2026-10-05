<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Core\Request;
use App\Models\AuditLog;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Query-string filters of the two audit log views (Property Manager and Super
 * Admin). Every value is validated: the module against AuditLog::MODULES, dates
 * strictly as Y-m-d, the search text cut to 100 characters (and bound as a
 * LIKE parameter by the model).
 */
trait ReadsAuditFilters
{
    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>} [model filters, echo for the form/pager]
     */
    private function auditFilters(Request $request, DateTimeZone $timezone): array
    {
        $filters = [];
        $query = [];

        $module = $request->queryString('module');
        if (array_key_exists($module, AuditLog::MODULES)) {
            $filters['module'] = $query['module'] = $module;
        }
        $q = mb_substr($request->queryString('q'), 0, 100);
        if ($q !== '') {
            $filters['q'] = $query['q'] = $q;
        }
        // Dates are local days of the viewer's zone, turned into UTC bounds
        // (created_at is UTC): [from 00:00, to + 1 day 00:00).
        foreach (['from' => 'from_utc', 'to' => 'to_utc'] as $key => $bound) {
            $value = $request->queryString($key);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                $query[$key] = $value;
                $edge = $key === 'to' ? $date->modify('+1 day') : $date;
                $filters[$bound] = $edge->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        return [$filters, $query];
    }
}
