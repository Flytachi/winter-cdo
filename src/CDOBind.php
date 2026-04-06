<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo;

/**
 * CDOBind — Named Parameter Container
 *
 * An immutable value object that pairs a named PDO placeholder with the value
 * to be bound when executing a prepared statement.
 *
 * CDOBind is produced automatically by {@see Qb} for every scalar value.
 * You can also create one manually to:
 *  - give the placeholder a **meaningful name** (instead of `:iqb0`)
 *  - **share one placeholder** across multiple `Qb` conditions
 *
 * ```
 * // Auto-generated placeholder:
 * Qb::eq('id', 42)
 * // SQL: id = :iqb0   (opaque name)
 *
 * // Named bind — explicit, readable:
 * $bind = new CDOBind('user_id', 42);
 * Qb::eq('id', $bind)
 * // SQL: id = :user_id
 * ```
 *
 * The `:` prefix is added automatically if omitted in the constructor.
 *
 * @package Flytachi\Winter\Cdo
 * @author  Flytachi
 */
readonly class CDOBind
{
    private string $name;
    private mixed $value;

    /**
     * Create a named bind parameter.
     *
     * If `$name` does not start with `:`, the prefix is added automatically:
     * `'user_id'` → `':user_id'`.
     *
     * @param string $name  Placeholder name (with or without leading `:`).
     * @param mixed  $value Value to bind. Any PHP type accepted by
     *                      {@see CDOStatement::bindTypedValue()} is valid.
     */
    public function __construct(
        string $name,
        mixed $value
    ) {
        $this->name = str_starts_with($name, ':') ? $name : ':' . $name;
        $this->value = $value;
    }

    /**
     * Returns the placeholder name, always prefixed with `:`.
     *
     * Example: `':user_id'`
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the value associated with this placeholder.
     *
     * @return mixed
     */
    public function getValue(): mixed
    {
        return $this->value;
    }
}
