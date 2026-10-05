<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * Base class for models of GLOBAL tables (users, condominiums, roles, tokens...).
 *
 * Models of tenant-scoped tables must extend TenantModel instead. That rule is
 * what keeps tenant data isolated, so it is enforced by code review.
 *
 * SQL safety: values are always bound parameters. Column and table names
 * cannot be bound, so the generic insert()/update() helpers only accept columns
 * listed in $fillable (a whitelist written by the developer, never by the user).
 */
abstract class Model
{
    /** Table name, set by each subclass. */
    protected string $table;

    /** @var list<string> Columns writable through insert()/update(). */
    protected array $fillable = [];

    /** Returns one row by primary key, or null. */
    public function find(int $id): ?array
    {
        return $this->selectWhere(['id' => $id]);
    }

    /**
     * Inserts a row with the whitelisted columns from $data.
     *
     * @param array<string, mixed> $data
     * @return int The new row's id.
     */
    public function insert(array $data): int
    {
        return $this->insertRow($this->onlyFillable($data));
    }

    /**
     * Updates whitelisted columns of a row by primary key.
     *
     * @param array<string, mixed> $data
     * @return int Number of rows changed.
     */
    public function update(int $id, array $data): int
    {
        return $this->updateWhere($this->onlyFillable($data), ['id' => $id]);
    }

    protected function db(): PDO
    {
        return Database::connection();
    }

    /**
     * Prepares and executes a statement, binding each value with the PDO type
     * that matches its PHP type. Explicit types matter with real prepared
     * statements: e.g. LIMIT rejects a value bound as a string.
     *
     * @param array<string, mixed> $params
     */
    protected function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->db()->prepare($sql);
        foreach ($params as $name => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $statement->bindValue(':' . $name, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    /** @param array<string, mixed> $params */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     * @return int Affected rows.
     */
    protected function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * SELECT * ... WHERE col = :col AND ... LIMIT 1. $where keys come from code only.
     *
     * @param array<string, mixed> $where
     */
    protected function selectWhere(array $where): ?array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE %s LIMIT 1', $this->table, $this->conditions($where, 'w_'));

        return $this->fetchOne($sql, $this->prefixKeys($where, 'w_'));
    }

    /** @param array<string, mixed> $data Column => value; keys must already be whitelisted. */
    protected function insertRow(array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );
        $this->execute($sql, $data);

        return (int) $this->db()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $set   Columns to change (whitelisted).
     * @param array<string, mixed> $where Conditions (keys from code only).
     */
    protected function updateWhere(array $set, array $where): int
    {
        $assignments = implode(', ', array_map(
            static fn (string $c): string => sprintf('`%s` = :s_%s', $c, $c),
            array_keys($set)
        ));
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $this->table, $assignments, $this->conditions($where, 'w_'));

        return $this->execute($sql, $this->prefixKeys($set, 's_') + $this->prefixKeys($where, 'w_'));
    }

    /**
     * Keeps only whitelisted columns; rejects an empty result so a typo cannot
     * silently produce "UPDATE t SET  WHERE ...".
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function onlyFillable(array $data): array
    {
        $filtered = array_intersect_key($data, array_flip($this->fillable));
        if ($filtered === []) {
            throw new InvalidArgumentException(sprintf('No writable columns given for table "%s".', $this->table));
        }

        return $filtered;
    }

    /**
     * Builds an IN (...) list of bound placeholders for integer ids:
     *   [$sql, $params] = $this->inList('unit', [3, 7]);  // ":unit0, :unit1", ['unit0' => 3, 'unit1' => 7]
     * Only placeholder names are generated; the values stay bound parameters.
     * Callers must handle an empty list themselves (IN () is invalid SQL).
     *
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    protected function inList(string $prefix, array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':' . $prefix . $i;
            $params[$prefix . $i] = (int) $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    /**
     * "%term%" for a LIKE search, with the user's own % and _ escaped so they
     * match literally instead of acting as wildcards. The result is still bound
     * as a parameter, never concatenated into SQL.
     */
    protected static function likeContains(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }

    /** @param array<string, mixed> $where */
    private function conditions(array $where, string $prefix): string
    {
        return implode(' AND ', array_map(
            static fn (string $c): string => sprintf('`%s` = :%s%s', $c, $prefix, $c),
            array_keys($where)
        ));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function prefixKeys(array $values, string $prefix): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$prefix . $key] = $value;
        }

        return $result;
    }
}
