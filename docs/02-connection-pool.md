# ConnectionPool — Config Registry and CDO Factory

`ConnectionPool` is a **process-level singleton registry** that caches
initialised `DbConfigInterface` instances and provides CDO connections on demand.

It is the single entry point through which all config classes are initialised
and all connections are obtained.

---

## How It Works

```
ConnectionPool::db(AppDb::class)
        │
        ▼
getConfigDb(AppDb::class)
        │
        ├─ first call ─→ new AppDb() → setUp() → stored in $dbConfig
        │
        └─ subsequent ─→ return cached instance
                │
                ▼
        config->connection()    ← lazy CDO open
                │
                ▼
             CDO
```

Key points:
- Each config class is instantiated **at most once** per PHP process.
- The key in the cache is `base64_encode($className)` — this allows any FQCN
  including namespaced class names with `\` characters.
- `setUp()` is called **once** immediately after instantiation — not on every
  `db()` call.
- The CDO itself is opened lazily inside `connection()` — the socket is only
  established when first needed.

---

## `db` — Get a CDO Connection

```php
public static function db(string $className): CDO
```

Returns an active CDO connection for the given config class.

```php
use Flytachi\Winter\Cdo\ConnectionPool;

$cdo = ConnectionPool::db(AppDb::class);
$cdo->insert('users', $data);
```

Under the hood:
1. Resolves the config via `getConfigDb()`.
2. Calls `$config->connection()`, which opens the PDO socket lazily.

---

## `getConfigDb` — Get the Config Instance

```php
public static function getConfigDb(string $className): DbConfigInterface
```

Returns the initialised config object.  Useful when you need access to config
metadata (schema name, ping, etc.) rather than the connection itself.

```php
$config = ConnectionPool::getConfigDb(AppDb::class);

// Health check:
$config->ping();
$config->pingDetail();

// Get schema (PostgreSQL):
$schema = $config->getSchema();   // 'public' or custom

// Force reconnect:
$config->reconnect();
```

---

## `showDbConfigs` — Inspect All Registered Configs

```php
public static function showDbConfigs(): array  // returns DbConfigInterface[]
```

Returns all currently registered config instances.  Useful for diagnostics,
health endpoints, or iterating all known connections:

```php
foreach (ConnectionPool::showDbConfigs() as $config) {
    $result = $config->pingDetail();
    echo $config::class . ': ' . ($result['status'] ? 'ok' : 'FAIL') . "\n";
}
```

---

## `EntityCallDbTrait` Shortcut

Config classes that use `EntityCallDbTrait` (all built-in base classes do)
gain a `::instance()` static method that calls `ConnectionPool::db()` for you:

```php
// These two lines are equivalent:
$cdo = ConnectionPool::db(AppDb::class);
$cdo = AppDb::instance();
```

`AppDb::instance()` is the idiomatic way to get a connection in most
application code — it reads cleanly and avoids repeating the class name as a string.

---

## Multiple Databases

Register as many config classes as needed.  Each is cached independently:

```php
class MainDb extends PgDbConfig { /* ... */ }
class AnalyticsDb extends PgDbConfig { /* ... */ }
class CacheDb extends MySqlDbConfig { /* ... */ }

$main      = MainDb::instance();
$analytics = AnalyticsDb::instance();
$cache     = CacheDb::instance();
```

---

## Practical: health check endpoint

```php
function databaseHealthCheck(): array
{
    $results = [];
    foreach (ConnectionPool::showDbConfigs() as $config) {
        $detail = $config->pingDetail();
        $results[$config::class] = [
            'status'  => $detail['status'] ? 'ok' : 'error',
            'latency' => $detail['latency'] . ' ms',
            'error'   => $detail['error'],
        ];
    }
    return $results;
}
```
