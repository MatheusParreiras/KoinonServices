<?php

declare(strict_types=1);

namespace App\Core;

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

    public static function set(int $condominiumId, string $condominiumName): void
    {
        self::$condominiumId = $condominiumId;
        self::$condominiumName = $condominiumName;
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
}
