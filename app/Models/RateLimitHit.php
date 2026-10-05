<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Attempts counted by App\Services\RateLimiter (`rate_limit_hits`, migration 0003).
 * Global table: the subjects (IPs, e-mail addresses) are not tenant data.
 */
final class RateLimitHit extends Model
{
    protected string $table = 'rate_limit_hits';

    public function record(string $bucket, string $subjectHash): void
    {
        $this->execute(
            'INSERT INTO rate_limit_hits (bucket, subject_hash) VALUES (:bucket, :subject_hash)',
            ['bucket' => $bucket, 'subject_hash' => $subjectHash]
        );
    }

    /** Hits of one subject in one bucket during the last $seconds (index ix_rate_limit_window). */
    public function countSince(string $bucket, string $subjectHash, int $seconds): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS total
               FROM rate_limit_hits
              WHERE bucket = :bucket
                AND subject_hash = :subject_hash
                AND created_at > UTC_TIMESTAMP() - INTERVAL :seconds SECOND',
            ['bucket' => $bucket, 'subject_hash' => $subjectHash, 'seconds' => $seconds]
        );

        return (int) ($row['total'] ?? 0);
    }

    /** Housekeeping: deletes rows older than every configured window (bounded batch). */
    public function purgeOlderThan(int $seconds): void
    {
        $this->execute(
            'DELETE FROM rate_limit_hits WHERE created_at < UTC_TIMESTAMP() - INTERVAL :seconds SECOND LIMIT 5000',
            ['seconds' => $seconds]
        );
    }
}
