# CDOStatement — Type-Aware Statement Wrapper

`CDOStatement` wraps a native `PDOStatement` and adds one critical feature:
**automatic PDO type detection** via `bindTypedValue()`.

In standard PDO, `bindValue()` defaults to `PDO::PARAM_STR` for everything.
This means `null` gets sent as an empty string, `true` becomes `"1"`, and
`0` stays as `"0"` — all of which can silently corrupt data or produce wrong
query results.  `CDOStatement` eliminates this category of bug.

---

## Construction

```php
$stmt = new CDOStatement($pdo->prepare($sql));
```

Typically you never construct `CDOStatement` directly — `CDO` creates one
internally for every prepared query.

---

## `bindTypedValue` — Auto-Typed Binding

```php
public function bindTypedValue(string $parameter, mixed $value): bool
```

Inspects `gettype($value)` and selects the appropriate PDO constant:

| PHP type | PDO type constant | Notes |
|----------|------------------|-------|
| `null` | `PDO::PARAM_NULL` | Sends SQL NULL |
| `bool` | `PDO::PARAM_BOOL` | Sends `true`/`false` correctly |
| `int` | `PDO::PARAM_INT` | Integer-typed binding |
| `array` | `PDO::PARAM_STR` | JSON-encoded before binding |
| `object` | `PDO::PARAM_STR` | Converted via `valObject()` — see below |
| `float`, `string` | `PDO::PARAM_STR` | Default string binding |

```php
$stmt->bindTypedValue(':active', true);      // PDO::PARAM_BOOL
$stmt->bindTypedValue(':count',  42);        // PDO::PARAM_INT
$stmt->bindTypedValue(':ratio',  3.14);      // PDO::PARAM_STR (PDO has no FLOAT type)
$stmt->bindTypedValue(':tags',   ['a','b']); // PDO::PARAM_STR, bound as '["a","b"]'
$stmt->bindTypedValue(':deleted',null);      // PDO::PARAM_NULL
$stmt->bindTypedValue(':name',  'Alice');    // PDO::PARAM_STR
```

---

## `valObject` — Object Serialisation

```php
public function valObject(object $value): mixed
```

Called automatically by `bindTypedValue` when the value is an object.
Inspects the object's interfaces in priority order:

| Interface | Conversion | Example |
|-----------|-----------|---------|
| `JsonSerializable` | `$obj->jsonSerialize()` | Custom JSON payload |
| `Stringable` | `(string) $obj` | Value objects with `__toString` |
| `DateTimeInterface` | `$obj->format('Y-m-d H:i:s')` | `DateTime`, `DateTimeImmutable` |
| `BackedEnum` | `$obj->value` | `enum Status: string { case Active = 'active'; }` |
| anything else | `serialize($obj)` | PHP serialisation fallback |

### Examples

```php
// DateTimeImmutable:
$stmt->bindTypedValue(':created', new DateTimeImmutable('2024-06-15 12:00:00'));
// Bound as: '2024-06-15 12:00:00'

// BackedEnum:
enum Status: string { case Active = 'active'; case Banned = 'banned'; }
$stmt->bindTypedValue(':status', Status::Active);
// Bound as: 'active'

// Stringable value object:
class Money implements Stringable {
    public function __construct(private int $cents) {}
    public function __toString(): string { return (string) $this->cents; }
}
$stmt->bindTypedValue(':price', new Money(999));
// Bound as: '999'

// JsonSerializable:
class GeoPoint implements JsonSerializable {
    public function __construct(public float $lat, public float $lng) {}
    public function jsonSerialize(): mixed { return ['lat' => $this->lat, 'lng' => $this->lng]; }
}
$stmt->bindTypedValue(':location', new GeoPoint(55.75, 37.62));
// Bound as: ['lat' => 55.75, 'lng' => 37.62]  (passed to bindValue as array)
```

---

## `bindValue` — Explicit Type Binding

```php
public function bindValue(string|int $parameter, mixed $value, int $data_type = PDO::PARAM_STR): bool
```

Direct delegation to `PDOStatement::bindValue()`.  The binding is recorded
internally for replay.  Use this when you need to override the auto-detected type.

```php
$stmt->bindValue(':flag', 1, PDO::PARAM_INT);
```

---

## `getBindings` — Inspect Recorded Bindings

```php
public function getBindings(): array
```

Returns all bindings recorded so far as an array of
`[$parameter, $value, $pdoType]` triples.

Useful for debugging or logging the exact values sent to the database:

```php
$bindings = $stmt->getBindings();
foreach ($bindings as [$param, $value, $type]) {
    echo "$param => " . var_export($value, true) . " (type: $type)\n";
}
```

---

## `updateStm` — Replay Bindings on a New Statement

```php
public function updateStm(PDOStatement $stmt): void
```

Replaces the internal `PDOStatement` and re-applies all recorded bindings to
the new statement.

This is used when a connection is recycled (reconnect scenario) — rather than
re-building all bindings from scratch, the wrapper replays them automatically:

```php
// Connection dropped, statement re-prepared:
$newPdoStmt = $cdo->prepare($sql);
$cdoStmt->updateStm($newPdoStmt);
$cdoStmt->getStmt()->execute();
```

---

## `getStmt` — Access the Underlying PDOStatement

```php
public function getStmt(): PDOStatement
```

Returns the wrapped `PDOStatement` for calling native PDO methods:

```php
$cdoStmt->getStmt()->execute();
$rows  = $cdoStmt->getStmt()->fetchAll();
$count = $cdoStmt->getStmt()->rowCount();
$col   = $cdoStmt->getStmt()->fetchColumn();
```
