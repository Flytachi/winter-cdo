# Winter CDO

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-cdo.svg)](https://packagist.org/packages/flytachi/winter-cdo)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-cdo.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-cdo)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

**CDO** (Connection Data Object) extends PDO with the write operations applications
actually perform — `insert`, `update`, `delete`, `upsert` and their streaming batch
variants — and with `Qb`, a composable builder for the `WHERE` clause.

Every value travels as a bound parameter, so SQL text and data never meet by string
concatenation. The driver-specific dialect is generated for you (PostgreSQL, MySQL /
MariaDB, SQLite, Oracle), so the same call works across all of them.

📖 **[Documentation](https://winterframe.net/packages/cdo)** · [Quick start](https://winterframe.net/packages/cdo/quickstart) · [CDO API](https://winterframe.net/packages/cdo/cdo-api) · [Qb operators](https://winterframe.net/packages/cdo/qb-operators)

---

## Installation

```bash
composer require flytachi/winter-cdo
```

Requires PHP **8.3+**, `ext-pdo` and `psr/log ^3.0`.

---

## Supported databases

| Database | insert | insertBatch | upsert | upsertBatch | update | delete |
|----------|:------:|:-----------:|:------:|:-----------:|:------:|:------:|
| PostgreSQL | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| MySQL / MariaDB | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| SQLite | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Oracle | ⚠️ | ✅ | ❌ | ❌ | ✅ | ✅ |

SQLite uses PostgreSQL-style `ON CONFLICT` upserts; `insert()` / `upsert()` return the
last inserted id via `lastInsertId()` rather than `RETURNING`, and timezone sync is a
no-op because SQLite has no session timezone.

---

## Quick start

Declare a database by filling in `setUp()`:

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

Then ask the pool for a connection and write:

```php
use Flytachi\Winter\Cdo\ConnectionPool;
use Flytachi\Winter\Cdo\Qb;

$cdo = ConnectionPool::db(AppDb::class);

$id = $cdo->insert('users', ['name' => 'Alice', 'email' => 'alice@example.com']);

$cdo->update('users', ['name' => 'Alice Smith'], Qb::eq('id', $id));
$cdo->delete('users', Qb::eq('id', $id));
```

A one-off connection needs no class — the inline `Call` variants take the credentials
directly, and SQLite needs none at all:

```php
use Flytachi\Winter\Cdo\Config\Call\SqliteDbCall;

$cdo = (new SqliteDbCall())->connection();   // in-memory, handy in tests
```

---

## What you get

- **Write operations, not a query language** — `insert`, `update`, `delete`, `upsert`
  take a table, an entity and a condition; the SQL is generated per driver.
- **Streaming batches** — `insertBatch` / `upsertBatch` accept a generator, so peak
  memory follows the chunk size rather than the size of the job.
- **A composable `WHERE`** — `Qb` fragments combine with `and` / `or` / `xor`, skip
  `null`, and parenthesise groups so an inner `OR` cannot break the surrounding `AND`.
- **Type-aware binding** — the `PDO::PARAM_*` type is derived from the PHP value;
  objects go through `DateTimeInterface` / `JsonSerializable` / `__toString()`.
- **Named binds** — one `CDOBind` reused across several conditions stays a single
  placeholder.
- **Lazy connections** — a config is instantiated once and cached; the socket opens on
  first use, with `ping()` / `reconnect()` for long-lived workers.
- **PSR-3 logging** — give it a logger and each statement, its bindings and its timing
  are recorded.

---

## A taste of Qb

```php
Qb::and(
    Qb::eq('status', 'active'),
    Qb::gte('age', 18),
    Qb::or(
        Qb::like('email', '%@example.com'),
        Qb::in('role', ['admin', 'editor']),
    ),
);
// (status = :iqb0 AND age >= :iqb1 AND (email LIKE :iqb2 OR role IN (:iqb3, :iqb4)))
```

Optional filters drop out by themselves, because logical operators skip `null`:

```php
Qb::and(
    Qb::eq('published', true),
    $categoryId ? Qb::eq('category_id', $categoryId) : null,
    $tagIds     ? Qb::in('tag_id', $tagIds)          : null,   // in() throws on []
);
```

> **Values are bound; column names are not.** A column name cannot be a placeholder, so
> it goes into the SQL verbatim. `Qb::eq('status', $userInput)` is safe;
> `Qb::eq($userInput, 'active')` is an injection vector — never let user input choose a
> column without a whitelist.

Every operator with the SQL it emits:
[Qb operators](https://winterframe.net/packages/cdo/qb-operators).

---

## Documentation

The user-facing documentation lives at **[winterframe.net/packages/cdo](https://winterframe.net/packages/cdo)**
(the link picks your language; RU and EN are both complete).

**Start here**

| Page | What it answers |
|------|-----------------|
| [Introduction](https://winterframe.net/packages/cdo/intro) | What CDO is, and where it sits next to plain PDO |
| [Installation](https://winterframe.net/packages/cdo/installation) | Requirements, install, driver extensions |
| [Quick start](https://winterframe.net/packages/cdo/quickstart) | Config, connection, first write |
| [Mental model](https://winterframe.net/packages/cdo/mental-model) | How config, pool, CDO and Qb relate |

**Guides**

| Page | What it answers |
|------|-----------------|
| [Inserting records](https://winterframe.net/packages/cdo/inserting-records) | Single rows, returned ids, batches |
| [Updating and deleting](https://winterframe.net/packages/cdo/updating-and-deleting) | Conditions, affected rows, staying safe |
| [Upserts](https://winterframe.net/packages/cdo/upserts) | Conflict columns and what gets updated |
| [Building conditions](https://winterframe.net/packages/cdo/building-conditions) | Composing `Qb`, optional filters, grouping |
| [Logging and diagnostics](https://winterframe.net/packages/cdo/logging-and-diagnostics) | Seeing the SQL, the bindings and the timing |

**Reference**

| Page | What it answers |
|------|-----------------|
| [CDO API](https://winterframe.net/packages/cdo/cdo-api) | Every method, its arguments and its return value |
| [Qb operators](https://winterframe.net/packages/cdo/qb-operators) | All operators with the SQL they emit |
| [Configuration](https://winterframe.net/packages/cdo/configuration) | Config classes, inline calls, driver options |
| [Upsert placeholders](https://winterframe.net/packages/cdo/upsert-placeholders) | `:new`, `:current`, and expressions between them |
| [Exceptions](https://winterframe.net/packages/cdo/exceptions) | What is thrown, and which SQLSTATE means what |

**Deep dive**

| Page | What it answers |
|------|-----------------|
| [Batches and chunking](https://winterframe.net/packages/cdo/batch-and-chunking) | Memory, partial failure, choosing a chunk size |
| [Parameter binding](https://winterframe.net/packages/cdo/parameter-binding) | How a PHP value becomes a bound parameter |
| [Driver detection](https://winterframe.net/packages/cdo/driver-detection) | What changes per driver, and how it is decided |

Classes in this package carry an `@link` to their page, so the same documentation is one
click away from your IDE.

---

## Contributing

Internal technical notes — exact contracts, the SQL each operator emits, and the
reasoning behind decisions that are not obvious from the code — live in
[`docs/`](docs/README.md). Read that before changing generated SQL.

```bash
composer test        # phpunit
composer test-detail # phpunit --testdox
composer cs-check    # phpcs
composer cs-fix      # phpcbf
```

- Changes and upgrade notes: [CHANGELOG.md](CHANGELOG.md)
- How to contribute (setup, tests, coding standard): [CONTRIBUTING.md](CONTRIBUTING.md)
- Reporting a vulnerability: [SECURITY.md](SECURITY.md)

---

## License

MIT License. See [LICENSE](LICENSE).
