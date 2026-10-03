<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * The condominium (tenant) the current request operates on.
 *
 * Set exactly once per request by TenantMiddleware, from server-side session
 * state that was validated against the database: never from a URL, form field,
 * JSON body or cookie. TenantModel reads it to scope every query.
 *
 * It fails closed: asking for the id before it is set throws, so a route that
 * forgot the "tenant" middleware breaks loudly instead of running unscoped queries.
 */
final class TenantContext
{
    private static ?int $condominiumId = null;
    private static ?string $condominiumName = null;
    private static string $timezone = 'UTC';

    public static function set(int $condominiumId, string $condominiumName, string $timezone = 'UTC'): void
    {
        self::$condominiumId = $condominiumId;
        self::$condominiumName = $condominiumName;
        self::$timezone = in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    /** @throws LogicException when no tenant has been resolved for this request. */
    public static function id(): int
    {
        if (self::$condominiumId === null) {
            throw new LogicException('Tenant context is not set: the route is missing the "tenant" middleware.');
        }

        return self::$condominiumId;
    }

    public static function has(): bool
    {
        return self::$condominiumId !== null;
    }

    /** Display name of the current condominium, or null outside tenant routes. */
    public static function name(): ?string
    {
        return self::$condominiumName;
    }

    /** The condominium's time zone (condominiums.timezone), e.g. "America/Sao_Paulo". */
    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::$timezone);
    }

    /**
     * Current local time of the condominium. Reservation dates/times and invoice
     * due dates are local wall-clock values (Phase 1, A-09), so "today" and
     * "in the past" must be judged in the condominium's zone, not in UTC.
     */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }

    /** Today's local date as Y-m-d. */
    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }
}
