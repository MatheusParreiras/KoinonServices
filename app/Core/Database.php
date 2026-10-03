<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Lazily created, shared PDO connection (one per request).
 *
 * A static accessor is used instead of a DI container to keep the framework-free
 * codebase simple; models obtain the connection through Model::db(), so swapping
 * this for constructor injection later only touches the base Model.
 */
final class Database
{
    private static ?PDO $connection = null;

    /** Returns the shared connection, connecting on first use. */
    public static function connection(): PDO
    {
        return self::$connection ??= self::connect();
    }

    /**
     * Runs $callback inside a transaction: commit on success, rollback on any
     * exception (which is then re-thrown).
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function connect(): PDO
    {
        $config = Config::get('database');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                // Every SQL error becomes an exception instead of a silent false.
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real server-side prepared statements: the SQL text and the
                // values travel separately, so values can never be parsed as SQL.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // DATETIME defaults (CURRENT_TIMESTAMP) and NOW() must be UTC (NFR-DATA-02).
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            Logger::error('Database connection failed', [
                'code'    => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
            // The original exception is deliberately NOT chained: the PDO
            // constructor's stack trace contains the DSN, user and password.
            throw new RuntimeException('Database unavailable.');
        }

        return $pdo;
    }
}
