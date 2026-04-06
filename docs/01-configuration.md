# Configuration — Connecting to a Database

The configuration layer defines **where** to connect and **how** the
connection behaves.  There are two flavours:

| Flavour | When to use |
|---------|-------------|
| **Config classes** (`DbConfig`, `MySqlDbConfig`, `PgDbConfig`) | Long-lived configs loaded at application start via environment variables or a service container |
| **Call classes** (`DbCall`, `MySqlDbCall`, `PgDbCall`) | Short-lived or inline configs created directly with constructor arguments |

All of them implement `DbConfigInterface` and are interchangeable.

---

## DbConfigInterface

The interface every config must satisfy:

| Method | Description |
|--------|-------------|
| `setUp()` | Initialise credentials (called once by ConnectionPool) |
| `getDns()` | Return the PDO DSN string |
| `getDriver()` | Return the driver name (`'pgsql'`, `'mysql'`, `'oci'`) |
| `getUsername()` | Return the DB username |
| `getPassword()` | Return the DB password |
| `getPersistentStatus()` | Whether PDO persistent connections are on |
| `connect(timeout)` | Open the connection (lazy, no-op if already open) |
| `disconnect()` | Release the CDO reference |
| `reconnect()` | Disconnect then connect |
| `connection()` | Return the active CDO (opens lazily) |
| `ping()` | Return `bool` — `SELECT 1` health check |
| `pingDetail()` | Return `['status', 'latency', 'error']` array |
| `getSchema()` | Return schema name or `null` (PostgreSQL) |

---

## Config Classes (application-level)

Use these when the connection config lives in your application structure —
typically loaded once from environment variables.

### MySQL — `MySqlDbConfig`

```php
use Flytachi\Winter\Cdo\Config\MySqlDbConfig;

class AppMySqlDb extends MySqlDbConfig
{
    public function setUp(): void
    {
        $this->host     = env('DB_HOST', 'localhost');
        $this->port     = (int) env('DB_PORT', 3306);
        $this->database = env('DB_NAME', 'myapp');
        $this->username = env('DB_USER', 'root');
        $this->password = env('DB_PASS', '');
        $this->charset  = 'utf8mb4';        // optional
        $this->isPersistent = false;         // optional (default)
    }
}
```

**Default values** (override only what differs):

| Property | Default |
|----------|---------|
| `$host` | `'localhost'` |
| `$port` | `3306` |
| `$database` | `''` |
| `$username` | `'root'` |
| `$password` | `''` |
| `$charset` | `null` (no charset in DSN) |

### PostgreSQL — `PgDbConfig`

```php
use Flytachi\Winter\Cdo\Config\PgDbConfig;

class AppPgDb extends PgDbConfig
{
    public function setUp(): void
    {
        $this->host     = env('DB_HOST', 'localhost');
        $this->port     = (int) env('DB_PORT', 5432);
        $this->database = env('DB_NAME', 'postgres');
        $this->username = env('DB_USER', 'postgres');
        $this->password = env('DB_PASS', '');
        $this->schema   = 'app_schema';     // optional, default: 'public'
        $this->charset  = 'UTF8';           // optional
    }
}
```

**Default values:**

| Property | Default |
|----------|---------|
| `$host` | `'localhost'` |
| `$port` | `5432` |
| `$database` | `'postgres'` |
| `$username` | `'postgres'` |
| `$password` | `''` |
| `$schema` | `'public'` |
| `$charset` | `null` |

### Generic — `DbConfig`

Use when the driver is not known at compile time or when connecting to Oracle:

```php
use Flytachi\Winter\Cdo\Config\DbConfig;

class OracleDb extends DbConfig
{
    public function setUp(): void
    {
        $this->driver   = 'oci';
        $this->host     = env('ORACLE_HOST');
        $this->port     = 1521;
        $this->database = env('ORACLE_SID');
        $this->username = env('ORACLE_USER');
        $this->password = env('ORACLE_PASS');
    }
}
```

---

## Call Classes (inline / one-off)

Use these when you need a connection built from runtime values — e.g. in a CLI
tool, a test fixture, or a multi-tenant system where credentials vary per request.

### `MySqlDbCall`

```php
use Flytachi\Winter\Cdo\Config\Call\MySqlDbCall;

$config = new MySqlDbCall(
    host:     '127.0.0.1',
    database: 'myapp',
    username: 'root',
    password: 'secret',
    charset:  'utf8mb4',   // optional
);

$cdo = $config->connection();
```

All parameters are optional and fall back to the same defaults as `MySqlDbConfig`.

### `PgDbCall`

```php
use Flytachi\Winter\Cdo\Config\Call\PgDbCall;

$config = new PgDbCall(
    host:     '127.0.0.1',
    database: 'myapp',
    username: 'postgres',
    password: 'secret',
    schema:   'app_schema',  // optional
    charset:  'UTF8',        // optional
);

$cdo = $config->connection();
```

### `DbCall` (generic)

```php
use Flytachi\Winter\Cdo\Config\Call\DbCall;

$config = new DbCall(
    driver:   'mysql',
    host:     '127.0.0.1',
    port:     3306,
    database: 'myapp',
    username: 'root',
    password: 'secret',
);

$cdo = $config->connection();
```

---

## Connection Lifecycle

All config classes share the same lifecycle provided by `BaseDbConfig`:

```
setUp()          ← called once by ConnectionPool
    ↓
connect()        ← lazy, called on first connection() or explicit call
    ↓
CDO created      ← PDO connection opened, timezone synced
    ↓
connection()     ← returns the cached CDO
    ↓
disconnect()     ← releases reference, triggers GC / socket close
```

```php
$config = new AppPgDb();
$config->setUp();

// Lazy — no socket opened yet:
// First call opens the connection:
$cdo = $config->connection();

// Subsequent calls return the same CDO:
$cdo2 = $config->connection();  // same object

// Health check:
$config->ping();         // bool
$config->pingDetail();   // ['status' => true, 'latency' => 1.23, 'error' => null]

// Reconnect after detecting a stale connection:
$config->reconnect();
$cdo = $config->connection();  // new CDO
```

---

## Persistent Connections

Set `$isPersistent = true` in your config to use PDO connection pooling
(persistent connections shared between PHP-FPM workers):

```php
class AppDb extends PgDbConfig
{
    protected bool $isPersistent = true;

    public function setUp(): void { /* ... */ }
}
```

> **Warning:** Persistent connections can cause session state leakage between
> requests (temporary tables, `SET` commands, transactions left open).  Only
> enable if you understand the implications for your database and workload.

---

## DSN Format

The base `getDns()` method produces:

```
<driver>:host=<host>;port=<port>;dbname=<database>;
```

MySQL with charset appended:
```
mysql:host=localhost;port=3306;dbname=myapp;charset=utf8mb4;
```

PostgreSQL with client encoding:
```
pgsql:host=localhost;port=5432;dbname=myapp;options='--client_encoding=UTF8';
```
