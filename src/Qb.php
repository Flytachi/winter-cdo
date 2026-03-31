<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo;

/**
 * Class Qb
 *
 * `Qb` is a utility class that generates and holds SQL conditions.
 * It provides methods to build various SQL conditions and logical operators.
 *
 * @version 4.3
 * @author Flytachi
 */
final class Qb
{
    private static int $placeholderCounter = 0;

    private string $query;
    private array $cache;

    private function __construct(string $query, array $cache)
    {
        $this->query = $query;
        $this->cache = $cache;
    }

    public function addAnd(Qb $qb): void
    {
        $this->add($qb, 'AND');
    }

    public function addOr(Qb $qb): void
    {
        $this->add($qb, 'OR');
    }

    public function addXor(Qb $qb): void
    {
        $this->add($qb, 'XOR');
    }

    private function add(Qb $qb, string $operator): void
    {
        $this->query .= (empty($this->query) ? '' : " $operator ") . $qb->query;
        $this->cache = array_merge($this->cache, $qb->cache);
    }

    /**
     * Returns the prepared SQL condition query and the cache.
     *
     * @return array
     */
    public function getData(): array
    {
        return [
            'query' => $this->query,
            'cache' => $this->cache,
        ];
    }

    /**
     * Returns the prepared SQL condition query.
     *
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Returns the parameters that were constructed for the condition.
     *
     * @return array
     */
    public function getCache(): array
    {
        return $this->cache;
    }

    /**
     * Equal to operator.
     *
     * @param string $column The column name.
     * @param bool|int|float|string|null $value The value to compare.
     * @return Qb
     */
    public static function eq(string $column, bool|int|float|string|null $value): Qb
    {
        if ($value === null) {
            return self::isNull($column);
        }
        if (is_bool($value)) {
            return new self("{$column} IS " . ($value ? 'TRUE' : 'FALSE'), []);
        }
        $hash = self::inject($value);
        return new self("{$column} = {$hash}", [$hash => $value]);
    }

    /**
     * Not equal to operator.
     *
     * @param string $column The column name.
     * @param bool|int|float|string|null $value The value to compare.
     * @return Qb
     */
    public static function neq(string $column, bool|int|float|string|null $value): Qb
    {
        if ($value === null) {
            return self::isNotNull($column);
        }
        if (is_bool($value)) {
            return new self("{$column} IS NOT " . ($value ? 'TRUE' : 'FALSE'), []);
        }
        $hash = self::inject($value);
        return new self("{$column} != {$hash}", [$hash => $value]);
    }

    /**
     * Greater than operator.
     *
     * @param string $column The column name.
     * @param int|float|string $value The value to compare.
     * @return Qb
     */
    public static function gt(string $column, int|float|string $value): Qb
    {
        $hash = self::inject($value);
        return new self("{$column} > {$hash}", [$hash => $value]);
    }

    /**
     * Greater than or equal to operator.
     *
     * @param string $column The column name.
     * @param int|float|string $value The value to compare.
     * @return Qb
     */
    public static function gte(string $column, int|float|string $value): Qb
    {
        $hash = self::inject($value);
        return new self("{$column} >= {$hash}", [$hash => $value]);
    }

    public static function geq(string $column, int|float|string $value): Qb
    {
        return self::gte($column, $value);
    }

    /**
     * Less than operator.
     *
     * @param string $column The column name.
     * @param int|float|string $value The value to compare.
     * @return Qb
     */
    public static function lt(string $column, int|float|string $value): Qb
    {
        $hash = self::inject($value);
        return new self("{$column} < {$hash}", [$hash => $value]);
    }

    /**
     * Less than or equal to the operator.
     *
     * @param string $column The column name.
     * @param int|float|string $value The value to compare.
     * @return Qb
     */
    public static function lte(string $column, int|float|string $value): Qb
    {
        $hash = self::inject($value);
        return new self("{$column} <= {$hash}", [$hash => $value]);
    }

    public static function leq(string $column, int|float|string $value): Qb
    {
        return self::lte($column, $value);
    }

    /**
     * NULL-safe equal to operator.
     *
     * @param string $column The column name.
     * @param int|float|string $value The value to compare.
     * @return Qb
     */
    public static function nsEq(string $column, int|float|string $value): Qb
    {
        $hash = self::inject($value);
        return new self("{$column} <=> {$hash}", [$hash => $value]);
    }

    /**
     * NULL value test.
     *
     * @param string $column The column name.
     * @return Qb
     */
    public static function isNull(string $column): Qb
    {
        return new self("{$column} IS NULL", []);
    }

    /**
     * NOT NULL value test.
     *
     * @param string $column The column name.
     * @return Qb
     */
    public static function isNotNull(string $column): Qb
    {
        return new self("{$column} IS NOT NULL", []);
    }

    /**
     * Whether a value is within a set of values.
     *
     * @param string $column The column name.
     * @param array $values The list of values.
     * @return Qb
     */
    public static function in(string $column, array $values): Qb
    {
        if (empty($values)) {
            return self::empty();
        }
        $data = self::prepareIn($values);
        return new self("{$column} IN ({$data['query']})", $data['cache']);
    }

    /**
     * Whether a value is not within a set of values.
     *
     * @param string $column The column name.
     * @param array $values The list of values.
     * @return Qb
     */
    public static function inNot(string $column, array $values): Qb
    {
        if (empty($values)) {
            return self::empty();
        }
        $data = self::prepareIn($values);
        return new self("{$column} NOT IN ({$data['query']})", $data['cache']);
    }

    /**
     * Simple pattern matching
     *
     * Parsed: $column LIKE $value
     *
     * Note: Pattern matching using an SQL pattern. Returns 1 (TRUE)
     * or 0 (FALSE). If either expr or pat is NULL, the result is NULL.
     *
     * Case-insensitive mode ($insensitive = true):
     *   - PostgreSQL: uses ILIKE operator (native support)
     *   - MySQL:      NOT required — LIKE is case-insensitive by default
     *                 for non-binary string columns. Using ILIKE will cause an error.
     *   - Oracle:     NOT supported — use REGEXP_LIKE(column, value, 'i')
     *                 or set NLS_COMP=LINGUISTIC and NLS_SORT=BINARY_CI at session level.
     *
     * @param string $column      Name of the column in the table
     * @param string $value       String value to match against
     * @param bool   $insensitive Use ILIKE instead of LIKE (PostgreSQL only)
     *
     * @return Qb
     */
    public static function like(string $column, string $value, bool $insensitive = false): Qb
    {
        $hash = self::inject($value);
        return new self("{$column} "
            . ($insensitive ? 'ILIKE' : 'LIKE')
            . " {$hash}", [$hash => $value]);
    }

    /**
     * Negation of simple pattern matching
     *
     * Parsed: $column NOT LIKE $value
     *
     * Case-insensitive mode ($insensitive = true):
     *   - PostgreSQL: uses NOT ILIKE operator (native support)
     *   - MySQL:      NOT required — LIKE is case-insensitive by default
     *                 for non-binary string columns. Using NOT ILIKE will cause an error.
     *   - Oracle:     NOT supported — use NOT REGEXP_LIKE(column, value, 'i')
     *                 or set NLS_COMP=LINGUISTIC and NLS_SORT=BINARY_CI at session level.
     *
     * @param string $column      Name of the column in the table
     * @param string $value       String value to match against
     * @param bool   $insensitive Use NOT ILIKE instead of NOT LIKE (PostgreSQL only)
     *
     * @return Qb
     */
    public static function likeNot(string $column, string $value, bool $insensitive = false): Qb
    {
        $hash = self::inject($value);
        return new self("{$column} NOT "
            . ($insensitive ? 'ILIKE' : 'LIKE')
            . " {$hash}", [$hash => $value]);
    }

    /**
     * BETWEEN operator
     *
     * Parsed: $column BETWEEN $valueMin AND $valueMax
     *
     * Note: The BETWEEN operator is used to select values within a range.
     * The values can be numbers, text, or dates.
     *
     * @param string $column name of the column in the table
     * @param int|float|string $valueMin - minimum value
     * @param int|float|string $valueMax - maximum value
     *
     * @return Qb
     */
    public static function between(string $column, string|int|float $valueMin, string|int|float $valueMax): Qb
    {
        $hashMin = self::inject($valueMin);
        $hashMax = self::inject($valueMax);
        return new self("{$column} BETWEEN {$hashMin} AND {$hashMax}", [
            $hashMin => $valueMin,
            $hashMax => $valueMax,
        ]);
    }

    /**
     * BETWEEN operator with column values
     *
     * Parsed: $value BETWEEN $column1 AND $column2
     *
     * Note: The BETWEEN operator is used to search for values that are within a specified range.
     * The values can be numbers or dates.
     *
     * @param string|int|float $value value to be compared
     * @param string $column1 name of the first column in the table
     * @param string $column2 name of the second column in the table
     *
     * @return Qb
     */
    public static function betweenBy(string|int|float $value, string $column1, string $column2): Qb
    {
        $hash = self::inject($value);
        return new self("{$value} BETWEEN {$column1} AND {$column2}", [
            $hash => $value,
        ]);
    }

    /**
     * NOT between operator
     *
     * Parsed: $column NOT BETWEEN $valueMin AND $valueMax
     *
     * Note: This operator returns true if the operand on the left
     * is NOT within the range of the operands on the right.
     *
     * @param string $column name of the column in the table
     * @param int|float|string $valueMin minimum value
     * @param int|float|string $valueMax maximum value
     *
     * @return Qb
     */
    public static function betweenNot(string $column, string|int|float $valueMin, string|int|float $valueMax): Qb
    {
        $hashMin = self::inject($valueMin);
        $hashMax = self::inject($valueMax);
        return new self("{$column} NOT BETWEEN {$hashMin} AND {$hashMax}", [
            $hashMin => $valueMin,
            $hashMax => $valueMax,
        ]);
    }

    /**
     * NOT-BETWEEN operator
     *
     * Parsed: $value NOT BETWEEN $column1 AND $column2
     *
     * Note: The NOT-BETWEEN operator checks whether a value is not within a specified range.
     *
     * @param string|int|float $value The value to check if not between the columns
     * @param string $column1 The first column for the comparison
     * @param string $column2 The second column for the comparison
     *
     * @return Qb
     */
    public static function betweenNotBy(string|int|float $value, string $column1, string $column2): Qb
    {
        $hash = self::inject($value);
        return new self("{$value} NOT BETWEEN {$column1} AND {$column2}", [
            $hash => $value,
        ]);
    }

    /**
     * Logical AND operator.
     *
     * @param Qb ...$conditions The conditions to combine.
     * @return Qb
     */
    public static function and(?Qb ...$conditions): Qb
    {
        $data = self::logicalPrepare('AND', $conditions);
        return new self($data['query'], $data['cache']);
    }

    /**
     * Logical OR operator.
     *
     * @param Qb ...$conditions The conditions to combine.
     * @return Qb
     */
    public static function or(?Qb ...$conditions): Qb
    {
        $data = self::logicalPrepare('OR', $conditions);
        return new self($data['query'], $data['cache']);
    }

    /**
     * Logical XOR operator.
     *
     * @param Qb ...$conditions The conditions to combine.
     * @return Qb
     */
    public static function xor(?Qb ...$conditions): Qb
    {
        $data = self::logicalPrepare('XOR', $conditions);
        return new self($data['query'], $data['cache']);
    }

    /**
     * Custom operator CLIP
     *
     * Parsed: ($QlObject)
     *
     * @param Qb $condition The condition to combine.
     *
     * @return Qb
     */
    public static function clip(Qb $condition): Qb
    {
        if (empty($condition->query)) {
            return $condition;
        } else {
            return new self('(' . $condition->query . ')', $condition->cache);
        }
    }

    /**
     * Custom script
     *
     * Warning: Attention be careful! SQL-injection
     * protection will not work in this script
     *
     * @param string $query string
     *
     * @return Qb
     * @internal This is for internal use or advanced scenarios only.
     */
    public static function custom(string $query): Qb
    {
        return new self($query, []);
    }

    /**
     * Empty Ql
     *
     * @return Qb
     */
    public static function empty(): Qb
    {
        return new self('', []);
    }

    /**
     * CASE operator.
     *
     * Generates a SQL CASE expression.
     *
     * @param array $whenThenPairs An associative array of conditions and results (e.g., ['condition' => 'result']).
     * @param string|null $else The default result if no conditions are met.
     * @return Qb
     */
    public static function case(array $whenThenPairs, ?string $else = null): Qb
    {
        $query = 'CASE ';
        $cache = [];

        foreach ($whenThenPairs as $when => $then) {
            $thenHash = self::inject($then);
            $cache[$thenHash] = $then;
            $query .= "WHEN {$when} THEN {$thenHash} ";
        }

        if ($else !== null) {
            $elseHash = self::inject($else);
            $cache[$elseHash] = $else;
            $query .= "ELSE {$elseHash} ";
        }

        $query .= 'END';
        return new self($query, $cache);
    }

    /**
     * Prepares a value for injection into the query.
     *
     * @param string|int|float $value The value to inject.
     * @return string
     */
    private static function inject(string|int|float $value): string
    {
        return ':iqb' . (self::$placeholderCounter++);
    }

    /**
     * Prepares the IN clause.
     *
     * @param array $values The list of values.
     * @return array
     */
    private static function prepareIn(array $values): array
    {
        $cache = [];
        $placeholders = [];

        foreach ($values as $value) {
            $hash = self::inject($value);
            $placeholders[] = $hash;
            $cache[$hash] = $value;
        }

        return [
            'query' => implode(', ', $placeholders),
            'cache' => $cache,
        ];
    }

    /**
     * Prepares logical conditions.
     *
     * @param string $operator The logical operator (AND, OR, XOR).
     * @param array $conditions The conditions to combine.
     * @return array
     */
    private static function logicalPrepare(string $operator, array $conditions): array
    {
        $queryParts = [];
        $cache = [];

        foreach ($conditions as $condition) {
            if ($condition == null || $condition->query === '') {
                continue;
            }
            $queryParts[] = $condition->query;
            $cache = array_merge($cache, $condition->cache);
        }

        return [
            'query' => implode(" {$operator} ", $queryParts),
            'cache' => $cache,
        ];
    }
}
