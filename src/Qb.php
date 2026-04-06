<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo;

/**
 * Class Qb — Query Builder for SQL conditions
 *
 * Qb is an immutable condition builder that produces parameterised SQL fragments.
 * Each static factory method returns a new Qb instance containing two things:
 *   - a SQL string with named placeholders  (e.g. `age > :iqb0`)
 *   - an array of {@see CDOBind} objects that map each placeholder to its value
 *
 * Instances are combined with logical operators ({@see Qb::and()}, {@see Qb::or()},
 * {@see Qb::xor()}, {@see Qb::clip()}) or mutated in-place with
 * {@see Qb::addAnd()}, {@see Qb::addOr()}, {@see Qb::addXor()}.
 *
 * **Named binds (CDOBind)**
 * Every value method accepts a raw scalar *or* a pre-built {@see CDOBind}.
 * Passing a CDOBind lets you reuse the same named placeholder across multiple
 * conditions — both columns will share the same `:name` in the final SQL, so
 * the value is bound exactly once.
 *
 * **Usage example**
 * ```
 * $condition = Qb::and(
 *     Qb::eq('status', 'active'),
 *     Qb::gte('age', 18),
 *     Qb::like('name', '%john%'),
 * );
 * // SQL  : status = :iqb0 AND age >= :iqb1 AND name LIKE :iqb2
 * // Binds: [:iqb0 => 'active', :iqb1 => 18, :iqb2 => '%john%']
 * ```
 *
 * @version 5.0
 * @author  Flytachi
 */
final class Qb
{
    private static int $placeholderCounter = 0;
    private string $query;
    /** @var CDOBind[] */
    private array $binds;

    /**
     * @param string $query
     * @param CDOBind[] $binds
     */
    private function __construct(string $query, array $binds)
    {
        $this->query = $query;
        $this->binds = $binds;
    }

    /**
     * Appends another condition to this instance using AND (mutable).
     *
     * Mutates the current Qb in place.  Use {@see Qb::and()} for an
     * immutable alternative that returns a new instance.
     *
     * ```
     * $qb = Qb::eq('status', 'active');
     * $qb->addAnd(Qb::gte('age', 18));
     * // SQL: status = :iqb0 AND age >= :iqb1
     * ```
     *
     * @param Qb $qb The condition to append.
     */
    public function addAnd(Qb $qb): void
    {
        $this->add($qb, 'AND');
    }

    /**
     * Appends another condition to this instance using OR (mutable).
     *
     * Mutates the current Qb in place.  Use {@see Qb::or()} for an
     * immutable alternative that returns a new instance.
     *
     * ```
     * $qb = Qb::eq('role', 'admin');
     * $qb->addOr(Qb::eq('role', 'moderator'));
     * // SQL: role = :iqb0 OR role = :iqb1
     * ```
     *
     * @param Qb $qb The condition to append.
     */
    public function addOr(Qb $qb): void
    {
        $this->add($qb, 'OR');
    }

    /**
     * Appends another condition to this instance using XOR (mutable).
     *
     * Mutates the current Qb in place.  Use {@see Qb::xor()} for an
     * immutable alternative that returns a new instance.
     *
     * @param Qb $qb The condition to append.
     */
    public function addXor(Qb $qb): void
    {
        $this->add($qb, 'XOR');
    }

    private function add(Qb $qb, string $operator): void
    {
        $this->query .= (empty($this->query) ? '' : " $operator ") . $qb->query;
        $this->binds = array_merge($this->binds, $qb->binds);
    }

    /**
     * Returns both the SQL fragment and the bind list as an associative array.
     *
     * Keys: `query` (string) and `binds` (CDOBind[]).
     * Useful when you need to pass both pieces together to an internal helper.
     *
     * @return array{query: string, binds: CDOBind[]}
     */
    public function getData(): array
    {
        return [
            'query' => $this->query,
            'binds' => $this->binds,
        ];
    }

    /**
     * Returns the SQL fragment with named placeholders.
     *
     * Example output: `"status = :iqb0 AND age >= :iqb1"`
     *
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Returns all bind parameters for this condition.
     *
     * Each {@see CDOBind} holds a named placeholder (e.g. `:iqb0` or a custom
     * name) and the value to be bound.  Pass these to `CDOStatement::bindTypedValue()`
     * or iterate them when executing a prepared statement.
     *
     * @return CDOBind[]
     */
    public function getBinds(): array
    {
        return $this->binds;
    }

    /**
     * @deprecated Use {@see getBinds()} instead. Always returns an empty array.
     * @return array
     */
    public function getCache(): array
    {
        return [];
    }

    /**
     * Equal to operator — `column = value`
     *
     * Special cases:
     *  - `null`  → `column IS NULL`
     *  - `true`  → `column IS TRUE`
     *  - `false` → `column IS FALSE`
     *
     * ```
     * Qb::eq('status', 'active')   // status = :iqb0
     * Qb::eq('deleted_at', null)   // deleted_at IS NULL
     * Qb::eq('is_admin', true)     // is_admin IS TRUE
     * ```
     *
     * @param string                              $column The column name.
     * @param CDOBind|bool|int|float|string|null  $value  The value to compare.
     * @return Qb
     */
    public static function eq(string $column, CDOBind|bool|int|float|string|null $value): Qb
    {
        if ($value === null) {
            return self::isNull($column);
        }
        if (is_bool($value)) {
            return new self("{$column} IS " . ($value ? 'TRUE' : 'FALSE'), []);
        }
        $bind = self::inject($value);
        return new self("{$column} = {$bind->getName()}", [$bind]);
    }

    /**
     * Not equal to operator — `column != value`
     *
     * Special cases:
     *  - `null`  → `column IS NOT NULL`
     *  - `true`  → `column IS NOT TRUE`
     *  - `false` → `column IS NOT FALSE`
     *
     * ```
     * Qb::neq('status', 'banned')  // status != :iqb0
     * Qb::neq('deleted_at', null)  // deleted_at IS NOT NULL
     * ```
     *
     * @param string                              $column The column name.
     * @param CDOBind|bool|int|float|string|null  $value  The value to compare.
     * @return Qb
     */
    public static function neq(string $column, CDOBind|bool|int|float|string|null $value): Qb
    {
        if ($value === null) {
            return self::isNotNull($column);
        }
        if (is_bool($value)) {
            return new self("{$column} IS NOT " . ($value ? 'TRUE' : 'FALSE'), []);
        }
        $bind = self::inject($value);
        return new self("{$column} != {$bind->getName()}", [$bind]);
    }

    /**
     * Greater than operator — `column > value`
     *
     * ```
     * Qb::gt('price', 100)   // price > :iqb0
     * Qb::gt('age', 17.5)    // age > :iqb0
     * ```
     *
     * @param string                    $column The column name.
     * @param CDOBind|int|float|string  $value  The value to compare.
     * @return Qb
     */
    public static function gt(string $column, CDOBind|int|float|string $value): Qb
    {
        $bind = self::inject($value);
        return new self("{$column} > {$bind->getName()}", [$bind]);
    }

    /**
     * Greater than or equal to operator — `column >= value`
     *
     * ```
     * Qb::gte('score', 60)   // score >= :iqb0
     * ```
     *
     * @param string                    $column The column name.
     * @param CDOBind|int|float|string  $value  The value to compare.
     * @return Qb
     */
    public static function gte(string $column, CDOBind|int|float|string $value): Qb
    {
        $bind = self::inject($value);
        return new self("{$column} >= {$bind->getName()}", [$bind]);
    }

    /**
     * Less than operator — `column < value`
     *
     * ```
     * Qb::lt('stock', 10)   // stock < :iqb0
     * ```
     *
     * @param string                    $column The column name.
     * @param CDOBind|int|float|string  $value  The value to compare.
     * @return Qb
     */
    public static function lt(string $column, CDOBind|int|float|string $value): Qb
    {
        $bind = self::inject($value);
        return new self("{$column} < {$bind->getName()}", [$bind]);
    }

    /**
     * Less than or equal to operator — `column <= value`
     *
     * ```
     * Qb::lte('age', 65)   // age <= :iqb0
     * ```
     *
     * @param string                    $column The column name.
     * @param CDOBind|int|float|string  $value  The value to compare.
     * @return Qb
     */
    public static function lte(string $column, CDOBind|int|float|string $value): Qb
    {
        $bind = self::inject($value);
        return new self("{$column} <= {$bind->getName()}", [$bind]);
    }

    /**
     * NULL-safe equal to operator — `column <=> value`  (MySQL / MariaDB)
     *
     * Works like `=` but treats NULL as a comparable value:
     * `NULL <=> NULL` is TRUE, `1 <=> NULL` is FALSE (no error).
     * Equivalent to `IS NOT DISTINCT FROM` in PostgreSQL / standard SQL.
     *
     * ```
     * Qb::nsEq('deleted_at', null)   // deleted_at <=> :iqb0  (binds NULL)
     * Qb::nsEq('score', 0)           // score <=> :iqb0
     * ```
     *
     * @param string                    $column The column name.
     * @param CDOBind|int|float|string  $value  The value to compare (NULL included via CDOBind).
     * @return Qb
     */
    public static function nsEq(string $column, CDOBind|int|float|string $value): Qb
    {
        $bind = self::inject($value);
        return new self("{$column} <=> {$bind->getName()}", [$bind]);
    }

    /**
     * NULL value test — `column IS NULL`
     *
     * ```
     * Qb::isNull('deleted_at')   // deleted_at IS NULL
     * ```
     *
     * @param string $column The column name.
     * @return Qb
     */
    public static function isNull(string $column): Qb
    {
        return new self("{$column} IS NULL", []);
    }

    /**
     * NOT NULL value test — `column IS NOT NULL`
     *
     * ```
     * Qb::isNotNull('email')   // email IS NOT NULL
     * ```
     *
     * @param string $column The column name.
     * @return Qb
     */
    public static function isNotNull(string $column): Qb
    {
        return new self("{$column} IS NOT NULL", []);
    }

    /**
     * Set membership — `column IN (v1, v2, ...)`
     *
     * Returns an empty Qb (no condition) when `$values` is an empty array,
     * so it is safe to pass dynamic lists without extra null-checks.
     *
     * ```
     * Qb::in('status', ['active', 'pending'])
     * // status IN (:iqb0, :iqb1)
     *
     * Qb::in('id', [1, 2, 3])
     * // id IN (:iqb0, :iqb1, :iqb2)
     * ```
     *
     * @param string $column The column name.
     * @param array  $values The list of values.
     * @return Qb
     */
    public static function in(string $column, array $values): Qb
    {
        if (empty($values)) {
            return self::empty();
        }
        $data = self::prepareIn($values);
        return new self("{$column} IN ({$data['query']})", $data['binds']);
    }

    /**
     * Set exclusion — `column NOT IN (v1, v2, ...)`
     *
     * Returns an empty Qb when `$values` is empty (no condition applied).
     *
     * ```
     * Qb::notIn('role', ['banned', 'suspended'])
     * // role NOT IN (:iqb0, :iqb1)
     * ```
     *
     * @param string $column The column name.
     * @param array  $values The list of values.
     * @return Qb
     */
    public static function notIn(string $column, array $values): Qb
    {
        if (empty($values)) {
            return self::empty();
        }
        $data = self::prepareIn($values);
        return new self("{$column} NOT IN ({$data['query']})", $data['binds']);
    }

    /**
     * Pattern matching — `column LIKE value`
     *
     * Uses SQL wildcard patterns: `%` matches any sequence of characters,
     * `_` matches any single character.
     *
     * ```
     * Qb::like('name', '%john%')          // name LIKE :iqb0
     * Qb::like('email', '%@gmail.com')    // email LIKE :iqb0
     * Qb::like('name', '%john%', true)    // name ILIKE :iqb0  (PostgreSQL only)
     * ```
     *
     * **Database compatibility for `$insensitive = true`:**
     *  - PostgreSQL: uses `ILIKE` (native)
     *  - MySQL:      `LIKE` is already case-insensitive for non-binary columns — do NOT set `true`
     *  - Oracle:     not supported — use `REGEXP_LIKE(col, val, 'i')` manually
     *
     * @param string          $column      Column name.
     * @param CDOBind|string  $value       Pattern to match (include `%` / `_` wildcards yourself).
     * @param bool            $insensitive Use ILIKE instead of LIKE (PostgreSQL only).
     * @return Qb
     */
    public static function like(string $column, CDOBind|string $value, bool $insensitive = false): Qb
    {
        $bind = self::inject($value);
        return new self("{$column} "
            . ($insensitive ? 'ILIKE' : 'LIKE') . " {$bind->getName()}", [$bind]);
    }

    /**
     * Negated pattern matching — `column NOT LIKE value`
     *
     * ```
     * Qb::notLike('email', '%@spam.com')        // email NOT LIKE :iqb0
     * Qb::notLike('name', 'bot_%', true)        // name NOT ILIKE :iqb0  (PostgreSQL)
     * ```
     *
     * **Database compatibility for `$insensitive = true`:**
     *  - PostgreSQL: uses `NOT ILIKE` (native)
     *  - MySQL:      do NOT set `true` — `NOT LIKE` is already case-insensitive for non-binary columns
     *  - Oracle:     not supported — use `NOT REGEXP_LIKE(col, val, 'i')` manually
     *
     * @param string          $column      Column name.
     * @param CDOBind|string  $value       Pattern to match (include `%` / `_` wildcards yourself).
     * @param bool            $insensitive Use NOT ILIKE instead of NOT LIKE (PostgreSQL only).
     * @return Qb
     */
    public static function notLike(string $column, CDOBind|string $value, bool $insensitive = false): Qb
    {
        $bind = self::inject($value);
        return new self("{$column} NOT " . ($insensitive ? 'ILIKE' : 'LIKE')
            . " {$bind->getName()}", [$bind]);
    }

    /**
     * Range check — `column BETWEEN min AND max`
     *
     * Both bounds are inclusive. Works with numbers, strings, and dates.
     *
     * ```
     * Qb::between('age', 18, 65)
     * // age BETWEEN :iqb0 AND :iqb1
     *
     * Qb::between('created_at', '2024-01-01', '2024-12-31')
     * // created_at BETWEEN :iqb0 AND :iqb1
     * ```
     *
     * @param string                    $column   Column name.
     * @param CDOBind|int|float|string  $valueMin Lower bound (inclusive).
     * @param CDOBind|int|float|string  $valueMax Upper bound (inclusive).
     * @return Qb
     */
    public static function between(
        string $column,
        CDOBind|string|int|float $valueMin,
        CDOBind|string|int|float $valueMax
    ): Qb {
        $bindMin = self::inject($valueMin);
        $bindMax = self::inject($valueMax);
        return new self(
            "{$column} BETWEEN {$bindMin->getName()} AND {$bindMax->getName()}",
            [$bindMin, $bindMax]
        );
    }

    /**
     * Inverted range check (value between two columns) — `value BETWEEN col1 AND col2`
     *
     * Useful when the range boundaries are stored in columns, not given as literals —
     * e.g. checking whether a date falls within a validity window stored per-row.
     *
     * ```
     * Qb::betweenBy('2024-06-01', 'valid_from', 'valid_to')
     * // :iqb0 BETWEEN valid_from AND valid_to
     * ```
     *
     * @param CDOBind|string|int|float  $value   The scalar value to test.
     * @param string                    $column1 Lower-bound column.
     * @param string                    $column2 Upper-bound column.
     * @return Qb
     */
    public static function betweenBy(CDOBind|string|int|float $value, string $column1, string $column2): Qb
    {
        $bind = self::inject($value);
        return new self(
            "{$bind->getName()} BETWEEN {$column1} AND {$column2}",
            [$bind]
        );
    }

    /**
     * Negated range check — `column NOT BETWEEN min AND max`
     *
     * True when `column` is strictly outside the [min, max] range.
     *
     * ```
     * Qb::notBetween('price', 10, 50)
     * // price NOT BETWEEN :iqb0 AND :iqb1
     * ```
     *
     * @param string                    $column   Column name.
     * @param CDOBind|int|float|string  $valueMin Lower bound (inclusive).
     * @param CDOBind|int|float|string  $valueMax Upper bound (inclusive).
     * @return Qb
     */
    public static function notBetween(
        string $column,
        CDOBind|string|int|float $valueMin,
        CDOBind|string|int|float $valueMax
    ): Qb {
        $bindMin = self::inject($valueMin);
        $bindMax = self::inject($valueMax);
        return new self(
            "{$column} NOT BETWEEN {$bindMin->getName()} AND {$bindMax->getName()}",
            [$bindMin, $bindMax]
        );
    }

    /**
     * Inverted negated range check (value NOT between two columns) — `value NOT BETWEEN col1 AND col2`
     *
     * True when the scalar value falls *outside* the range defined by two columns.
     *
     * ```
     * Qb::notBetweenBy('2020-01-01', 'valid_from', 'valid_to')
     * // :iqb0 NOT BETWEEN valid_from AND valid_to
     * ```
     *
     * @param CDOBind|string|int|float  $value   The scalar value to test.
     * @param string                    $column1 Lower-bound column.
     * @param string                    $column2 Upper-bound column.
     * @return Qb
     */
    public static function notBetweenBy(CDOBind|string|int|float $value, string $column1, string $column2): Qb
    {
        $bind = self::inject($value);
        return new self(
            "{$bind->getName()} NOT BETWEEN {$column1} AND {$column2}",
            [$bind]
        );
    }

    /**
     * Logical AND — `cond1 AND cond2 AND ...`
     *
     * Null or empty conditions are silently skipped, so it is safe to pass
     * optional filters that may produce an empty Qb.
     *
     * ```
     * Qb::and(
     *     Qb::eq('status', 'active'),
     *     Qb::gte('age', 18),
     * )
     * // status = :iqb0 AND age >= :iqb1
     * ```
     *
     * @param Qb|null ...$conditions Conditions to join (nulls are skipped).
     * @return Qb
     */
    public static function and(?Qb ...$conditions): Qb
    {
        $data = self::logicalPrepare('AND', $conditions);
        return new self($data['query'], $data['binds']);
    }

    /**
     * Logical OR — `cond1 OR cond2 OR ...`
     *
     * Null or empty conditions are silently skipped.
     *
     * ```
     * Qb::or(
     *     Qb::eq('role', 'admin'),
     *     Qb::eq('role', 'moderator'),
     * )
     * // role = :iqb0 OR role = :iqb1
     * ```
     *
     * @param Qb|null ...$conditions Conditions to join (nulls are skipped).
     * @return Qb
     */
    public static function or(?Qb ...$conditions): Qb
    {
        $data = self::logicalPrepare('OR', $conditions);
        return new self($data['query'], $data['binds']);
    }

    /**
     * Logical XOR — `cond1 XOR cond2 XOR ...`
     *
     * True when an odd number of conditions are true.
     * Supported natively in MySQL/MariaDB; may require emulation on other DBs.
     *
     * ```
     * Qb::xor(Qb::eq('a', 1), Qb::eq('b', 2))
     * // a = :iqb0 XOR b = :iqb1
     * ```
     *
     * @param Qb|null ...$conditions Conditions to join (nulls are skipped).
     * @return Qb
     */
    public static function xor(?Qb ...$conditions): Qb
    {
        $data = self::logicalPrepare('XOR', $conditions);
        return new self($data['query'], $data['binds']);
    }

    /**
     * Wraps a condition in parentheses — `(condition)`
     *
     * Essential for controlling operator precedence when mixing AND and OR.
     * An empty condition is returned as-is (no wrapping).
     *
     * ```
     * Qb::and(
     *     Qb::eq('status', 'active'),
     *     Qb::clip(
     *         Qb::or(Qb::eq('role', 'admin'), Qb::eq('role', 'moderator'))
     *     ),
     * )
     * // status = :iqb0 AND (role = :iqb1 OR role = :iqb2)
     * ```
     *
     * @param Qb $condition The condition to wrap.
     * @return Qb
     */
    public static function clip(Qb $condition): Qb
    {
        if (empty($condition->query)) {
            return $condition;
        } else {
            return new self('(' . $condition->query . ')', $condition->binds);
        }
    }

    /**
     * Raw SQL fragment — injected verbatim, **NO parameterisation**.
     *
     * Use only when no other Qb method fits (e.g. vendor-specific functions,
     * subquery conditions, raw expressions).  The caller is **fully responsible**
     * for sanitising the string — passing user input here opens SQL-injection.
     *
     * ```
     * Qb::custom('JSON_CONTAINS(tags, \'"php"\')')
     * // JSON_CONTAINS(tags, '"php"')  — raw, no binds
     * ```
     *
     * @param string $query Raw SQL string (no placeholders, no binding).
     * @return Qb
     */
    public static function custom(string $query): Qb
    {
        return new self($query, []);
    }

    /**
     * Creates an empty (no-op) condition.
     *
     * Returned by `in()` / `notIn()` when passed an empty array.
     * Empty conditions are silently ignored by `and()`, `or()`, `xor()`, and
     * the mutable `addAnd()` / `addOr()` / `addXor()` methods.
     *
     * @return Qb
     */
    public static function empty(): Qb
    {
        return new self('', []);
    }

    /**
     * CASE expression — `CASE WHEN ... THEN ... [ELSE ...] END`
     *
     * The keys of `$whenThenPairs` are raw SQL condition strings (no binding);
     * the values are the result literals and **are parameterised** via CDOBind.
     *
     * ```
     * Qb::case([
     *     'score >= 90' => 'A',
     *     'score >= 75' => 'B',
     *     'score >= 60' => 'C',
     * ], else: 'F')
     * // CASE WHEN score >= 90 THEN :iqb0
     * //      WHEN score >= 75 THEN :iqb1
     * //      WHEN score >= 60 THEN :iqb2
     * //      ELSE :iqb3 END
     * ```
     *
     * @param array<string, string>  $whenThenPairs Keys = raw SQL conditions, values = result literals.
     * @param string|null            $else          Default value when no condition matches.
     * @return Qb
     */
    public static function case(array $whenThenPairs, ?string $else = null): Qb
    {
        $query = 'CASE ';
        $binds = [];

        foreach ($whenThenPairs as $when => $then) {
            $thenBind = self::inject($then);
            $binds[] = $thenBind;
            $query .= "WHEN {$when} THEN {$thenBind->getName()} ";
        }

        if ($else !== null) {
            $elseBind = self::inject($else);
            $binds[] = $elseBind;
            $query .= "ELSE {$elseBind->getName()} ";
        }

        $query .= 'END';
        return new self($query, $binds);
    }

    /**
     * Prepares a value for injection into the query.
     *
     * @param CDOBind|string|int|float $value The value to inject.
     * @return CDOBind
     */
    private static function inject(CDOBind|string|int|float $value): CDOBind
    {
        if ($value instanceof CDOBind) {
            return $value;
        } else {
            return new CDOBind(':iqb' . (self::$placeholderCounter++), $value);
        }
    }

    /**
     * Prepares the IN clause.
     *
     * @param array $values The list of values.
     * @return array
     */
    private static function prepareIn(array $values): array
    {
        $binds = [];
        $placeholders = [];

        foreach ($values as $value) {
            $bind = self::inject($value);
            $placeholders[] = $bind->getName();
            $binds[] = $bind;
        }

        return [
            'query' => implode(', ', $placeholders),
            'binds' => $binds,
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
        $binds = [];

        foreach ($conditions as $condition) {
            if ($condition == null || $condition->query === '') {
                continue;
            }
            $queryParts[] = $condition->query;
            $binds = array_merge($binds, $condition->binds);
        }

        return [
            'query' => implode(" {$operator} ", $queryParts),
            'binds' => $binds,
        ];
    }
}
