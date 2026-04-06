# Pattern Matching — LIKE / NOT LIKE

## SQL Wildcard Syntax

Both `like` and `notLike` use standard SQL wildcard characters.
**You include wildcards in the value yourself** — the builder does not add them:

| Wildcard | Meaning | Example pattern | Matches |
|----------|---------|-----------------|---------|
| `%` | Zero or more of any character | `%php%` | `"php"`, `"advanced php"`, `"php7"` |
| `_` | Exactly one of any character | `_at` | `"cat"`, `"bat"`, `"rat"` |

```php
'%john%'     // contains "john" anywhere
'john%'      // starts with "john"
'%john'      // ends with "john"
'j_hn'       // "john", "j1hn", "jahn", etc.
```

---

## `like` — Pattern Matching

```
column LIKE :placeholder
column ILIKE :placeholder   (with insensitive: true, PostgreSQL only)
```

```php
Qb::like('username', '%admin%')
// SQL:  username LIKE :iqb0
// Bind: :iqb0 => '%admin%'

Qb::like('email', '%@gmail.com')
// SQL:  email LIKE :iqb0
// Bind: :iqb0 => '%@gmail.com'

Qb::like('code', 'PRD-___-2024')
// SQL:  code LIKE :iqb0
// Bind: :iqb0 => 'PRD-___-2024'
// Matches: PRD-ABC-2024, PRD-X99-2024, …

// Case-insensitive (PostgreSQL only):
Qb::like('title', '%php%', insensitive: true)
// SQL:  title ILIKE :iqb0
// Bind: :iqb0 => '%php%'
```

---

## `notLike` — Negated Pattern Matching

```
column NOT LIKE :placeholder
column NOT ILIKE :placeholder   (with insensitive: true, PostgreSQL only)
```

```php
Qb::notLike('email', '%@spam.com')
// SQL:  email NOT LIKE :iqb0
// Bind: :iqb0 => '%@spam.com'

Qb::notLike('username', 'bot_%')
// SQL:  username NOT LIKE :iqb0
// Bind: :iqb0 => 'bot_%'
// Excludes: bot_alpha, bot_1, …

// Case-insensitive exclusion (PostgreSQL only):
Qb::notLike('name', '%test%', insensitive: true)
// SQL:  name NOT ILIKE :iqb0
```

---

## Case Sensitivity — Database Compatibility

The `$insensitive` parameter maps to `ILIKE` / `NOT ILIKE`, which is a
**PostgreSQL extension**.

| Database | Default LIKE | `insensitive: true` |
|----------|-------------|---------------------|
| **PostgreSQL** | Case-sensitive | Uses `ILIKE` — works correctly |
| **MySQL / MariaDB** | Case-insensitive for non-binary columns | **Do not use** — `ILIKE` is a syntax error on MySQL |
| **Oracle** | Case-sensitive | **Not supported** — use `REGEXP_LIKE(col, val, 'i')` manually via `Qb::custom()` |
| **SQLite** | Case-insensitive for ASCII only | Not applicable |

### MySQL — explicit case-sensitive LIKE

On MySQL, `LIKE` is already case-insensitive for `VARCHAR`/`TEXT` columns with
a case-insensitive collation (which is the default).  If you need a
**case-sensitive** match on MySQL, cast to a binary collation at the SQL level:

```php
// Case-sensitive LIKE on MySQL via custom():
Qb::custom("BINARY username LIKE '%Admin%'")
```

---

## Passing a CDOBind

```php
$pattern = new CDOBind('search_term', '%climate%');

Qb::or(
    Qb::like('title',   $pattern),
    Qb::like('summary', $pattern),
)
// title LIKE :search_term OR summary LIKE :search_term
// Bind: :search_term => '%climate%'
```

This is the cleanest way to search the same pattern across multiple columns
without duplicating bind parameters.

---

## Practical patterns

### Full-text style substring search

```php
function searchByName(string $term): Qb
{
    $pattern = '%' . $term . '%';   // add wildcards before binding

    return Qb::or(
        Qb::like('first_name', $pattern),
        Qb::like('last_name',  $pattern),
        Qb::like('email',      $pattern),
    );
}

searchByName('john')
// first_name LIKE :iqb0 OR last_name LIKE :iqb1 OR email LIKE :iqb2
// (or use a shared CDOBind for a single :search placeholder)
```

### Filter out test / bot accounts

```php
Qb::and(
    Qb::notLike('email',    '%+test%'),
    Qb::notLike('username', 'bot_%'),
    Qb::notLike('username', 'test_%'),
)
// email NOT LIKE :iqb0
//   AND username NOT LIKE :iqb1
//   AND username NOT LIKE :iqb2
```

### Starts-with / ends-with helpers

```php
// Starts with:
Qb::like('sku', 'PRD-%')
// sku LIKE :iqb0  (:iqb0 => 'PRD-%')

// Ends with:
Qb::like('filename', '%.pdf')
// filename LIKE :iqb0  (:iqb0 => '%.pdf')
```
