<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sliding-window rate limiter stored in the user's session.
 *
 * Enough to stop a resident (or a script using their session) from flooding
 * the feed (Phase 1, NFR-SEC-09). It is per session, not per IP; abuse at the
 * network level belongs to the web server or a WAF.
 */
final class RateLimiter
{
    private const SESSION_KEY = '_rate_limits';

    /**
     * Records an attempt for $key and reports whether it is allowed.
     *
     * @return bool False when $max attempts already happened in the last $seconds.
     */
    public static function attempt(string $key, int $max, int $seconds): bool
    {
        $now = time();
        $buckets = Session::get(self::SESSION_KEY, []);
        $hits = array_values(array_filter(
            is_array($buckets[$key] ?? null) ? $buckets[$key] : [],
            static fn (mixed $t): bool => is_int($t) && $t > $now - $seconds
        ));

        if (count($hits) >= $max) {
            return false;
        }

        $hits[] = $now;
        $buckets[$key] = $hits;
        Session::set(self::SESSION_KEY, $buckets);

        return true;
    }
}
