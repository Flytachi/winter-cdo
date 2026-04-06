# Comparison Operators

All comparison operators share the same contract:

- Accept a **column name** (raw string, injected as-is into SQL)
- Accept a **value** — either a scalar or a [`CDOBind`](01-cdobind.md)
- Return a new `Qb` instance with the condition and its bind(s)

---

## `eq` — Equal to

```
column = :placeholder
```

### Special value handling

`eq` performs smart dispatch based on the value type:

| Value | Generated SQL |
|-------|--------------|
| `null` | `column IS NULL` |
| `true` | `column IS TRUE` |
| `false` | `column IS FALSE` |
| any scalar / CDOBind | `column = :placeholder` |

### Examples

```php
Qb::eq('status', 'active')
// SQL:  status = :iqb0
// Bind: :iqb0 => 'active'

Qb::eq('price', 99.99)
// SQL:  price = :iqb0
// Bind: :iqb0 => 99.99

Qb::eq('deleted_at', null)
// SQL:  deleted_at IS NULL
// Bind: (none)

Qb::eq('is_verified', true)
// SQL:  is_verified IS TRUE
// Bind: (none)

Qb::eq('is_bot', false)
// SQL:  is_bot IS FALSE
// Bind: (none)
```

### Using a named CDOBind

```php
$bind = new CDOBind('uid', 7);
Qb::eq('user_id', $bind)
// SQL:  user_id = :uid
// Bind: :uid => 7
```

---

## `neq` — Not Equal to

```
column != :placeholder
```

### Special value handling

Same dispatch rules as `eq`, but negated:

| Value | Generated SQL |
|-------|--------------|
| `null` | `column IS NOT NULL` |
| `true` | `column IS NOT TRUE` |
| `false` | `column IS NOT FALSE` |
| any scalar / CDOBind | `column != :placeholder` |

### Examples

```php
Qb::neq('role', 'banned')
// SQL:  role != :iqb0
// Bind: :iqb0 => 'banned'

Qb::neq('deleted_at', null)
// SQL:  deleted_at IS NOT NULL
// Bind: (none)

Qb::neq('is_trial', true)
// SQL:  is_trial IS NOT TRUE
// Bind: (none)
```

---

## `gt` — Greater Than

```
column > :placeholder
```

Accepts `int`, `float`, `string`, or `CDOBind`.

```php
Qb::gt('price', 100)
// SQL:  price > :iqb0
// Bind: :iqb0 => 100

Qb::gt('score', 9.5)
// SQL:  score > :iqb0
// Bind: :iqb0 => 9.5

Qb::gt('created_at', '2024-01-01')
// SQL:  created_at > :iqb0
// Bind: :iqb0 => '2024-01-01'
```

---

## `gte` — Greater Than or Equal To

```
column >= :placeholder
```

```php
Qb::gte('age', 18)
// SQL:  age >= :iqb0
// Bind: :iqb0 => 18

Qb::gte('score', 60.0)
// SQL:  score >= :iqb0
// Bind: :iqb0 => 60.0
```

---

## `lt` — Less Than

```
column < :placeholder
```

```php
Qb::lt('stock', 10)
// SQL:  stock < :iqb0
// Bind: :iqb0 => 10

Qb::lt('temperature', -5.5)
// SQL:  temperature < :iqb0
// Bind: :iqb0 => -5.5
```

---

## `lte` — Less Than or Equal To

```
column <= :placeholder
```

```php
Qb::lte('age', 65)
// SQL:  age <= :iqb0
// Bind: :iqb0 => 65

Qb::lte('priority', 3)
// SQL:  priority <= :iqb0
// Bind: :iqb0 => 3
```

---

## `nsEq` — NULL-safe Equal To (MySQL / MariaDB)

```
column <=> :placeholder
```

The `<=>` operator is MySQL/MariaDB-specific.  It behaves like `=` but returns
a boolean instead of NULL when either side is NULL:

| Expression | Regular `=` result | `<=>` result |
|------------|-------------------|-------------|
| `1 = 1` | `TRUE` | `TRUE` |
| `1 = NULL` | `NULL` | `FALSE` |
| `NULL = NULL` | `NULL` | `TRUE` |

This makes it safe to compare nullable columns without an extra `IS NULL` check.

**PostgreSQL equivalent:** `IS NOT DISTINCT FROM`

### Examples

```php
Qb::nsEq('score', 0)
// SQL:  score <=> :iqb0
// Bind: :iqb0 => 0

// Comparing with NULL via CDOBind (nsEq type signature excludes null directly):
$nullBind = new CDOBind('nothing', null);
Qb::nsEq('deleted_at', $nullBind)
// SQL:  deleted_at <=> :nothing
// Bind: :nothing => null
```

---

## Combining comparisons

Comparison operators produce single-condition `Qb` instances.  Combine them
with [logical operators](07-logical-operators.md):

```php
Qb::and(
    Qb::gte('age',   18),
    Qb::lte('age',   65),
    Qb::neq('status', 'banned'),
)
// age >= :iqb0 AND age <= :iqb1 AND status != :iqb2
```

> **Tip — range shorthand:** the above age range can also be written as
> `Qb::between('age', 18, 65)` — see [06-range-operators.md](06-range-operators.md).
