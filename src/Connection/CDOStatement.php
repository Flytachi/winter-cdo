<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Connection;

use PDO;
use PDOStatement;

/**
 * CDOStatement — Type-Aware PDO Statement Wrapper
 *
 * Wraps a `PDOStatement` and adds automatic PDO type detection via
 * {@see bindTypedValue()}, which maps PHP types to the correct `PDO::PARAM_*`
 * constants so you never bind an integer as a string or a boolean as `"1"`.
 *
 * All bindings are recorded internally so the statement can be
 * **re-applied** to a new `PDOStatement` via {@see updateStm()} — useful
 * when a connection is recycled or a statement is re-prepared.
 *
 * **Type mapping applied by `bindTypedValue`:**
 *
 * | PHP type | PDO constant | Notes |
 * |----------|-------------|-------|
 * | `null` | `PARAM_NULL` | |
 * | `bool` | `PARAM_BOOL` | |
 * | `int` | `PARAM_INT` | |
 * | `array` | `PARAM_STR` | JSON-encoded before binding |
 * | `object` | `PARAM_STR` | See `valObject()` for dispatch rules |
 * | `float`, `string` | `PARAM_STR` | Default |
 *
 * @package Flytachi\Winter\Cdo\Connection
 * @author  Flytachi
 */
class CDOStatement
{
    private PDOStatement $stmt;
    private array $bindings = [];

    /**
     * Wrap an existing PDOStatement.
     *
     * @param PDOStatement $stmt The prepared statement to wrap.
     */
    public function __construct(PDOStatement $stmt)
    {
        $this->stmt = $stmt;
    }

    /**
     * Bind a value with an explicit PDO type constant.
     *
     * Delegates directly to `PDOStatement::bindValue()` and records the
     * binding for later replay via {@see updateStm()}.
     *
     * @param string|int $parameter Placeholder name (`:foo`) or positional index.
     * @param mixed      $value     Value to bind.
     * @param int        $data_type One of the `PDO::PARAM_*` constants.
     * @return bool `true` on success, `false` on failure.
     */
    public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR): bool
    {
        $this->bindings[] = [$parameter, $value, $data_type];
        return $this->stmt->bindValue($parameter, $value, $data_type);
    }

    /**
     * Bind a value with automatic PHP-type detection.
     *
     * Inspects `gettype($value)` and selects the appropriate `PDO::PARAM_*`:
     *
     * - `null`    → `PDO::PARAM_NULL`
     * - `bool`    → `PDO::PARAM_BOOL`
     * - `int`     → `PDO::PARAM_INT`
     * - `array`   → `PDO::PARAM_STR` (JSON-encoded)
     * - `object`  → `PDO::PARAM_STR` (serialised via {@see valObject()})
     * - everything else → `PDO::PARAM_STR`
     *
     * @param string $parameter Placeholder name, e.g. `':user_id'`.
     * @param mixed  $value     The value to bind.
     * @return bool `true` on success, `false` on failure.
     */
    public function bindTypedValue(string $parameter, mixed $value): bool
    {
        return match (gettype($value)) {
            'NULL' => $this->bindValue($parameter, $value, PDO::PARAM_NULL),
            'boolean' => $this->bindValue($parameter, $value, PDO::PARAM_BOOL),
            'integer' => $this->bindValue($parameter, $value, PDO::PARAM_INT),
            'array' => $this->bindValue($parameter, json_encode($value)),
            'object' => $this->bindValue($parameter, $this->valObject($value)),
            default => $this->bindValue($parameter, $value),
        };
    }

    /**
     * Serialise an object to a scalar suitable for PDO string binding.
     *
     * Dispatch priority (first matching interface wins):
     *
     * 1. `JsonSerializable` → `$value->jsonSerialize()`
     * 2. `Stringable`       → `(string) $value`
     * 3. `DateTimeInterface`→ `$value->format('Y-m-d H:i:s')`
     * 4. `BackedEnum`       → `$value->value`
     * 5. anything else      → `serialize($value)` (PHP serialisation)
     *
     * @param object $value Object to convert.
     * @return mixed A scalar representation of the object.
     */
    public function valObject(object $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        } elseif ($value instanceof \Stringable) {
            return (string) $value;
        } elseif ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        } elseif ($value instanceof \BackedEnum) {
            return $value->value;
        } else {
            return serialize($value);
        }
    }

    /**
     * Returns all recorded bindings in insertion order.
     *
     * Each entry is a three-element array: `[$parameter, $value, $pdoType]`.
     * Used internally by {@see updateStm()} to replay bindings onto a new
     * statement.
     *
     * @return array<int, array{0: string|int, 1: mixed, 2: int}>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Replace the underlying PDOStatement and replay all recorded bindings.
     *
     * Useful when a connection is recycled (e.g. after a reconnect) and the
     * original statement handle is no longer valid — the new statement is
     * re-bound with exactly the same values.
     *
     * @param PDOStatement $stmt The new prepared statement to bind against.
     */
    public function updateStm(PDOStatement $stmt): void
    {
        $this->stmt = $stmt;
        foreach ($this->bindings as $binding) {
            $this->stmt->bindValue(...$binding);
        }
    }

    /**
     * Returns the wrapped PDOStatement.
     *
     * Use this to call `execute()`, `fetch()`, `fetchAll()`, `rowCount()`,
     * `fetchColumn()`, etc. directly on the underlying statement.
     *
     * @return PDOStatement
     */
    public function getStmt(): PDOStatement
    {
        return $this->stmt;
    }
}
