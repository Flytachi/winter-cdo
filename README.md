# Winter CDO

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-cdo.svg)](https://packagist.org/packages/flytachi/winter-cdo)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-cdo.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-cdo)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

**CDO** (Connection Data Object) — an extended PDO wrapper for type-safe,
parameterised database operations with a composable query builder.

**Full documentation:** https://winterframe.net/packages/cdo

---

## Requirements

- PHP >= 8.3
- ext-pdo
- psr/log ^3.0

## Installation

```bash
composer require flytachi/winter-cdo
```

## Supported Databases

| Database | insert | insertBatch | upsert | upsertBatch | update | delete |
|----------|:------:|:-----------:|:------:|:-----------:|:------:|:------:|
| PostgreSQL | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| MySQL / MariaDB | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| SQLite | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Oracle | ⚠️ | ✅ | ❌ | ❌ | ✅ | ✅ |

> SQLite uses PostgreSQL-style `ON CONFLICT` upserts. `insert()`/`upsert()` return
> the last inserted row id via `lastInsertId()` (SQLite is not routed through
> `RETURNING`). SQLite has no session timezone, so timezone sync is a no-op.

---

## Quick Start

### 1. Define a configuration

Extend `MySqlDbConfig`, `PgDbConfig` or `SqliteDbConfig` and fill the connection
details in `setUp()`:

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
`MySqlDbCall` / `SqliteDbCall` / `DbCall` constructors — see
[Configuration docs](docs/01-configuration.md). SQLite needs no server or
credentials — `new SqliteDbCall(path: 'app.sqlite')`, or the default `:memory:`
for an ephemeral database (handy in tests):

```php
use Flytachi\Winter\Cdo\Config\Call\SqliteDbCall;

$cdo = (new SqliteDbCall())->connection();   // in-memory SQLite
```

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

// Batch insert — returns the number of inserted rows:
$inserted = $cdo->insertBatch('users', $usersArray, chunkSize: 500);

// …or stream them: a generator keeps peak memory at one batch, whatever the total.
$inserted = $cdo->insertBatch('users', (function () use ($csv) {
    while (($line = fgetcsv($csv)) !== false) {
        yield ['name' => $line[0], 'email' => $line[1]];
    }
})());

// Upsert (insert or update on conflict):
$cdo->upsert('products',
    ['sku' => 'ABC-001', 'price' => 9.99, 'stock' => 50],
    conflictColumns: ['sku'],
    updateColumns: ['price' => ':new', 'stock' => ':current + :new']
);
```

---

## Qb — Query Builder

`Qb` builds safe, parameterised SQL `WHERE` fragments.  Every **value** is bound
via a named placeholder — no string interpolation, no injection risk.

> **Column names, however, are injected verbatim** (they cannot be bound).
> Never pass user input as a column name: `Qb::eq('status', $userInput)` is safe,
> `Qb::eq($userInput, 'active')` is a SQL-injection vector.

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
| Raw | `raw` | verbatim SQL with optional binds |

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
$cdo->upsertBatch('inventory', $items,
    conflictColumns: ['warehouse_id', 'product_id'],
    updateColumns: [
        'cost'       => ':new',
        'quantity'   => ':current + :new',
        'updated_at' => 'NOW()',
    ]
);
```

`updateColumns` maps **column => expression**. A plain list — `['cost', 'quantity']`,
the shape Laravel's `upsert()` takes — is refused with a message showing the corrected
call; pass `[]` or `null` to ignore conflicts entirely (`DO NOTHING` / `INSERT IGNORE`).

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

Full reference documentation is at **https://winterframe.net/packages/cdo**

Local docs in [`docs/`](docs/):

| File | Topic |
|------|-------|
| [00-overview.md](docs/00-overview.md) | How the pieces fit together |
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
| [15-special.md](docs/15-special.md) | raw, empty |
| [16-advanced-examples.md](docs/16-advanced-examples.md) | Real-world combinations |

---

## Contributing & Security

- Changes and upgrade notes: [CHANGELOG.md](CHANGELOG.md)
- How to contribute (setup, tests, coding standard): [CONTRIBUTING.md](CONTRIBUTING.md)
- Reporting a vulnerability: [SECURITY.md](SECURITY.md)

---

## License

MIT License. See [LICENSE](LICENSE).
