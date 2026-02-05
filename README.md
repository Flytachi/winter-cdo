# Winter CDO Component

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-cdo.svg)](https://packagist.org/packages/flytachi/winter-cdo)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

CDO (Connection Data Object) — an extended PDO wrapper for convenient database operations.

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
|----------|--------|-------------|--------|-------------|--------|--------|
| PostgreSQL | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| MySQL/MariaDB | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Oracle | ⚠️ | ✅ | ❌ | ❌ | ✅ | ✅ |

## Quick Start

### 1. Create a Configuration

```php
use Flytachi\Winter\Cdo\Config\PgDbConfig;

class MyDbConfig extends PgDbConfig
{
    public function setUp(): void
    {
        $this->host = env('DB_HOST', 'localhost');
        $this->port = (int) env('DB_PORT', 5432);
        $this->database = env('DB_NAME', 'mydb');
        $this->username = env('DB_USER', 'postgres');
        $this->password = env('DB_PASS', '');
        $this->schema = env('DB_SCHEMA', 'public');
    }
}
```

### 2. Get a Connection

```php
use Flytachi\Winter\Cdo\ConnectionPool;

$cdo = ConnectionPool::db(MyDbConfig::class);
```

### 3. Perform Operations

```php
use Flytachi\Winter\Cdo\Qb;

// Insert
$id = $cdo->insert('users', [
    'name' => 'John',
    'email' => 'john@example.com'
]);

// Update
$affected = $cdo->update('users', ['name' => 'Jane'], Qb::eq('id', 1));

// Delete
$deleted = $cdo->delete('users', Qb::eq('id', 1));
```

## API Reference

### CDO Methods

#### `insert(string $table, object|array $entity): mixed`

Insert a single record. Returns the inserted record ID.

```php
$userId = $cdo->insert('users', [
    'name' => 'John',
    'email' => 'john@example.com'
]);
```

#### `insertGroup(string $table, array $entities, int $chunkSize = 1000): void`

Batch insert with automatic chunking.

```php
$users = [
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com'],
    // ... thousands of records
];

$cdo->insertGroup('users', $users);
$cdo->insertGroup('users', $users, chunkSize: 500); // custom chunk size
```

#### `upsert(string $table, object|array $entity, array $conflictColumns, ?array $updateColumns = null): mixed`

Insert or update a single record.

```php
// Insert or update
$cdo->upsert(
    'products',
    ['sku' => 'ABC', 'name' => 'Product', 'price' => 100],
    ['sku'],
    ['name' => ':new', 'price' => ':new']
);

// Insert only (ignore duplicates)
$cdo->upsert('users', $user, ['email']);
```

#### `upsertGroup(string $table, array $entities, array $conflictColumns, ?array $updateColumns = null, int $chunkSize = 500): void`

Batch upsert with automatic chunking.

```php
$cdo->upsertGroup(
    'inventory',
    $items,
    ['warehouse_id', 'product_id'],
    [
        'cost' => ':new',
        'quantity' => ':current + :new',
        'updated_at' => 'NOW()'
    ]
);
```

**Placeholders**

| Placeholder | PostgreSQL | MySQL |
|-------------|------------|-------|
| `:new` | `EXCLUDED.column` | `VALUES(column)` |
| `:current` | `table.column` | `column` |

**Expression Examples**

| Expression | Description |
|------------|-------------|
| `:new` | Replace with new value |
| `:current + :new` | Add to current value |
| `GREATEST(:current, :new)` | Take maximum |
| `COALESCE(:new, :current)` | New value or keep current |
| `NOW()` | SQL function (no placeholder) |

#### `update(string $table, object|array $entity, Qb $qb): int`

Update records by condition. Returns the number of affected rows.

```php
$affected = $cdo->update(
    'users',
    ['status' => 'inactive'],
    Qb::and(
        Qb::lt('last_login', '2024-01-01'),
        Qb::eq('status', 'active')
    )
);
```

#### `delete(string $table, Qb $qb): int`

Delete records by condition. Returns the number of deleted rows.

```php
$deleted = $cdo->delete('sessions', Qb::lt('expires_at', date('Y-m-d H:i:s')));
```

### Qb (Query Builder)

WHERE condition generator with automatic SQL injection protection.

#### Comparison Operators

```php
Qb::eq('status', 'active')     // status = ?
Qb::neq('status', 'deleted')   // status != ?
Qb::gt('age', 18)              // age > ?
Qb::geq('age', 18)             // age >= ?
Qb::lt('age', 65)              // age < ?
Qb::leq('age', 65)             // age <= ?
Qb::isNull('deleted_at')       // deleted_at IS NULL
Qb::isNotNull('email')         // email IS NOT NULL
```

#### Set Operators

```php
Qb::in('status', ['active', 'pending'])      // status IN (?, ?)
Qb::inNot('role', ['banned', 'suspended'])   // role NOT IN (?, ?)
Qb::between('age', 18, 65)                   // age BETWEEN ? AND ?
Qb::betweenNot('price', 100, 200)            // price NOT BETWEEN ? AND ?
```

#### Pattern Operators

```php
Qb::like('name', '%john%')      // name LIKE ?
Qb::likeNot('email', '%spam%')  // email NOT LIKE ?
```

#### Logical Operators

```php
Qb::and($condition1, $condition2, ...)  // cond1 AND cond2 AND ...
Qb::or($condition1, $condition2, ...)   // cond1 OR cond2 OR ...
Qb::xor($condition1, $condition2, ...)  // cond1 XOR cond2 XOR ...
Qb::clip($condition)                    // (condition)
```

#### Complex Example

```php
$qb = Qb::and(
    Qb::eq('status', 'active'),
    Qb::or(
        Qb::clip(Qb::and(
            Qb::eq('role', 'admin'),
            Qb::geq('level', 5)
        )),
        Qb::clip(Qb::and(
            Qb::eq('role', 'moderator'),
            Qb::in('department', ['sales', 'support'])
        ))
    ),
    Qb::isNotNull('email_verified_at')
);

// Result: status = ? AND ((role = ? AND level >= ?) OR (role = ? AND department IN (?, ?))) AND email_verified_at IS NOT NULL
```

### ConnectionPool

Connection manager with caching.

```php
// Get connection (created once, then reused)
$cdo = ConnectionPool::db(MyDbConfig::class);

// Get config
$config = ConnectionPool::getConfigDb(MyDbConfig::class);

// Check connection
$config->ping();        // bool
$config->pingDetail();  // ['status' => bool, 'latency' => float, 'error' => ?string]

// Reconnect
$config->reconnect();
```

### Configurations

#### PostgreSQL

```php
use Flytachi\Winter\Cdo\Config\PgDbConfig;

class MyPgConfig extends PgDbConfig
{
    public function setUp(): void
    {
        $this->host = 'localhost';
        $this->port = 5432;
        $this->database = 'mydb';
        $this->username = 'postgres';
        $this->password = 'secret';
        $this->schema = 'public';
        $this->charset = 'UTF8';        // optional
        $this->isPersistent = false;    // optional
    }
}
```

#### MySQL / MariaDB

```php
use Flytachi\Winter\Cdo\Config\MySqlDbConfig;

class MyMySqlConfig extends MySqlDbConfig
{
    public function setUp(): void
    {
        $this->host = 'localhost';
        $this->port = 3306;
        $this->database = 'mydb';
        $this->username = 'root';
        $this->password = 'secret';
        $this->charset = 'utf8mb4';     // optional
        $this->isPersistent = false;    // optional
    }
}
```

## License

MIT License. See [LICENSE](LICENSE).
