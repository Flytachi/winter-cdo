<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Connection;

use Closure;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Throwable;

/**
 * CDO - Connection Data Object
 *
 * Extended PDO wrapper with convenient methods for database operations.
 * Supports PostgreSQL, MySQL/MariaDB, SQLite, and Oracle databases.
 *
 * Features:
 * - Automatic driver detection and configuration
 * - Timezone synchronization with PHP
 * - Type-safe parameter binding
 * - Batch insert/upsert with chunking support
 *
 * Basic usage (example):
 * ```
 * $cdo = new CDO($config);
 *
 * // Insert
 * $id = $cdo->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
 *
 * // Update
 * $affected = $cdo->update('users', ['name' => 'Jane'], Qb::eq('id', 1));
 *
 * // Delete
 * $deleted = $cdo->delete('users', Qb::eq('id', 1));
 *
 * // Batch insert
 * $cdo->insertBatch('users', $usersArray, chunkSize: 500);
 * ```
 *
 * @package Flytachi\Winter\Cdo\Connection
 * @author Flytachi
 * @version 2.5
 */
class CDO extends PDO
{
    private LoggerInterface $logger;

    /**
     * Normalized database driver name, cached at construction time.
     *
     * Values: 'pgsql' | 'mysql' | 'mariadb' | 'sqlite' | 'oci'.
     *
     * PDO::ATTR_DRIVER_NAME returns 'mysql' for both MySQL and MariaDB, but they
     * diverge in supported SQL — MariaDB ≥ 10.5 supports `INSERT ... RETURNING`,
     * MySQL never does — so we detect the flavour from PDO::ATTR_SERVER_VERSION
     * (e.g. "5.5.5-10.11.6-MariaDB" or "11.4.2-MariaDB"; MySQL never contains
     * the "MariaDB" marker) and store 'mariadb' separately here.
     */
    private string $driverName = '';

    /**
     * Create a new CDO connection
     *
     * Establishes database connection with automatic driver configuration,
     * timezone synchronization, and optional debug mode.
     *
     * @param DbConfigInterface $config Database configuration object
     * @param int $timeout Connection timeout in seconds (default: 5)
     * @param bool $debug Enable debug mode with PDO::ERRMODE_EXCEPTION (default: false)
     *
     * @throws CDOException If connection fails
     *
     * Example:
     * ```
     * $config = new MyDbConfig();
     * $cdo = new CDO($config, timeout: 10, debug: true);
     * ```
     */
    public function __construct(DbConfigInterface $config, int $timeout = 5, bool $debug = false)
    {
        $this->logger = $config->getLogger();
        try {
            // ATTR_PERSISTENT and ATTR_TIMEOUT are connection-time options: they
            // only take effect when passed in the driver-options array here.
            // PDO::setAttribute(PDO::ATTR_PERSISTENT, …) after construction is a
            // no-op (returns false), so persistence must be requested up front.
            parent::__construct(
                $config->getDNS(),
                $config->getUsername(),
                $config->getPassword(),
                [
                    PDO::ATTR_PERSISTENT => $config->getPersistentStatus(),
                    PDO::ATTR_TIMEOUT => $timeout,
                ]
            );
            $this->applyDatabase();

            if ($debug) {
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        } catch (PDOException $e) {
            throw new CDOException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Get the normalized database driver name
     *
     * Unlike PDO::ATTR_DRIVER_NAME — which returns 'mysql' for both MySQL and
     * MariaDB — this method distinguishes the two: MariaDB is detected from
     * PDO::ATTR_SERVER_VERSION at construction time and returned as 'mariadb'.
     *
     * @return string One of: 'pgsql' | 'mysql' | 'mariadb' | 'sqlite' | 'oci'
     *
     * Example:
     * ```
     * if ($cdo->getDriverName() === 'mariadb') {
     *     // MariaDB-specific behaviour (e.g. INSERT ... RETURNING)
     * }
     * ```
     */
    public function getDriverName(): string
    {
        return $this->driverName;
    }

    /**
     * Insert a single record into the database
     *
     * Automatically detects primary key from first array key and returns
     * the inserted ID. Uses RETURNING for PostgreSQL, lastInsertId() for MySQL.
     *
     * Note: NULL values are automatically excluded from the INSERT statement.
     *
     * @param string $table Table name
     * @param object|array $entity Data to insert (object will be converted via get_object_vars)
     *
     * @return mixed Inserted primary key value
     *
     * @throws CDOException If insert fails or no result returned
     *
     * Example:
     * ```
     * // Using array
     * $userId = $cdo->insert('users', [
     *     'id' => null,  // will be excluded, auto-generated
     *     'name' => 'John',
     *     'email' => 'john@example.com'
     * ]);
     *
     * // Using object
     * $user = new stdClass();
     * $user->name = 'John';
     * $user->email = 'john@example.com';
     * $userId = $cdo->insert('users', $user);
     * ```
     */
    final public function insert(string $table, object|array $entity): mixed
    {
        $data = is_object($entity) ? get_object_vars($entity) : $entity;
        foreach ($data as $key => $value) {
            if (is_null($value)) {
                unset($data[$key]);
            }
        }
        $row = $this->buildRow($data);

        try {
            $tableQ = $this->quoteIdentifier($table);
            $primaryKey = $this->quoteIdentifier((string) array_key_first((array) $entity));
            $useReturning = $this->driverName === 'pgsql' || $this->driverName === 'mariadb';

            if ($useReturning) {
                $query = "INSERT INTO $tableQ ({$row['columns']}) VALUES ({$row['placeholders']})"
                    . " RETURNING $primaryKey";
            } else {
                $query = "INSERT INTO $tableQ ({$row['columns']}) VALUES ({$row['placeholders']})";
            }
            $this->logger->debug('insert:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($row['binds'] as $placeholder => $paramVal) {
                $stmt->bindTypedValue($placeholder, $paramVal);
            }
            $stmt->getStmt()->execute();

            if ($useReturning) {
                $result = $stmt->getStmt()->fetchColumn();
            } else {
                $result = $this->lastInsertId();
            }

            // Genuine SQL errors surface through PDOException (caught below).
            // A falsy $result here just means "no auto-generated id to return"
            // — e.g. MySQL INSERT into a table without AUTO_INCREMENT, or one
            // with an explicit value for the AUTO_INCREMENT column.
            return $result ?: null;
        } catch (PDOException $ex) {
            throw new CDOException(
                'Error when creating a record in the database (' . $ex->getMessage() . ')',
                previous: $ex
            );
        }
    }

    /**
     * Insert many records, sent to the server in batches.
     *
     * Rows are buffered per column signature and a batch is issued as soon as one
     * fills, so peak memory follows `$chunkSize` rather than the size of the job:
     * a generator of a million rows costs the same as one of a thousand. Chunking
     * also keeps each statement inside the server's limits (max placeholders,
     * packet size).
     *
     * `$entities` is any `iterable` — an array, a generator, any `Traversable`:
     *
     * ```
     * $cdo->insertBatch('users', [
     *     ['name' => 'John', 'email' => 'john@example.com'],
     *     ['name' => 'Jane', 'email' => 'jane@example.com'],
     * ]);
     *
     * // Streamed: nothing but the current batch is ever held in memory.
     * $cdo->insertBatch('users', (function () {
     *     foreach (fopen('users.csv', 'r') as $line) {
     *         yield ['name' => $line[0], 'email' => $line[1]];
     *     }
     * })());
     *
     * $cdo->insertBatch('users', $rows, chunkSize: 500);
     * ```
     *
     * NULL values are dropped from each row, so rows differing only in which
     * columns are null still insert correctly — see {@see normalizeRow()}.
     *
     * **Partial writes on failure.** Batches are sent as they fill, so a row that
     * fails validation, or a batch the server rejects, leaves the batches before
     * it already committed. Wrap the call in {@see transaction()} when the whole
     * job must be all-or-nothing; that is the caller's choice, because holding a
     * million rows in one transaction is not always the right trade.
     *
     * @param string $table Table name.
     * @param iterable<object|array<string, mixed>> $entities Rows to insert.
     * @param int $chunkSize Rows per INSERT statement (default: 1000). Anything
     *   below 1 means a statement per row, which is the natural reading of "batch
     *   of zero" and needs no clamping.
     *
     * @return int Total rows inserted. For a plain INSERT this count is reported
     *             identically by PostgreSQL, MySQL and MariaDB.
     *
     * @throws CDOException If a row has no non-null columns, or an insert fails.
     */
    final public function insertBatch(string $table, iterable $entities, int $chunkSize = 1000): int
    {
        $affected = 0;

        // One buffer per column signature: rows of the same shape combine into one
        // multi-row statement, rows of another shape form their own (see
        // normalizeRow()). Each buffer is flushed the moment it fills, which is what
        // keeps memory bounded by $chunkSize instead of by the number of rows.
        $buffers = [];
        foreach ($entities as $entity) {
            $row       = $this->normalizeRow($entity);
            $signature = implode(',', array_keys($row));

            $buffers[$signature][] = $row;
            if (count($buffers[$signature]) >= $chunkSize) {
                $affected += $this->insertChunk($table, $buffers[$signature]);
                $buffers[$signature] = [];
            }
        }

        foreach ($buffers as $rows) {
            if ($rows !== []) {
                $affected += $this->insertChunk($table, $rows);
            }
        }

        return $affected;
    }

    /**
     * Insert or update a single record (upsert)
     *
     * Inserts a new record, or updates existing one if conflict occurs.
     * Uses ON CONFLICT for PostgreSQL and ON DUPLICATE KEY UPDATE for MySQL/MariaDB.
     *
     * Placeholders for expressions:
     * - `:new` - new (incoming) value (PostgreSQL: EXCLUDED.column, MySQL: VALUES(column))
     * - `:current` - current table value (PostgreSQL: table.column, MySQL: column)
     *
     * @param string $table Table name
     * @param object|array $entity Data to insert/update
     * @param array $conflictColumns Columns that define uniqueness
     * @param array|null $updateColumns Columns to update on conflict: ['column' => 'expression']
     *                                   If null or empty - conflict is ignored (DO NOTHING)
     *
     * @return mixed Inserted/existing primary key value (PostgreSQL only with RETURNING)
     *
     * @throws CDOException If conflictColumns is empty or query fails
     *
     * Example
     * ```
     * // Insert or update single product
     * $cdo->upsert(
     *     'products',
     *     ['sku' => 'ABC', 'name' => 'Product', 'price' => 100, 'stock' => 5],
     *     ['sku'],
     *     [
     *         'name' => ':new',
     *         'price' => ':new',
     *         'stock' => ':current + :new'
     *     ]
     * );
     *
     * // Insert only if not exists (ignore duplicate)
     * $cdo->upsert('users', $user, ['email']);
     * ```
     */
    final public function upsert(
        string $table,
        object|array $entity,
        array $conflictColumns,
        ?array $updateColumns = null
    ): mixed {
        if (empty($conflictColumns)) {
            throw new CDOException('conflictColumns is empty');
        }
        $this->assertUpdateColumns($updateColumns);

        $data = is_object($entity) ? get_object_vars($entity) : $entity;
        foreach ($data as $key => $value) {
            if (is_null($value)) {
                unset($data[$key]);
            }
        }

        $row = $this->buildRow($data);
        $columns = $row['columns'];
        $placeholders = $row['placeholders'];

        try {
            $tableQ = $this->quoteIdentifier($table);
            $primaryKey = $this->quoteIdentifier((string) array_key_first((array) $entity));
            $conflictColumnStr = $this->quoteIdentifierList($conflictColumns);

            if (empty($updateColumns)) {
                if ($this->usesPgConflictSyntax()) {
                    // PostgreSQL / SQLite: ON CONFLICT (...) DO NOTHING.
                    $query = "INSERT INTO $tableQ ($columns) VALUES ($placeholders)"
                        . " ON CONFLICT ($conflictColumnStr) DO NOTHING";
                    // Only PostgreSQL returns the key here; SQLite falls through
                    // to lastInsertId() to avoid depending on RETURNING (3.35+).
                    if ($this->driverName === 'pgsql') {
                        $query .= " RETURNING $primaryKey";
                    }
                } else {
                    // MariaDB also disallows RETURNING with INSERT IGNORE, so fall through to lastInsertId().
                    $query = "INSERT IGNORE INTO $tableQ ($columns) VALUES ($placeholders)";
                }
            } else {
                $updateColumnStr = $this->buildUpdateSetString($updateColumns, $this->driverName, $table);
                if ($this->usesPgConflictSyntax()) {
                    // PostgreSQL / SQLite: ON CONFLICT (...) DO UPDATE SET ...
                    $query = "INSERT INTO $tableQ ($columns) VALUES ($placeholders)"
                        . " ON CONFLICT ($conflictColumnStr) DO UPDATE SET $updateColumnStr";
                    if ($this->driverName === 'pgsql') {
                        $query .= " RETURNING $primaryKey";
                    }
                } else {
                    // MariaDB disallows RETURNING with ON DUPLICATE KEY UPDATE, so fall through to lastInsertId().
                    $query = "INSERT INTO $tableQ ($columns) VALUES ($placeholders)"
                        . " ON DUPLICATE KEY UPDATE $updateColumnStr";
                }
            }

            $this->logger->debug('upsert:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($row['binds'] as $placeholder => $paramVal) {
                $stmt->bindTypedValue($placeholder, $paramVal);
            }
            $stmt->getStmt()->execute();

            if ($this->driverName === 'pgsql') {
                $result = $stmt->getStmt()->fetchColumn();
                return $result ?: null;
            } else {
                $insertId = $this->lastInsertId();
                return $insertId ?: null;
            }
        } catch (PDOException $ex) {
            throw new CDOException(
                'Error when upserting a record in the database (' . $ex->getMessage() . ')',
                previous: $ex
            );
        }
    }

    /**
     * Update records in the database
     *
     * Updates records matching the Qb condition. Returns the number
     * of affected rows.
     *
     * @param string $table Table name
     * @param object|array $entity Data to update (object will be converted via get_object_vars)
     * @param Qb $qb Query builder condition for WHERE clause
     *
     * @return int Number of affected rows
     *
     * @throws CDOException If update fails
     *
     * Example
     * ```
     * // Update single record
     * $affected = $cdo->update('users',
     *     ['name' => 'Jane Doe', 'updated_at' => date('Y-m-d H:i:s')],
     *     Qb::eq('id', 1)
     * );
     *
     * // Update with complex condition
     * $affected = $cdo->update('users',
     *     ['status' => 'inactive'],
     *     Qb::and(
     *         Qb::lt('last_login', '2024-01-01'),
     *         Qb::eq('status', 'active')
     *     )
     * );
     * ```
     */
    final public function update(string $table, object|array $entity, Qb $qb): int
    {
        $data = is_object($entity) ? get_object_vars($entity) : $entity;
        $setParts = [];
        $binds = [];
        $i = 0;
        foreach ($data as $key => $value) {
            $placeholder = ':set' . $i++;
            $setParts[] = $this->quoteIdentifier((string) $key) . '=' . $placeholder;
            $binds[$placeholder] = $value;
        }

        try {
            $query = "UPDATE {$this->quoteIdentifier($table)} SET " . implode(',', $setParts)
                . " WHERE " . $qb->getQuery();
            $this->logger->debug('update:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($qb->getBinds() as $bind) {
                $stmt->bindTypedValue($bind->getName(), $bind->getValue());
            }
            foreach ($binds as $placeholder => $paramVal) {
                $stmt->bindTypedValue($placeholder, $paramVal);
            }
            $stmt->getStmt()->execute();
            return $stmt->getStmt()->rowCount();
        } catch (PDOException $ex) {
            throw new CDOException(
                'Error when changing a record in the database (' . $ex->getMessage() . ')',
                previous: $ex
            );
        }
    }

    /**
     * Delete records from the database
     *
     * Deletes records matching the Qb condition. Returns the number
     * of deleted rows.
     *
     * @param string $table Table name
     * @param Qb $qb Query builder condition for WHERE clause
     *
     * @return int Number of deleted rows
     *
     * @throws CDOException If delete fails
     *
     * Example
     * ```
     * // Delete single record
     * $deleted = $cdo->delete('users', Qb::eq('id', 1));
     *
     * // Delete with complex condition
     * $deleted = $cdo->delete('sessions',
     *     Qb::and(
     *         Qb::lt('expires_at', date('Y-m-d H:i:s')),
     *         Qb::eq('is_active', false)
     *     )
     * );
     *
     * // Delete using IN
     * $deleted = $cdo->delete('users', Qb::in('id', [1, 2, 3]));
     * ```
     */
    final public function delete(string $table, Qb $qb): int
    {
        try {
            $query = "DELETE FROM {$this->quoteIdentifier($table)} WHERE " . $qb->getQuery();
            $this->logger->debug('delete:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($qb->getBinds() as $bind) {
                $stmt->bindTypedValue($bind->getName(), $bind->getValue());
            }
            $stmt->getStmt()->execute();
            return $stmt->getStmt()->rowCount();
        } catch (PDOException $ex) {
            throw new CDOException(
                'Error deleting a record in the database (' . $ex->getMessage() . ')',
                previous: $ex
            );
        }
    }

    /**
     * Insert or update multiple records (upsert)
     *
     * Performs batch upsert with automatic chunking to handle large datasets safely.
     * Automatically adapts SQL syntax based on database driver:
     * - PostgreSQL: INSERT ... ON CONFLICT (...) DO UPDATE SET ...
     * - MySQL/MariaDB: INSERT ... ON DUPLICATE KEY UPDATE ...
     *
     * ## Placeholders
     *
     * | Placeholder | Description | PostgreSQL | MySQL |
     * |-------------|-------------|------------|-------|
     * | `:new` | New (incoming) value | `EXCLUDED.column` | `VALUES(column)` |
     * | `:current` | Current table value | `table.column` | `column` |
     *
     * ## Expression Examples
     *
     * | Expression | Description | Result (PostgreSQL) |
     * |------------|-------------|---------------------|
     * | `:new` | Replace with new value | `EXCLUDED.column` |
     * | `:current + :new` | Add new to current | `table.column + EXCLUDED.column` |
     * | `:current - :new` | Subtract new from current | `table.column - EXCLUDED.column` |
     * | `GREATEST(:current, :new)` | Take maximum | `GREATEST(table.column, EXCLUDED.column)` |
     * | `COALESCE(:new, :current)` | New value or keep current | `COALESCE(EXCLUDED.column, table.column)` |
     * | `NOW()` | SQL function (no placeholder) | `NOW()` |
     *
     * ## Memory
     *
     * `$entities` is any `iterable` — an array, a generator, any `Traversable`. Rows
     * are buffered per column shape and a batch is issued as soon as one fills, so
     * peak memory follows `$chunkSize` rather than the size of the job. Streaming a
     * million rows costs the same as a thousand; see {@see insertBatch()}.
     *
     * **Partial writes on failure.** Batches are sent as they fill, so a row that
     * fails validation, or a batch the server rejects, leaves the batches before it
     * already committed. Wrap the call in {@see transaction()} when the whole job
     * must be all-or-nothing.
     *
     * @param string $table Table name
     * @param iterable<object|array<string, mixed>> $entities Rows to upsert
     * @param array $conflictColumns Columns that define uniqueness (e.g., ['id'] or ['warehouse_id', 'product_id'])
     * @param array|null $updateColumns Columns to update on conflict: ['column' => 'expression']
     *                                   If null or empty - conflicts are ignored (DO NOTHING / INSERT IGNORE)
     * @param int $chunkSize Rows per statement (default: 500). Anything below 1 means
     *                       a statement per row.
     *
     * Returns void by design: unlike a plain INSERT, the affected-row count of
     * an upsert is not comparable across drivers — MySQL/MariaDB report 2 per
     * updated row (1 per insert, 0 when unchanged) while PostgreSQL reports 1
     * per affected row, and IGNORE / DO NOTHING count only real inserts. There
     * is no stable cross-database "rows affected" value to return here.
     *
     * @throws CDOException If conflictColumns is empty or query fails
     *
     * Example: Insert only new records (ignore duplicates)
     * ```
     * // PostgreSQL: ON CONFLICT (email) DO NOTHING
     * // MySQL: INSERT IGNORE INTO ...
     * $cdo->upsertBatch(
     *     'users',
     *     $users,
     *     ['email']  // no updateColumns - just ignore duplicates
     * );
     * ```
     *
     * Example: Basic upsert (replace values)
     * ```
     * $cdo->upsertBatch(
     *     'users',
     *     $users,
     *     ['email'],
     *     [
     *         'name' => ':new',
     *         'updated_at' => 'NOW()'
     *     ]
     * );
     * ```
     *
     * Example: Inventory update (accumulate quantity)
     * ```
     * $cdo->upsertBatch(
     *     'inventory',
     *     $items,
     *     ['warehouse_id', 'product_id'],
     *     [
     *         'cost' => ':new',
     *         'quantity' => ':current + :new',
     *         'updated_at' => 'NOW()'
     *     ]
     * );
     * ```
     *
     * Example: Price update (take minimum)
     * ```
     * $cdo->upsertBatch(
     *     'products',
     *     $products,
     *     ['sku'],
     *     [
     *         'price' => 'LEAST(:current, :new)',
     *         'min_price_date' => 'CASE WHEN :new < :current THEN NOW() ELSE min_price_date END'
     *     ]
     * );
     * ```
     */
    final public function upsertBatch(
        string $table,
        iterable $entities,
        array $conflictColumns,
        ?array $updateColumns = null,
        int $chunkSize = 500
    ): void {
        if (empty($conflictColumns)) {
            throw new CDOException('conflictColumns is empty');
        }
        $this->assertUpdateColumns($updateColumns);

        // One buffer per column signature, flushed the moment it fills — the same
        // shape as insertBatch(), for the same reason: peak memory must follow the
        // batch, not the number of rows.
        $buffers = [];
        foreach ($entities as $entity) {
            $row       = $this->normalizeRow($entity);
            $signature = implode(',', array_keys($row));

            $buffers[$signature][] = $row;
            if (count($buffers[$signature]) >= $chunkSize) {
                $this->upsertChunk($table, $buffers[$signature], $conflictColumns, $updateColumns);
                $buffers[$signature] = [];
            }
        }

        foreach ($buffers as $rows) {
            if ($rows !== []) {
                $this->upsertChunk($table, $rows, $conflictColumns, $updateColumns);
            }
        }
    }

    // ==================== Private Methods ====================

    /**
     * Quote a SQL identifier (table or column name) for the active driver.
     *
     * Identifiers cannot be bound as parameters, so they are wrapped in the
     * driver's quoting characters to neutralise SQL injection through table /
     * column names. Dotted names are treated as qualified references and each
     * segment is quoted individually (`schema.table` -> `"schema"."table"`).
     * Any embedded quote character is doubled per SQL rules.
     *
     * - MySQL / MariaDB: backticks  (`col`)
     * - PostgreSQL / Oracle: double quotes  ("col")
     *
     * Note: quoted identifiers are case-sensitive in PostgreSQL — pass names
     * exactly as they exist in the schema.
     *
     * @param string $identifier Raw identifier, optionally dot-qualified.
     * @return string Quoted identifier safe for interpolation.
     */
    private function quoteIdentifier(string $identifier): string
    {
        $quote = ($this->driverName === 'mysql' || $this->driverName === 'mariadb') ? '`' : '"';

        $parts = array_map(
            fn(string $part): string => $quote . str_replace($quote, $quote . $quote, $part) . $quote,
            explode('.', $identifier)
        );

        return implode('.', $parts);
    }

    /**
     * Quote a list of identifiers and join them with commas.
     *
     * @param array $identifiers List of raw identifiers.
     * @return string Comma-separated list of quoted identifiers.
     */
    private function quoteIdentifierList(array $identifiers): string
    {
        return implode(',', array_map(
            fn($identifier): string => $this->quoteIdentifier((string) $identifier),
            $identifiers
        ));
    }

    /**
     * Build the column list, placeholder list and bound values for a single row.
     *
     * Placeholder names are positional (`:c0`, `:c1`, …) and never derived from
     * column names, so a hostile column name cannot leak into the query through
     * the placeholder. Column identifiers are quoted via {@see quoteIdentifier()}.
     *
     * @param array $row Column => value map (already NULL-filtered by the caller).
     * @return array{columns: string, placeholders: string, binds: array<string, mixed>}
     */
    private function buildRow(array $row): array
    {
        $columns = [];
        $placeholders = [];
        $binds = [];
        $i = 0;
        foreach ($row as $column => $value) {
            $placeholder = ':c' . $i++;
            $columns[] = $this->quoteIdentifier((string) $column);
            $placeholders[] = $placeholder;
            $binds[$placeholder] = $value;
        }

        return [
            'columns' => implode(',', $columns),
            'placeholders' => implode(',', $placeholders),
            'binds' => $binds,
        ];
    }

    /**
     * Whether the active driver uses PostgreSQL-style upsert syntax.
     *
     * PostgreSQL and SQLite share the same conflict grammar
     * (`INSERT ... ON CONFLICT (...) DO NOTHING | DO UPDATE SET ... EXCLUDED.col`),
     * whereas MySQL / MariaDB use the `INSERT IGNORE` / `ON DUPLICATE KEY UPDATE`
     * family instead.
     *
     * @return bool True for 'pgsql' and 'sqlite'.
     */
    private function usesPgConflictSyntax(): bool
    {
        return $this->driverName === 'pgsql' || $this->driverName === 'sqlite';
    }

    /**
     * Apply database-specific settings
     *
     * Configures PDO attributes based on driver type and sets timezone.
     */
    private function applyDatabase(): void
    {
        $driver = $this->getAttribute(PDO::ATTR_DRIVER_NAME);

        switch ($driver) {
            case 'pgsql':
                $this->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                break;
            case 'mysql':
            case 'oci':
                $this->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                break;
            default:
                break;
        }

        $this->driverName = $driver;
        if ($driver === 'mysql') {
            $version = (string) $this->getAttribute(PDO::ATTR_SERVER_VERSION);
            if (stripos($version, 'MariaDB') !== false) {
                $this->driverName = 'mariadb';
            }
        }

        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->applyDatabaseTimezone($driver, date_default_timezone_get());
    }

    /**
     * Set database timezone to match PHP timezone
     *
     * @param mixed $driver Database driver name
     * @param string $tz PHP timezone identifier
     */
    public function applyDatabaseTimezone(mixed $driver, string $tz): void
    {
        switch ($driver) {
            case 'pgsql':
                $this->exec("SET TIMEZONE TO " . $this->quote($tz));
                break;
            case 'mysql':
                $offset = $this->timezoneToOffset($tz);
                if ($offset !== null) {
                    $this->exec("SET time_zone = " . $this->quote($offset));
                }
                break;
            case 'oci':
                $this->exec("ALTER SESSION SET TIME_ZONE = " . $this->quote($tz));
                break;
            case 'sqlite':
                break;
            default:
                $this->logger->warning("Timezone setting not implemented for driver: $driver");
                break;
        }
    }

    /**
     * Execute a callback within a database transaction
     *
     * Begins a transaction, invokes the callback and commits on success.
     * If the callback throws, the transaction is rolled back and the
     * exception is re-thrown.
     *
     * @param Closure $callback Callback to run inside the transaction
     *
     * @throws Throwable Any exception thrown by the callback (after rollback)
     */
    public function transaction(Closure $callback): void
    {
        $this->beginTransaction();
        try {
            $callback();
            $this->commit();
        } catch (Throwable $e) {
            // a DDL statement caused an implicit commit on MySQL/MariaDB/Oracle.
            try {
                if ($this->inTransaction()) {
                    $this->rollback();
                }
            } catch (Throwable $rollbackError) {
                $this->logger->error('Transaction rollback failed: ' . $rollbackError->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Turn one entity into the row that goes into a batch.
     *
     * NULL values are stripped so the database applies column DEFAULTs and
     * auto-increment — matching single-row {@see insert()} semantics. The
     * remaining columns are sorted, so two rows carrying the same set of non-null
     * columns produce the same key order and can share one multi-row statement,
     * while rows of a different shape are buffered separately by the caller.
     *
     * This mirrors Hibernate's `@DynamicInsert` behaviour and prevents the
     * column/value count mismatch that a single heterogeneous VALUES list would
     * otherwise produce (e.g. `(a) VALUES (1,2),(3)`).
     *
     * Note: buffering per shape re-orders rows — a batch is sent when its own
     * buffer fills, independently of the others. For plain batch inserts this is
     * irrelevant, but do not rely on auto-increment ids following input order when
     * rows have differing column shapes.
     *
     * @param object|array<string, mixed> $entity Raw entity.
     * @return array<string, mixed> Normalized row, column-sorted.
     * @throws CDOException If the row has no non-null columns (nothing to insert).
     */
    private function normalizeRow(object|array $entity): array
    {
        $items = is_object($entity) ? get_object_vars($entity) : $entity;
        foreach ($items as $key => $value) {
            if (is_null($value)) {
                unset($items[$key]);
            }
        }
        if (empty($items)) {
            throw new CDOException('Cannot insert a row with no non-null columns');
        }
        ksort($items);

        return $items;
    }


    /**
     * Insert a homogeneous chunk of already-normalized rows.
     *
     * Every row shares the same column set (guaranteed by
     * {@see normalizeRow()}), so the column list is taken once from the
     * first row and every tuple has a matching value count.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $rows Normalized rows (null-free, uniform columns)
     *
     * @return int Number of rows inserted by this statement.
     *
     * @throws CDOException If insert fails
     */
    private function insertChunk(string $table, array $rows): int
    {
        $col = $this->quoteIdentifierList(array_keys($rows[0]));
        $data = [];
        $tuples = [];

        foreach ($rows as $rowIndex => $row) {
            $placeholders = [];
            $colIndex = 0;
            foreach ($row as $value) {
                $placeholder = ':r' . $rowIndex . '_c' . $colIndex++;
                $placeholders[] = $placeholder;
                $data[$placeholder] = $value;
            }
            $tuples[] = '(' . implode(',', $placeholders) . ')';
        }

        try {
            $query = "INSERT INTO {$this->quoteIdentifier($table)} ($col) VALUES " . implode(',', $tuples);
            $this->logger->debug('insert group:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($data as $placeholder => $paramVal) {
                $stmt->bindTypedValue($placeholder, $paramVal);
            }
            $stmt->getStmt()->execute();
            return $stmt->getStmt()->rowCount();
        } catch (PDOException $ex) {
            throw new CDOException(
                'Error when creating records in the database (' . $ex->getMessage() . ')',
                previous: $ex
            );
        }
    }

    /**
     * Upsert a homogeneous chunk of already-normalized rows.
     *
     * Every row shares the same column set (guaranteed by
     * {@see normalizeRow()}), so the column list is taken once from the
     * first row and every tuple has a matching value count.
     *
     * Note: an `updateColumns` expression referencing a column absent from this
     * group's signature (its value was NULL for every row here) resolves to
     * NULL — `EXCLUDED.col` / `VALUES(col)` has no value to draw from.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $rows Normalized rows (null-free, uniform columns)
     * @param array $conflictColumns Columns to check for conflict
     * @param array|null $updateColumns Columns to update on conflict
     *
     * @throws CDOException If upsert fails
     */
    private function upsertChunk(
        string $table,
        array $rows,
        array $conflictColumns,
        ?array $updateColumns
    ): void {
        $columns = $this->quoteIdentifierList(array_keys($rows[0]));
        $data = [];
        $tuples = [];

        foreach ($rows as $rowIndex => $row) {
            $placeholders = [];
            $colIndex = 0;
            foreach ($row as $val) {
                $placeholder = ':r' . $rowIndex . '_c' . $colIndex++;
                $placeholders[] = $placeholder;
                $data[$placeholder] = $val;
            }
            $tuples[] = '(' . implode(',', $placeholders) . ')';
        }
        $values = implode(',', $tuples);

        try {
            $tableQ = $this->quoteIdentifier($table);
            $conflictColumnStr = $this->quoteIdentifierList($conflictColumns);

            if (empty($updateColumns)) {
                // No update - just ignore conflicts
                if ($this->usesPgConflictSyntax()) {
                    $query = "INSERT INTO $tableQ ($columns) VALUES $values"
                        . " ON CONFLICT ($conflictColumnStr) DO NOTHING";
                } else {
                    $query = "INSERT IGNORE INTO $tableQ ($columns) VALUES $values";
                }
            } else {
                // Update on conflict
                $updateColumnStr = $this->buildUpdateSetString($updateColumns, $this->driverName, $table);
                if ($this->usesPgConflictSyntax()) {
                    $query = "INSERT INTO $tableQ ($columns) VALUES $values"
                        . " ON CONFLICT ($conflictColumnStr) DO UPDATE SET $updateColumnStr";
                } else {
                    $query = "INSERT INTO $tableQ ($columns) VALUES $values ON DUPLICATE KEY UPDATE $updateColumnStr";
                }
            }

            $this->logger->debug('insert or update group:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($data as $placeholder => $paramVal) {
                $stmt->bindTypedValue($placeholder, $paramVal);
            }
            $stmt->getStmt()->execute();
        } catch (PDOException $ex) {
            throw new CDOException(
                'Error when upserting records in the database (' . $ex->getMessage() . ')',
                previous: $ex
            );
        }
    }

    /**
     * Build SET clause string for upsert query
     *
     * Transforms column expressions to use database-specific syntax:
     * - PostgreSQL: EXCLUDED.column
     * - MySQL: VALUES(column)
     *
     * @param array|null $updateColumns Columns with expressions ['column' => 'expression']
     * @param string $driver Database driver ('pgsql' or 'mysql')
     *
     * @return string SET clause string (e.g., "name = EXCLUDED.name, price = EXCLUDED.price")
     */
    /**
     * Build SET clause string for upsert query
     *
     * Use `:new` placeholder in expressions to reference the new (incoming) value.
     * The placeholders will be replaced with database-specific syntax:
     * - `:new` - new value (PostgreSQL: EXCLUDED.column, MySQL: VALUES(column))
     * - `:current` - current table value (PostgreSQL: table.column, MySQL: column)
     *
     * @param array|null $updateColumns Columns with expressions ['column' => 'expression']
     *                                   Use `:new` to reference the new value
     *                                   Use `:current` to reference the current table value
     * @param string $driver Database driver ('pgsql' or 'mysql')
     * @param string $table Table name for qualifying column references
     *
     * @return string SET clause string
     *
     * Example:
     * ```
     * [
     *     'cost' => ':new',                 // cost = EXCLUDED.cost (replace with new value)
     *     'quantity' => ':current + :new',  // quantity = table.quantity + EXCLUDED.quantity (add to current)
     *     'updated_at' => 'NOW()'           // updated_at = NOW() (use SQL function)
     * ]
     * ```
     */
    /**
     * Reject a plain list where a column => expression map is expected.
     *
     * `['qty', 'created_at']` is a natural thing to write — it is exactly the shape
     * Laravel's `upsert()` takes for the same argument — but here it means nothing:
     * the keys become 0 and 1, and the database answers `no such column: 0` from deep
     * inside the generated SQL, pointing at the schema instead of at the call.
     *
     * Both shapes could be accepted (an integer key is unambiguously a list), and that
     * was considered. It was turned down because the shorthand only ever covers the
     * trivial `:new` case: the moment an expression is needed — `:current + :new`,
     * `NOW()`, `LEAST(:current, :new)` — the map is required anyway. So the saving is a
     * few characters, while the cost is two accepted shapes to document, test and
     * recognise in review, forever. One shape, and an error that teaches the other half
     * of the API, is the better trade.
     *
     * An empty map or null is left alone: that is the documented way to say
     * DO NOTHING / INSERT IGNORE.
     *
     * @throws CDOException If any key is an integer, i.e. a list was passed.
     */
    private function assertUpdateColumns(?array $updateColumns): void
    {
        if ($updateColumns === null || $updateColumns === []) {
            return;
        }

        foreach ($updateColumns as $key => $value) {
            if (!is_int($key)) {
                continue;
            }

            $suggestion = is_string($value)
                ? sprintf("['%s' => ':new']", $value)
                : "['column' => ':new']";

            throw new CDOException(sprintf(
                'updateColumns expects a column => expression map, got a plain list at '
                . 'position %d. Did you mean %s? Use \':new\' for the incoming value and '
                . '\':current\' for the stored one; pass an empty array to ignore conflicts.',
                $key,
                $suggestion,
            ));
        }
    }

    private function buildUpdateSetString(?array $updateColumns, string $driver, string $table): string
    {
        if (empty($updateColumns)) {
            return '';
        }

        $updateParts = [];

        foreach ($updateColumns as $column => $expression) {
            $quotedColumn = $this->quoteIdentifier((string) $column);
            if ($driver === 'pgsql' || $driver === 'sqlite') {
                // PostgreSQL and SQLite both reference the incoming row as
                // EXCLUDED.column and the existing row as table.column.
                $prefixedExpression = str_replace(':new', "EXCLUDED.$quotedColumn", $expression);
                $prefixedExpression = str_replace(
                    ':current',
                    $this->quoteIdentifier($table) . '.' . $quotedColumn,
                    $prefixedExpression
                );
            } else {
                $prefixedExpression = str_replace(':new', "VALUES($quotedColumn)", $expression);
                $prefixedExpression = str_replace(':current', $quotedColumn, $prefixedExpression);
            }
            $updateParts[] = "$quotedColumn = $prefixedExpression";
        }

        return implode(', ', $updateParts);
    }

    /**
     * Convert a timezone identifier to a UTC offset string
     *
     * Resolves the current offset (e.g. "+03:00") for the given timezone,
     * suitable for MySQL's `SET time_zone` statement.
     *
     * @param string $timezone PHP timezone identifier
     *
     * @return string|null UTC offset in "+HH:MM" format, or null if the timezone is invalid
     */
    private function timezoneToOffset(string $timezone): ?string
    {
        try {
            $tz = new \DateTimeZone($timezone);
            $dt = new \DateTime('now', $tz);
            return $dt->format('P');
        } catch (DateInvalidTimeZoneException | DateMalformedStringException $e) {
            return null;
        }
    }
}
