# CASE Expression

## Overview

`Qb::case()` generates a searched `CASE` expression:

```sql
CASE
  WHEN <condition1> THEN <value1>
  WHEN <condition2> THEN <value2>
  ...
  [ELSE <default_value>]
END
```

This is a **SQL expression**, not a condition — the resulting `Qb` instance
represents a value expression that can be used in a `SELECT` column list,
inside another condition, or wherever a scalar expression is valid.

---

## Signature

```php
public static function case(
    array<string, string> $whenThenPairs,
    ?string $else = null
): Qb
```

| Argument | Type | Description |
|----------|------|-------------|
| `$whenThenPairs` | `array<string, string>` | Keys = raw SQL conditions (not parameterised), values = result literals (parameterised) |
| `$else` | `string\|null` | Default value when no `WHEN` branch matches (parameterised) |

### Important: keys vs. values

- **Keys** (the `WHEN` conditions) are injected **verbatim** into the SQL.
  Use column names, comparisons, and SQL expressions — but **do not put user
  input here** (SQL injection risk).
- **Values** (the `THEN` results) are **always parameterised** via `CDOBind`.
  They are safe to receive from external sources.

---

## Examples

### Simple score-to-grade mapping

```php
Qb::case([
    'score >= 90' => 'A',
    'score >= 75' => 'B',
    'score >= 60' => 'C',
], else: 'F')
```

Generated SQL:
```sql
CASE
  WHEN score >= 90 THEN :iqb0
  WHEN score >= 75 THEN :iqb1
  WHEN score >= 60 THEN :iqb2
  ELSE :iqb3
END
```

Binds: `:iqb0 => 'A'`, `:iqb1 => 'B'`, `:iqb2 => 'C'`, `:iqb3 => 'F'`

---

### Status label mapping

```php
Qb::case([
    "status = 'active'"    => 'Active',
    "status = 'pending'"   => 'Pending review',
    "status = 'suspended'" => 'Suspended',
], else: 'Unknown')
```

SQL:
```sql
CASE
  WHEN status = 'active'    THEN :iqb0
  WHEN status = 'pending'   THEN :iqb1
  WHEN status = 'suspended' THEN :iqb2
  ELSE :iqb3
END
```

> Note: the string values inside the WHEN keys (`'active'`, `'pending'`) are
> part of a raw SQL string — they are **not** bound parameters.  If the status
> values are known constants (not user input), this is fine.

---

### Without an ELSE clause

When `$else` is omitted, the expression returns `NULL` for non-matching rows:

```php
Qb::case([
    'priority = 1' => 'Critical',
    'priority = 2' => 'High',
    'priority = 3' => 'Normal',
])
```

SQL:
```sql
CASE
  WHEN priority = 1 THEN :iqb0
  WHEN priority = 2 THEN :iqb1
  WHEN priority = 3 THEN :iqb2
END
```

For `priority = 4` the expression evaluates to `NULL`.

---

### Combining with other conditions

A `Qb::case()` result is itself a `Qb` — you can use it wherever a SQL
expression fits.  For example, as the left side of a comparison:

```php
// All users whose tier label is 'Gold' or 'Platinum':
Qb::and(
    Qb::eq('is_active', true),
    Qb::in(
        Qb::case([
            'points >= 10000' => 'Platinum',
            'points >= 5000'  => 'Gold',
            'points >= 1000'  => 'Silver',
        ], else: 'Bronze')->getQuery(),     // embed the CASE as a column expression
        ['Gold', 'Platinum']
    ),
)
```

> Because `Qb::in` takes a column name string (not a `Qb`), you need to call
> `getQuery()` to extract the SQL fragment and embed it.  This is an advanced
> use case — in most scenarios you would select the `CASE` expression and
> filter in application code, or use `Qb::custom()` for raw expressions.

---

## Security reminder

The **keys** of `$whenThenPairs` are raw SQL.  Never interpolate user-supplied
strings into them:

```php
// ✅ Safe — keys are hardcoded constants:
Qb::case([
    'role = 1' => 'Admin',
    'role = 2' => 'Editor',
])

// ❌ UNSAFE — user input in a key:
$userInput = $_GET['condition'];
Qb::case([
    $userInput => 'matched',   // SQL injection!
])
```

If the condition itself depends on user input, use a parameterised `Qb`
condition (e.g. `Qb::eq`) and convert its `getQuery()` to the key — but only
after verifying that the column name is a known, safe identifier.
