<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One implementation of the single-use e-mail token design shared by e-mail
 * verification (Phase 2), invitations, password resets and e-mail changes.
 *
 *  - generate(): 32 bytes from random_bytes() (a CSPRNG) → 64 hex characters.
 *    256 bits cannot be guessed or brute-forced over HTTP.
 *  - hash(): only SHA-256 of the raw token is stored. A database leak, backup or
 *    log of the token tables therefore contains no usable link. A fast hash is
 *    fine here (unlike passwords): the input is already high-entropy random.
 *  - matches(): constant-time comparison with hash_equals(), as a second check
 *    after the indexed lookup by hash.
 *  - Expiry, single use and revocation are columns of each token table
 *    (expires_at, consumed_at, revoked_at) and are computed by MySQL with
 *    UTC_TIMESTAMP(), so they never depend on the PHP server's clock.
 */
final class TokenService
{
    private const BYTES = 32;

    /**
     * A new raw token and its hash. The raw value goes into the e-mail only;
     * the hash goes into the database.
     *
     * @return array{raw: string, hash: string}
     */
    public function generate(): array
    {
        $raw = bin2hex(random_bytes(self::BYTES));

        return ['raw' => $raw, 'hash' => $this->hash($raw)];
    }

    /** hex(SHA-256(raw)), the value stored in the token_hash columns. */
    public function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Rejects anything that is not exactly 64 lowercase hex characters before a
     * query runs, so malformed or oversized input never reaches the database.
     */
    public function isWellFormed(string $raw): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $raw) === 1;
    }

    /** Constant-time check that $raw hashes to the stored value. */
    public function matches(string $raw, string $storedHash): bool
    {
        return $this->isWellFormed($raw) && hash_equals($storedHash, $this->hash($raw));
    }
}
