# Logical Operators — AND / OR / XOR / Clip

Logical operators **combine** `Qb` conditions.  They are the glue that turns
individual filters into complex `WHERE` clauses.

All four operators described here are **immutable** — they return a new `Qb`
instance without modifying their inputs.  For in-place mutation, see
[08-mutable-methods.md](08-mutable-methods.md).

---

## `and` — Logical AND

```
cond1 AND cond2 AND cond3 ...
```

Returns `TRUE` only when **all** conditions are true.  
Accepts any number of `Qb|null` arguments.  `null` and **empty** conditions
are silently skipped.

```php
Qb::and(
    Qb::eq('status', 'active'),
    Qb::gte('age', 18),
    Qb::isNotNull('email'),
)
// SQL:  status = :iqb0 AND age >= :iqb1 AND email IS NOT NULL
```

### Skipping null / empty conditions

```php
$minAge  = null;   // not filtered
$country = 'RU';

Qb::and(
    Qb::eq('is_active', true),
    $minAge !== null ? Qb::gte('age', $minAge) : null,   // null → skipped
    Qb::eq('country', $country),
)
// SQL:  is_active IS TRUE AND country = :iqb0
```

---

## `or` — Logical OR

```
cond1 OR cond2 OR cond3 ...
```

Returns `TRUE` when **at least one** condition is true.

```php
Qb::or(
    Qb::eq('role', 'admin'),
    Qb::eq('role', 'moderator'),
    Qb::eq('role', 'superuser'),
)
// SQL:  role = :iqb0 OR role = :iqb1 OR role = :iqb2
```

---

## `xor` — Logical XOR

```
cond1 XOR cond2 XOR cond3 ...
```

Returns `TRUE` when an **odd number** of conditions are true.  
For two conditions this is equivalent to "exactly one is true".

```php
Qb::xor(
    Qb::eq('flag_a', 1),
    Qb::eq('flag_b', 1),
)
// SQL:  flag_a = :iqb0 XOR flag_b = :iqb1
```

> **Database support:**  
> `XOR` is a native operator in MySQL and MariaDB.  
> PostgreSQL and SQLite do not have a `XOR` keyword — emulate it with:  
> `(A OR B) AND NOT (A AND B)` via `Qb::and` / `Qb::or` / `Qb::clip`.

---

## `clip` — Grouping with Parentheses

```
(condition)
```

Wraps any condition in parentheses to enforce operator precedence.

### Why parentheses matter

SQL evaluates `AND` **before** `OR` (AND has higher precedence).  Without
explicit grouping, mixing the two operators produces unexpected results:

```php
// ⚠ WITHOUT clip — WRONG precedence:
Qb::and(
    Qb::eq('published', true),
    Qb::or(
        Qb::eq('role', 'editor'),
        Qb::eq('role', 'admin'),
    ),
)
// Generates:  published IS TRUE AND role = :iqb0 OR role = :iqb1
//
// SQL reads it as:
//   (published IS TRUE AND role = :iqb0) OR (role = :iqb1)
//
// This is WRONG — any admin passes regardless of `published`!
```

```php
// ✅ WITH clip — correct precedence:
Qb::and(
    Qb::eq('published', true),
    Qb::clip(
        Qb::or(
            Qb::eq('role', 'editor'),
            Qb::eq('role', 'admin'),
        )
    ),
)
// SQL:  published IS TRUE AND (role = :iqb0 OR role = :iqb1)
//
// Only rows where published is TRUE *and* role is editor or admin.
```

### clip on empty condition

If the condition passed to `clip` is empty, it is returned unchanged — no
parentheses are added and no empty `()` appears in the query.

```php
Qb::clip(Qb::empty())
// Returns Qb::empty()  — getQuery() === ''
```

---

## Nesting logical operators

Logical operators can be nested to any depth.  Use `clip` at each level where
precedence needs to be explicit.

```php
// Query:
// Find products that are:
//   published AND (in categories 1 or 2) AND (price < 50 OR has_discount IS TRUE)

Qb::and(
    Qb::eq('published', true),
    Qb::clip(
        Qb::in('category_id', [1, 2])
    ),
    Qb::clip(
        Qb::or(
            Qb::lt('price', 50),
            Qb::eq('has_discount', true),
        )
    ),
)

// SQL:
// published IS TRUE
//   AND (category_id IN (:iqb0, :iqb1))
//   AND (price < :iqb2 OR has_discount IS TRUE)
```

---

## Passing conditions from variables

Since all operators accept `?Qb ...$conditions`, you can collect conditions
into an array and spread them:

```php
$conditions = [
    Qb::eq('active', true),
];

if ($request->has('category')) {
    $conditions[] = Qb::eq('category_id', $request->get('category'));
}

if ($request->has('price_max')) {
    $conditions[] = Qb::lte('price', $request->get('price_max'));
}

$where = Qb::and(...$conditions);
// Produces only the conditions that were added — no nulls, no empties
```

---

## Summary table

| Operator | Returns TRUE when | Null / empty args |
|----------|------------------|-------------------|
| `and(...)` | All conditions are true | Skipped |
| `or(...)` | At least one condition is true | Skipped |
| `xor(...)` | Odd number of conditions are true | Skipped |
| `clip($c)` | Wraps condition in `(...)` — no logical change | Returns condition unchanged |
