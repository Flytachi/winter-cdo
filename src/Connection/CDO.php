<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Connection;

use Flytachi\Winter\Base\Log\LoggerRegistry;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;

/**
 * CDO - Connection Data Object
 *
 * Extended PDO wrapper with convenient methods for database operations.
 * Supports PostgreSQL, MySQL/MariaDB, and Oracle databases.
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
 * $cdo->insertGroup('users', $usersArray, chunkSize: 500);
 * ```
 *
 * @package Flytachi\Winter\Cdo\Connection
 * @author Flytachi
 * @version 2.0
 */
class CDO extends PDO
{
    private LoggerInterface $logger;

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
        $this->logger = LoggerRegistry::instance('CDO');
        try {
            parent::__construct($config->getDNS(), $config->getUsername(), $config->getPassword());
            $this->setAttribute(PDO::ATTR_TIMEOUT, $timeout);
            $this->setAttribute(PDO::ATTR_PERSISTENT, $config->getPersistentStatus());
            $this->applyDatabase();

            if ($debug) {
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
            $this->logger->debug('connection:' . $config->getDns());
        } catch (PDOException $e) {
            throw new CDOException($e->getMessage(), previous: $e);
        }
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
        $col = implode(",", array_keys($data));
        $val = ":" . implode(",:", array_keys($data));

        try {
            $driver = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
            $primaryKey = array_key_first((array) $entity);

            if ($driver === 'pgsql') {
                $query = "INSERT INTO $table ($col) VALUES ($val) RETURNING $primaryKey";
                $this->logger->debug('insert:' . $query);

                $stmt = new CDOStatement($this->prepare($query));
                foreach ($data as $keyVal => $paramVal) {
                    $stmt->bindTypedValue(':' . $keyVal, $paramVal);
                }
                $stmt->getStmt()->execute();
                $result = $stmt->getStmt()->fetchColumn();
            } else {
                $query = "INSERT INTO $table ($col) VALUES ($val)";
                $this->logger->debug('insert:' . $query);

                $stmt = new CDOStatement($this->prepare($query));
                foreach ($data as $keyVal => $paramVal) {
                    $stmt->bindTypedValue(':' . $keyVal, $paramVal);
                }
                $stmt->getStmt()->execute();
                $result = $this->lastInsertId();
            }

            if (!$result) {
                throw new CDOException('Error when creating a record in the database (' . $result . ')');
            }
            return $result;
        } catch (PDOException $ex) {
            throw new CDOException(
                'Error when creating a record in the database (' . $ex->getMessage() . ')',
                previous: $ex
            );
        }
    }

    /**
     * Insert multiple records into the database
     *
     * Performs batch insert with automatic chunking to avoid hitting
     * database limits (max placeholders, packet size).
     *
     * Note: NULL values are automatically excluded from each record.
     *
     * @param string $table Table name
     * @param array $entities Array of entities (objects or arrays)
     * @param int $chunkSize Number of records per INSERT query (default: 1000)
     *
     * @throws CDOException If any insert fails
     *
     * Example:
     * ```
     * $users = [
     *     ['name' => 'John', 'email' => 'john@example.com'],
     *     ['name' => 'Jane', 'email' => 'jane@example.com'],
     *     // ... thousands more
     * ];
     *
     * // Insert with default chunk size (1000)
     * $cdo->insertGroup('users', $users);
     *
     * // Insert with custom chunk size
     * $cdo->insertGroup('users', $users, chunkSize: 500);
     * ```
     */
    final public function insertGroup(string $table, array $entities, int $chunkSize = 1000): void
    {
        if (empty($entities)) {
            return;
        }

        foreach (array_chunk($entities, $chunkSize) as $chunk) {
            $this->insertChunk($table, $chunk);
        }
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

        $data = is_object($entity) ? get_object_vars($entity) : $entity;
        foreach ($data as $key => $value) {
            if (is_null($value)) {
                unset($data[$key]);
            }
        }

        $columns = implode(",", array_keys($data));
        $placeholders = ":" . implode(",:", array_keys($data));
        $primaryKey = array_key_first((array) $entity);

        try {
            $driver = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
            $conflictColumnStr = implode(',', $conflictColumns);

            if (empty($updateColumns)) {
                if ($driver === 'pgsql') {
                    $query = "INSERT INTO $table ($columns) VALUES ($placeholders) ON CONFLICT ($conflictColumnStr) DO NOTHING RETURNING $primaryKey";
                } else {
                    $query = "INSERT IGNORE INTO $table ($columns) VALUES ($placeholders)";
                }
            } else {
                $updateColumnStr = $this->buildUpdateSetString($updateColumns, $driver, $table);
                if ($driver === 'pgsql') {
                    $query = "INSERT INTO $table ($columns) VALUES ($placeholders) ON CONFLICT ($conflictColumnStr) DO UPDATE SET $updateColumnStr RETURNING $primaryKey";
                } else {
                    $query = "INSERT INTO $table ($columns) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateColumnStr";
                }
            }

            $this->logger->debug('upsert:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($data as $keyVal => $paramVal) {
                $stmt->bindTypedValue(':' . $keyVal, $paramVal);
            }
            $stmt->getStmt()->execute();

            if ($driver === 'pgsql') {
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
        $set = "";
        foreach ($data as $key => $value) {
            $data[":S_$key"] = $value;
            unset($data[$key]);
            $set .= ",$key=:S_$key";
        }

        try {
            $query = "UPDATE $table SET " . ltrim($set, ", ") . " WHERE " . $qb->getQuery();
            $this->logger->debug('update:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ([...$qb->getCache(), ...$data] as $keyVal => $paramVal) {
                $stmt->bindTypedValue((string) $keyVal, $paramVal);
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
            $query = "DELETE FROM $table WHERE " . $qb->getQuery();
            $this->logger->debug('delete:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($qb->getCache() as $keyVal => $paramVal) {
                $stmt->bindTypedValue($keyVal, $paramVal);
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
     * @param string $table Table name
     * @param array $entities Array of entities (objects or arrays)
     * @param array $conflictColumns Columns that define uniqueness (e.g., ['id'] or ['warehouse_id', 'product_id'])
     * @param array|null $updateColumns Columns to update on conflict: ['column' => 'expression']
     *                                   If null or empty - conflicts are ignored (DO NOTHING / INSERT IGNORE)
     * @param int $chunkSize Records per query (default: 500)
     *
     * @throws CDOException If conflictColumns is empty or query fails
     *
     * Example: Insert only new records (ignore duplicates)
     * ```
     * // PostgreSQL: ON CONFLICT (email) DO NOTHING
     * // MySQL: INSERT IGNORE INTO ...
     * $cdo->upsertGroup(
     *     'users',
     *     $users,
     *     ['email']  // no updateColumns - just ignore duplicates
     * );
     * ```
     *
     * Example: Basic upsert (replace values)
     * ```
     * $cdo->upsertGroup(
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
     * $cdo->upsertGroup(
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
     * $cdo->upsertGroup(
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
    final public function upsertGroup(
        string $table,
        array $entities,
        array $conflictColumns,
        ?array $updateColumns = null,
        int $chunkSize = 500
    ): void {
        if (empty($entities)) {
            return;
        }
        if (empty($conflictColumns)) {
            throw new CDOException('conflictColumns is empty');
        }

        foreach (array_chunk($entities, $chunkSize) as $chunk) {
            $this->upsertChunk($table, $chunk, $conflictColumns, $updateColumns);
        }
    }

    // ==================== Private Methods ====================

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
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->applyDatabaseTimezone($driver, date_default_timezone_get());
    }

    /**
     * Set database timezone to match PHP timezone
     *
     * @param mixed $driver Database driver name
     * @param string $tz PHP timezone identifier
     */
    private function applyDatabaseTimezone(mixed $driver, string $tz): void
    {
        switch ($driver) {
            case 'pgsql':
                $this->exec("SET TIMEZONE TO " . $this->quote($tz));
                break;
            case 'mysql':
                $offset = timezoneToOffset($tz);
                if ($offset !== null) {
                    $this->exec("SET time_zone = " . $this->quote($offset));
                }
                break;
            case 'oci':
                $this->exec("ALTER SESSION SET TIME_ZONE = " . $this->quote($tz));
                break;
            default:
                $this->logger->warning("Timezone setting not implemented for driver: $driver");
                break;
        }
    }

    /**
     * Insert a chunk of entities
     *
     * @param string $table Table name
     * @param array $entities Chunk of entities to insert
     *
     * @throws CDOException If insert fails
     */
    private function insertChunk(string $table, array $entities): void
    {
        $data = [];
        $prefix = 0;
        $val = '';
        $col = '';

        foreach ($entities as $entity) {
            $items = is_object($entity) ? get_object_vars($entity) : $entity;
            foreach ($items as $key => $value) {
                if (is_null($value)) {
                    unset($items[$key]);
                }
            }
            $col = implode(",", array_keys($items));
            $newKeys = array_map(fn($oldKey) => $oldKey . '_' . $prefix, array_keys($items));
            $items = array_combine($newKeys, array_values($items));
            foreach ($items as $key => $value) {
                if (is_null($value)) {
                    unset($items[$key]);
                }
            }
            $val .= '(:' . implode(",:", array_keys($items)) . '),';
            ++$prefix;
            $data = array_merge($data, $items);
        }

        try {
            $query = "INSERT INTO $table ($col) VALUES " . rtrim($val, ',');
            $this->logger->debug('insert group:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($data as $keyVal => $paramVal) {
                $stmt->bindTypedValue(':' . $keyVal, $paramVal);
            }
            $stmt->getStmt()->execute();
        } catch (PDOException $ex) {
            throw new CDOException(
                'Error when creating records in the database (' . $ex->getMessage() . ')',
                previous: $ex
            );
        }
    }

    /**
     * Upsert a chunk of entities
     *
     * @param string $table Table name
     * @param array $entities Chunk of entities to upsert
     * @param array $conflictColumns Columns to check for conflict
     * @param array|null $updateColumns Columns to update on conflict
     *
     * @throws CDOException If upsert fails
     */
    private function upsertChunk(
        string $table,
        array $entities,
        array $conflictColumns,
        ?array $updateColumns
    ): void {
        $data = [];
        $prefix = 0;
        $columns = '';
        $values = '';

        foreach ($entities as $entity) {
            $items = is_object($entity) ? get_object_vars($entity) : $entity;
            foreach ($items as $key => $val) {
                if (is_null($val)) {
                    unset($items[$key]);
                }
            }
            $columns = implode(",", array_keys($items));
            $newKeys = array_map(fn($oldKey) => $oldKey . '_' . $prefix, array_keys($items));
            $items = array_combine($newKeys, array_values($items));
            foreach ($items as $key => $val) {
                if (is_null($val)) {
                    unset($items[$key]);
                }
            }
            $values .= '(:' . implode(",:", array_keys($items)) . '),';
            ++$prefix;
            $data = array_merge($data, $items);
        }
        $values = rtrim($values, ',');

        try {
            $driver = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
            $conflictColumnStr = implode(',', $conflictColumns);

            if (empty($updateColumns)) {
                // No update - just ignore conflicts
                if ($driver === 'pgsql') {
                    $query = "INSERT INTO $table ($columns) VALUES $values ON CONFLICT ($conflictColumnStr) DO NOTHING";
                } else {
                    $query = "INSERT IGNORE INTO $table ($columns) VALUES $values";
                }
            } else {
                // Update on conflict
                $updateColumnStr = $this->buildUpdateSetString($updateColumns, $driver, $table);
                if ($driver === 'pgsql') {
                    $query = "INSERT INTO $table ($columns) VALUES $values ON CONFLICT ($conflictColumnStr) DO UPDATE SET $updateColumnStr";
                } else {
                    $query = "INSERT INTO $table ($columns) VALUES $values ON DUPLICATE KEY UPDATE $updateColumnStr";
                }
            }

            $this->logger->debug('insert or update group:' . $query);

            $stmt = new CDOStatement($this->prepare($query));
            foreach ($data as $keyVal => $paramVal) {
                $stmt->bindTypedValue(':' . $keyVal, $paramVal);
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
    private function buildUpdateSetString(?array $updateColumns, string $driver, string $table): string
    {
        if (empty($updateColumns)) {
            return '';
        }

        $updateParts = [];

        foreach ($updateColumns as $column => $expression) {
            if ($driver === 'pgsql') {
                $prefixedExpression = str_replace(':new', "EXCLUDED.$column", $expression);
                $prefixedExpression = str_replace(':current', "$table.$column", $prefixedExpression);
            } else {
                $prefixedExpression = str_replace(':new', "VALUES($column)", $expression);
                $prefixedExpression = str_replace(':current', $column, $prefixedExpression);
            }
            $updateParts[] = "$column = $prefixedExpression";
        }

        return implode(', ', $updateParts);
    }
}
