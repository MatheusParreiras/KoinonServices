<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Outcome of AuthService::attempt().
 *
 * INVALID covers unknown e-mail, wrong password, locked, blocked and deleted
 * accounts alike, so the login form shows one generic message for all of them
 * and nobody can probe which e-mails are registered.
 */
final class LoginResult
{
    public const SUCCESS = 'success';
    public const INVALID = 'invalid';
    public const UNVERIFIED = 'unverified';

    /** @param array<string, mixed>|null $user */
    private function __construct(
        public readonly string $status,
        public readonly ?array $user = null
    ) {
    }

    /** @param array<string, mixed> $user */
    public static function success(array $user): self
    {
        return new self(self::SUCCESS, $user);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    /** @param array<string, mixed> $user */
    public static function unverified(array $user): self
    {
        return new self(self::UNVERIFIED, $user);
    }
}
