# CDOException — Error Handling

## Overview

`CDOException` is the single exception type thrown by `CDO` for all
database-level failures.  It extends the framework base `Exception` class and
sets the log level to `LogLevel::ALERT`.

```
CDOException
    └── extends \RuntimeException
```

---

## When It Is Thrown

| Scenario | Method |
|----------|--------|
| PDO connection failed | `CDO::__construct()` |
| INSERT query failed | `CDO::insert()` |
| INSERT returned no result | `CDO::insert()` (result falsy) |
| Batch INSERT failed | `CDO::insertGroup()` |
| UPDATE query failed | `CDO::update()` |
| DELETE query failed | `CDO::delete()` |
| `conflictColumns` is empty | `CDO::upsert()`, `CDO::upsertGroup()` |
| UPSERT query failed | `CDO::upsert()`, `CDO::upsertGroup()` |

---

## Exception Chain

Every `CDOException` wraps the original `PDOException` as its `$previous`
cause.  This preserves the SQLSTATE code, driver error message, and full stack
trace:

```php
try {
    $cdo->insert('users', $data);
} catch (CDOException $e) {
    echo $e->getMessage();           // "Error when creating a record in the database (...)"
    echo $e->getPrevious()->getMessage(); // Original PDO message with SQLSTATE
    echo $e->getPrevious()->getCode();    // SQLSTATE code (e.g. "23505" for unique violation)
}
```

---

## Log Level

All `CDOException` instances are automatically logged at `LogLevel::ALERT`
by the framework's exception handler.  This level signals that a database
failure has occurred and may require immediate attention.

---

## Catching Database Errors

### Constraint violation (unique key / FK)

```php
try {
    $cdo->insert('users', ['email' => 'alice@example.com', 'name' => 'Alice']);
} catch (CDOException $e) {
    $pdoEx = $e->getPrevious();

    // PostgreSQL unique violation: SQLSTATE 23505
    // MySQL duplicate entry: SQLSTATE 23000
    if ($pdoEx && str_starts_with($pdoEx->getCode(), '23')) {
        throw new DuplicateEmailException('Email already registered');
    }

    throw $e;  // Re-throw unrecognised errors
}
```

### Connection failure with fallback

```php
try {
    $cdo = ConnectionPool::db(MainDb::class);
    $rows = $cdo->query("SELECT * FROM settings")->fetchAll();
} catch (CDOException $e) {
    // Log and return cached/default values
    logger()->alert('Database unreachable', ['error' => $e->getMessage()]);
    return $this->getCachedSettings();
}
```

### Graceful reconnect

```php
try {
    $result = ConnectionPool::db(MainDb::class)->insert('events', $eventData);
} catch (CDOException $e) {
    // Reconnect once and retry
    ConnectionPool::getConfigDb(MainDb::class)->reconnect();
    $result = ConnectionPool::db(MainDb::class)->insert('events', $eventData);
}
```

---

## SQLSTATE Reference (Common Codes)

| Code | Meaning | Database |
|------|---------|----------|
| `23000` | Integrity constraint violation | MySQL/MariaDB |
| `23505` | Unique violation | PostgreSQL |
| `23503` | Foreign key violation | PostgreSQL |
| `42P01` | Undefined table | PostgreSQL |
| `42000` | Syntax error | MySQL |
| `08006` | Connection failure | PostgreSQL |
| `HY000` | General error | Various |

Access the code via `$e->getPrevious()->getCode()`.
