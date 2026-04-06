# CDO — Connection Data Object

`CDO` extends PHP's native `PDO` and adds a set of high-level DML methods
(`insert`, `update`, `delete`, `upsert`, and their batch variants) on top of
the standard PDO API.

All methods are **`final`** — the class is designed to be used as-is, not
subclassed to override behaviour.

---

## Construction

```php
$config = new PgDbCall(host: '127.0.0.1', database: 'myapp', password: 'secret');
$cdo    = new CDO($config);                    // default timeout = 5 s
$cdo    = new CDO($config, timeout: 10);       // custom timeout
$cdo    = new CDO($config, debug: true);       // PDO::ERRMODE_EXCEPTION enabled
```

In practice you rarely construct `CDO` directly — obtain it through a config
class or `ConnectionPool`:

```php
$cdo = AppDb::instance();              // via EntityCallDbTrait
$cdo = ConnectionPool::db(AppDb::class);
```

On construction CDO:
1. Opens the PDO socket using the DSN, username, and password from the config.
2. Applies driver-specific attributes (`ATTR_EMULATE_PREPARES`, `ATTR_DEFAULT_FETCH_MODE`).
3. Synchronises the database session timezone with `date_default_timezone_get()`.
4. Logs the connection DSN at DEBUG level.

Throws {@see CDOException} (wrapping the original `PDOException`) if the
connection cannot be established.

---

## insert — Single Record

```php
public function insert(string $table, object|array $entity): mixed
```

Inserts one row and returns the generated primary key.

- `null` values in `$entity` are **excluded** from the INSERT (the database
  fills them in via defaults or auto-increment).
- The primary key column is assumed to be the **first key** in `$entity`.
- PostgreSQL: uses `RETURNING <primaryKey>`.
- MySQL/MariaDB: uses `PDO::lastInsertId()`.

```php
// Array form:
$id = $cdo->insert('users', [
    'id'    => null,          // excluded — auto-generated
    'name'  => 'Alice',
    'email' => 'alice@example.com',
]);
// SQL: INSERT INTO users (name, email) VALUES (:name, :email) RETURNING id

// Object form:
$user        = new stdClass();
$user->id    = null;
$user->name  = 'Bob';
$user->email = 'bob@example.com';
$id = $cdo->insert('users', $user);
```

Returns the inserted primary key value (`int` or `string` depending on driver).
Throws {@see CDOException} on failure.

---

## insertGroup — Batch Insert

```php
public function insertGroup(string $table, array $entities, int $chunkSize = 1000): void
```

Inserts many rows efficiently.  The array is split into chunks to avoid
exceeding the maximum placeholder count or packet size.

- `null` values in each entity are excluded from that row's INSERT.
- Each chunk is inserted in a single `INSERT INTO … VALUES (…), (…), …` statement.
- The default chunk size is **1 000** rows per query.

```php
$users = [
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob',   'email' => 'bob@example.com'],
    // ... thousands more
];

$cdo->insertGroup('users', $users);              // chunks of 1 000
$cdo->insertGroup('users', $users, chunkSize: 500);  // smaller chunks
```

Throws {@see CDOException} if any chunk fails.

---

## update — Update Records

```php
public function update(string $table, object|array $entity, Qb $qb): int
```

Updates all rows that match the `Qb` condition and returns the number of
affected rows.

- Object form is converted via `get_object_vars()`.
- The condition is always required — there is no "update everything" variant
  to prevent accidental full-table updates.

```php
// Update a single record:
$affected = $cdo->update('users',
    ['name' => 'Alice Smith', 'updated_at' => date('Y-m-d H:i:s')],
    Qb::eq('id', 1)
);

// Update multiple records with a complex condition:
$affected = $cdo->update('users',
    ['status' => 'inactive'],
    Qb::and(
        Qb::lt('last_login', '2024-01-01'),
        Qb::eq('status', 'active'),
    )
);
// SQL: UPDATE users SET status = :S_status
//      WHERE last_login < :iqb0 AND status = :iqb1
```

Returns `int` (number of affected rows).  Throws {@see CDOException} on failure.

---

## delete — Delete Records

```php
public function delete(string $table, Qb $qb): int
```

Deletes all rows that match the condition and returns the number deleted.

```php
// Delete by ID:
$deleted = $cdo->delete('users', Qb::eq('id', 42));

// Delete soft-deleted records older than 30 days:
$deleted = $cdo->delete('users',
    Qb::and(
        Qb::isNotNull('deleted_at'),
        Qb::lt('deleted_at', date('Y-m-d', strtotime('-30 days'))),
    )
);

// Bulk delete by ID list:
$deleted = $cdo->delete('sessions', Qb::in('id', $expiredIds));
```

Returns `int` (rows deleted).  Throws {@see CDOException} on failure.

---

## upsert — Insert or Update a Single Record

```php
public function upsert(
    string       $table,
    object|array $entity,
    array        $conflictColumns,
    ?array       $updateColumns = null
): mixed
```

Inserts the record; if a unique constraint is violated, updates the existing row.

### `$conflictColumns`

The columns that define uniqueness (the conflict target):

```php
['id']                          // single PK
['warehouse_id', 'product_id']  // composite unique key
['email']                       // unique email
```

### `$updateColumns` — expressions with `:new` and `:current`

Defines what to update on conflict.  Use the placeholder tokens in expressions:

| Token | Meaning | PostgreSQL | MySQL |
|-------|---------|-----------|-------|
| `:new` | The incoming value | `EXCLUDED.column` | `VALUES(column)` |
| `:current` | The existing table value | `table.column` | `column` |

```php
// Replace values on conflict:
$cdo->upsert('products',
    ['sku' => 'ABC-001', 'name' => 'Widget', 'price' => 9.99, 'stock' => 50],
    ['sku'],
    [
        'name'  => ':new',
        'price' => ':new',
        'stock' => ':current + :new',   // accumulate
    ]
);
// PostgreSQL:
// INSERT INTO products (sku, name, price, stock) VALUES (...)
//   ON CONFLICT (sku) DO UPDATE SET
//     name  = EXCLUDED.name,
//     price = EXCLUDED.price,
//     stock = products.stock + EXCLUDED.stock

// MySQL:
// INSERT INTO products (...) VALUES (...)
//   ON DUPLICATE KEY UPDATE
//     name  = VALUES(name),
//     price = VALUES(price),
//     stock = stock + VALUES(stock)
```

### Ignore on conflict (DO NOTHING)

Omit or pass `null` as `$updateColumns`:

```php
$cdo->upsert('users', $user, ['email']);
// PostgreSQL: ON CONFLICT (email) DO NOTHING
// MySQL:      INSERT IGNORE INTO users ...
```

Returns the primary key value (PostgreSQL only via `RETURNING`; MySQL returns
`lastInsertId()`).  Returns `null` on conflict with `DO NOTHING`.
Throws {@see CDOException} if `conflictColumns` is empty or query fails.

---

## upsertGroup — Batch Upsert

```php
public function upsertGroup(
    string  $table,
    array   $entities,
    array   $conflictColumns,
    ?array  $updateColumns = null,
    int     $chunkSize = 500
): void
```

Same semantics as `upsert`, but for arrays of records.  Rows are split into
chunks (default **500** per query) to avoid database limits.

The `:new` / `:current` placeholder tokens work identically.

```php
// Inventory sync — accumulate quantity, always take latest cost:
$cdo->upsertGroup(
    'inventory',
    $stockItems,
    ['warehouse_id', 'product_id'],
    [
        'cost'       => ':new',
        'quantity'   => ':current + :new',
        'updated_at' => 'NOW()',
    ],
    chunkSize: 250
);
```

Expressions for `$updateColumns`:

| Expression | Effect |
|-----------|--------|
| `':new'` | Replace existing value with incoming value |
| `':current + :new'` | Add incoming value to existing |
| `':current - :new'` | Subtract incoming from existing |
| `'GREATEST(:current, :new)'` | Keep the larger value |
| `'COALESCE(:new, :current)'` | Use incoming if not null, else keep current |
| `'NOW()'` | Set to current database timestamp (no token needed) |

Throws {@see CDOException} if `conflictColumns` is empty or any chunk fails.

---

## Standard PDO Methods

Since `CDO extends PDO`, all native PDO methods remain available:

```php
// Raw query:
$rows = $cdo->query("SELECT * FROM users")->fetchAll();

// Manual prepared statement:
$stmt = $cdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => 1]);
$user = $stmt->fetch();

// Transactions:
$cdo->beginTransaction();
try {
    $cdo->insert('orders', $order);
    $cdo->update('inventory', ['stock' => $newStock], Qb::eq('id', $productId));
    $cdo->commit();
} catch (\Throwable $e) {
    $cdo->rollBack();
    throw $e;
}
```

---

## Timezone Synchronisation

CDO automatically synchronises the database session timezone with PHP's
`date_default_timezone_get()` on every new connection:

| Driver | SQL executed |
|--------|-------------|
| PostgreSQL | `SET TIMEZONE TO 'Europe/Moscow'` |
| MySQL | `SET time_zone = '+03:00'` |
| Oracle | `ALTER SESSION SET TIME_ZONE = 'Europe/Moscow'` |

This ensures that `NOW()`, `CURRENT_TIMESTAMP`, and date arithmetic produce
consistent results regardless of the database server's system timezone.

---

## Logging

CDO uses a PSR-3 logger (keyed `'CDO'` in `LoggerRegistry`) and logs at
`DEBUG` level:
- Connection DSN on `__construct`
- Query string for every `insert`, `insertGroup`, `update`, `delete`,
  `upsert`, `upsertGroup` call
