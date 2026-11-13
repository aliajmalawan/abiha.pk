<?php

declare(strict_types=1);

namespace App\Core;

use mysqli;
use mysqli_sql_exception;
use mysqli_stmt;

/**
 * Thin MySQLi wrapper. Every query goes through a prepared statement with typed
 * bindings; there is no string-interpolated SQL path for user input.
 */
final class Database
{
    private static ?Database $instance = null;
    private mysqli $m;
    private int $depth = 0;

    public function __construct(array $cfg)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->m = mysqli_init();
        $this->m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        $this->m->real_connect($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], $cfg['port']);
        $this->m->set_charset('utf8mb4');
        // No "SET time_zone": the old Laravel app used the server's default session time zone and wrote timestamps as
        // Asia/Karachi wall-clock strings (config app.timezone). Keeping both identical means existing TIMESTAMP values
        // read back unchanged. Write times with the now() helper, never NOW()/UTC_TIMESTAMP() in SQL.
        $this->m->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    public static function get(): self
    {
        return self::$instance ??= new self(Config::get('db'));
    }

    public static function swap(?self $db): void
    {
        self::$instance = $db;
    }

    public function raw(): mysqli
    {
        return $this->m;
    }

    /** Queries run in this request (shown in the Server-Timing header outside production). */
    public static int $queries = 0;

    private function run(string $sql, array $params): mysqli_stmt
    {
        self::$queries++;
        $stmt = $this->m->prepare($sql);
        if ($params) {
            $types = '';
            $values = [];
            foreach (array_values($params) as $p) {
                if (is_bool($p)) {
                    $types .= 'i';
                    $values[] = $p ? 1 : 0;
                } elseif (is_int($p)) {
                    $types .= 'i';
                    $values[] = $p;
                } elseif (is_float($p)) {
                    $types .= 'd';
                    $values[] = $p;
                } elseif ($p === null) {
                    $types .= 's';
                    $values[] = null;
                } else {
                    $types .= 's';
                    $values[] = (string) $p;
                }
            }
            $stmt->bind_param($types, ...$values);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->run($sql, $params);
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->run($sql, $params);
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->one($sql, $params);
        return $row ? reset($row) : null;
    }

    /** @return list<mixed> */
    public function column(string $sql, array $params = []): array
    {
        return array_map(static fn ($r) => reset($r), $this->all($sql, $params));
    }

    /** Executes a write and returns affected rows. */
    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->run($sql, $params);
        $n = $stmt->affected_rows;
        $stmt->close();
        return (int) $n;
    }

    public function insert(string $sql, array $params = []): int
    {
        $stmt = $this->run($sql, $params);
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** Builds "(?, ?, ?)" for IN clauses; empty lists yield "(NULL)" (matches nothing). */
    public static function in(array $values): string
    {
        return $values ? '(' . implode(',', array_fill(0, count($values), '?')) . ')' : '(NULL)';
    }

    /**
     * Runs $fn inside a transaction. Retries on deadlock / lock-wait timeout.
     * Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn, int $attempts = 3): mixed
    {
        if ($this->depth > 0) {
            return $fn($this);
        }
        for ($try = 1; ; $try++) {
            $this->m->begin_transaction();
            $this->depth++;
            try {
                $result = $fn($this);
                $this->m->commit();
                $this->depth--;
                return $result;
            } catch (\Throwable $e) {
                $this->depth--;
                try {
                    $this->m->rollback();
                } catch (\Throwable) {
                }
                $retryable = $e instanceof mysqli_sql_exception && in_array($e->getCode(), [1213, 1205], true);
                if (!$retryable || $try >= $attempts) {
                    throw $e;
                }
                usleep(random_int(20_000, 80_000) * $try);
            }
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
}
