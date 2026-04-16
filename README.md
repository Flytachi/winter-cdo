# Winter CDO

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-cdo.svg)](https://packagist.org/packages/flytachi/winter-cdo)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

**CDO** (Connection Data Object) — an extended PDO wrapper for type-safe,
parameterised database operations with a composable query builder.

**Full documentation:** https://winterframe.net/docs/cdo

---

## Requirements

- PHP >= 8.3
- ext-pdo
- flytachi/winter-base ^1.0

## Installation

```bash
composer require flytachi/winter-cdo
```

## Supported Databases

| Database | insert | insertGroup | upsert | upsertGroup | update | delete |
|----------|:------:|:-----------:|:------:|:-----------:|:------:|:------:|
| PostgreSQL | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| MySQL / MariaDB | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Oracle | ⚠️ | ✅ | ❌ | ❌ | ✅ | ✅ |

---

## Quick Start

### 1. Define a configuration

Extend `MySqlDbConfig` or `PgDbConfig` and fill credentials in `setUp()`:

```php
use Flytachi\Winter\Cdo\Config\PgDbConfig;

class AppDb extends PgDbConfig
{
    public function setUp(): void
    {
        $this->host     = env('DB_HOST', 'localhost');
        $this->port     = (int) env('DB_PORT', 5432);
        $this->database = env('DB_NAME', 'myapp');
        $this->username = env('DB_USER', 'postgres');
        $this->password = env('DB_PASS', '');
    }
}
```

For a one-off connection without a dedicated class, use the inline `PgDbCall` /
`MySqlDbCall` / `DbCall` constructors — see [Configuration docs](docs/01-configuration.md).

### 2. Get a connection

```php
$cdo = ConnectionPool::db(AppDb::class);
```

### 3. Run operations

```php
use Flytachi\Winter\Cdo\Qb;

// Insert — returns the generated primary key:
$id = $cdo->insert('users', [
    'name'  => 'Alice',
    'email' => 'alice@example.com',
]);

// Update — returns affected row count:
$cdo->update('users',
    ['name' => 'Alice Smith'],
    Qb::eq('id', $id)
);

// Delete — returns deleted row count:
$cdo->delete('users', Qb::eq('id', $id));

// Batch insert:
$cdo->insertGroup('users', $usersArray, chunkSize: 500);

// Upsert (insert or update on conflict):
$cdo->upsert('products',
    ['sku' => 'ABC-001', 'price' => 9.99, 'stock' => 50],
    conflictColumns: ['sku'],
    updateColumns: ['price' => ':new', 'stock' => ':current + :new']
);
```

---

## Qb — Query Builder

`Qb` builds safe, parameterised SQL `WHERE` fragments.  Every value is bound
via a named placeholder — no string interpolation, no injection risk.

```php
// Simple condition:
Qb::eq('status', 'active')
// → status = :iqb0

// Compound condition:
$where = Qb::and(
    Qb::eq('status', 'active'),
    Qb::gte('age', 18),
    Qb::isNull('banned_at'),
);
// → status = :iqb0 AND age >= :iqb1 AND banned_at IS NULL
```

### Operator reference

| Category | Methods | SQL result |
|----------|---------|-----------|
| Comparison | `eq`, `neq`, `gt`, `gte`, `lt`, `lte` | `col = :x`, `col != :x`, … |
| NULL | `isNull`, `isNotNull` | `col IS NULL`, `col IS NOT NULL` |
| NULL-safe | `nsEq` | `col <=> :x` (MySQL/MariaDB) |
| Set | `in`, `notIn` | `col IN (:a, :b)`, `col NOT IN (…)` |
| Pattern | `like`, `notLike` | `col LIKE :x`, `col NOT LIKE :x` |
| Range | `between`, `notBetween` | `col BETWEEN :a AND :b` |
| Range (inverted) | `betweenBy`, `notBetweenBy` | `:x BETWEEN col1 AND col2` |
| Logical | `and`, `or`, `xor` | `a AND b`, `a OR b`, `a XOR b` |
| Grouping | `clip` | `(condition)` |
| CASE | `case` | `CASE WHEN … THEN … END` |
| Raw | `custom` | verbatim SQL (no binding) |

### Operator precedence — always use `clip` with mixed AND/OR

```php
// ❌ Wrong — SQL reads as (published AND role='editor') OR role='admin':
Qb::and(
    Qb::eq('published', true),
    Qb::or(Qb::eq('role', 'editor'), Qb::eq('role', 'admin')),
)

// ✅ Correct — clip enforces the right grouping:
Qb::and(
    Qb::eq('published', true),
    Qb::clip(
        Qb::or(Qb::eq('role', 'editor'), Qb::eq('role', 'admin'))
    ),
)
// → published IS TRUE AND (role = :iqb0 OR role = :iqb1)
```

### Dynamic filters

```php
// null conditions are silently skipped:
$where = Qb::and(
    Qb::eq('status', 'active'),
    $minAge  !== null ? Qb::gte('age', $minAge)   : null,
    $country !== null ? Qb::eq('country', $country) : null,
    Qb::in('tag_id', $tagIds),   // skipped when $tagIds is []
);
```

### Named binds — share one placeholder across conditions

```php
$uid = new CDOBind('uid', $currentUserId);

$where = Qb::or(
    Qb::eq('author_id',   $uid),
    Qb::eq('reviewer_id', $uid),
    Qb::eq('assignee_id', $uid),
);
// → author_id = :uid OR reviewer_id = :uid OR assignee_id = :uid
```

---

## Upsert Placeholders

| Token | PostgreSQL | MySQL / MariaDB |
|-------|-----------|----------------|
| `:new` | `EXCLUDED.column` | `VALUES(column)` |
| `:current` | `table.column` | `column` |

```php
$cdo->upsertGroup('inventory', $items,
    conflictColumns: ['warehouse_id', 'product_id'],
    updateColumns: [
        'cost'       => ':new',
        'quantity'   => ':current + :new',
        'updated_at' => 'NOW()',
    ]
);
```

---

## Error Handling

All failures throw `CDOException`, which wraps the original `PDOException` as
its `$previous` cause (preserving SQLSTATE code and driver message):

```php
use Flytachi\Winter\Cdo\Connection\CDOException;

try {
    $cdo->insert('users', $data);
} catch (CDOException $e) {
    $sqlstate = $e->getPrevious()?->getCode();  // e.g. "23505" (PG unique violation)
    // handle or re-throw
}
```

---

## Documentation

Full reference documentation is at **https://winterframe.net/docs/cdo**

Local docs in [`docs/`](docs/):

| File | Topic |
|------|-------|
| [01-configuration.md](docs/01-configuration.md) | Config classes, inline Call classes |
| [02-connection-pool.md](docs/02-connection-pool.md) | ConnectionPool, health checks |
| [03-cdo.md](docs/03-cdo.md) | All CDO DML methods |
| [04-cdo-statement.md](docs/04-cdo-statement.md) | Type binding, object serialisation |
| [05-exceptions.md](docs/05-exceptions.md) | CDOException, SQLSTATE reference |
| [06-cdobind.md](docs/06-cdobind.md) | CDOBind — named parameters |
| [07-comparison-operators.md](docs/07-comparison-operators.md) | eq, neq, gt, gte, lt, lte, nsEq |
| [08-null-checks.md](docs/08-null-checks.md) | isNull, isNotNull |
| [09-set-operators.md](docs/09-set-operators.md) | in, notIn |
| [10-pattern-matching.md](docs/10-pattern-matching.md) | like, notLike |
| [11-range-operators.md](docs/11-range-operators.md) | between, betweenBy, notBetween, notBetweenBy |
| [12-logical-operators.md](docs/12-logical-operators.md) | and, or, xor, clip |
| [13-mutable-methods.md](docs/13-mutable-methods.md) | addAnd, addOr, addXor |
| [14-case-expression.md](docs/14-case-expression.md) | CASE WHEN … END |
| [15-special.md](docs/15-special.md) | custom, empty |
| [16-advanced-examples.md](docs/16-advanced-examples.md) | Real-world combinations |

---

## License

MIT License. See [LICENSE](LICENSE).
