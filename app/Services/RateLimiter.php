<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\RateLimitHit;
use LogicException;

/**
 * Database-backed sliding-window rate limiter (Phase 5).
 *
 * Unlike App\Core\RateLimiter (Phase 4, kept for flood control of the social
 * feed), which lives in the session, this one is stored in `rate_limit_hits`.
 * That matters for logged-out actions: an attacker simply drops the session
 * cookie to reset a session-based counter, but cannot reset a database row.
 *
 * Limits are configured per action and per subject kind in
 * config/security.php ("rate_limits"), e.g.:
 *
 *   'login' => ['ip' => [20, 900], 'account' => [10, 900]]   // 20 per IP / 10 per e-mail in 15 min
 *
 *   if (!$limiter->attempt('login', ['ip' => $request->ip(), 'account' => $email])) { ... 429 ... }
 *
 * Every subject is counted (no short-circuit), so an attacker rotating e-mail
 * addresses still runs into the per-IP limit, and one rotating IPs still runs
 * into the per-account limit.
 */
final class RateLimiter
{
    /** One in this many calls purges rows older than the longest window. */
    private const PURGE_ONE_IN = 50;

    public function __construct(private readonly RateLimitHit $hits = new RateLimitHit())
    {
    }

    /**
     * Records one attempt for each subject and reports whether ALL of them are
     * still within their limits.
     *
     * The hit is recorded BEFORE counting, so concurrent requests can only
     * over-count (and be refused), never slip through together: fail-closed.
     *
     * @param array<string, string> $subjects kind => value, e.g. ['ip' => '203.0.113.9']
     */
    public function attempt(string $action, array $subjects): bool
    {
        $limits = Config::get('security.rate_limits.' . $action);
        if (!is_array($limits)) {
            throw new LogicException(sprintf('No rate limit configured for "%s".', $action));
        }

        $allowed = true;
        foreach ($subjects as $kind => $value) {
            [$max, $seconds] = $limits[$kind] ?? throw new LogicException(sprintf('No "%s" limit for "%s".', $kind, $action));
            $bucket = $action . '.' . $kind;
            $subjectHash = self::subjectHash($value);

            $this->hits->record($bucket, $subjectHash);
            if ($this->hits->countSince($bucket, $subjectHash, (int) $seconds) > (int) $max) {
                $allowed = false;
            }
        }

        if (random_int(1, self::PURGE_ONE_IN) === 1) {
            $this->hits->purgeOlderThan(2 * 86400);
        }

        return $allowed;
    }

    /**
     * Subjects are stored as SHA-256 of the normalised value, so the table holds
     * no raw e-mail or IP address. (This is pseudonymisation, not anonymity:
     * IPv4 addresses are few enough to brute-force. The rows expire in 2 days.)
     */
    private static function subjectHash(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }
}
