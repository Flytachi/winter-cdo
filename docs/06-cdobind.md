# CDOBind — Named Parameter Container

`CDOBind` is a small, immutable value object that pairs a **placeholder name**
with a **value** to be bound to a prepared statement.

```php
readonly class CDOBind
{
    public function __construct(string $name, mixed $value) {}
    public function getName(): string {}   // always starts with ':'
    public function getValue(): mixed {}
}
```

---

## Construction

```php
// With the ':' prefix (explicit):
$bind = new CDOBind(':user_id', 42);

// Without the ':' prefix — it is added automatically:
$bind = new CDOBind('user_id', 42);

$bind->getName();   // ':user_id'
$bind->getValue();  // 42
```

The constructor normalises the name: if it does not start with `:`, one is
prepended.  The resulting name is always `:something`.

---

## Why use CDOBind directly?

Under normal circumstances you pass scalar values to `Qb` methods and let the
builder generate placeholders automatically (`:iqb0`, `:iqb1`, …).  There are
two situations where creating a `CDOBind` yourself is useful.

### 1. Readable placeholder names

Auto-generated names (`:iqb0`) are opaque.  For complex queries — especially
when you inspect the SQL during debugging — a descriptive name makes the query
much easier to read.

```php
// Auto-generated:
Qb::eq('user_id', 42)
// user_id = :iqb0   ← what is :iqb0?

// Named CDOBind:
Qb::eq('user_id', new CDOBind('uid', 42))
// user_id = :uid    ← immediately clear
```

### 2. Reusing the same value in multiple conditions

PDO named parameters can appear multiple times in one SQL string.  When you
pass the **same `CDOBind` instance** to several `Qb` methods, all of them emit
the same placeholder name — the value is bound exactly once.

```php
$targetId = new CDOBind('target', 99);

$qb = Qb::or(
    Qb::eq('sender_id',   $targetId),
    Qb::eq('receiver_id', $targetId),
    Qb::eq('cc_id',       $targetId),
);

// SQL:
// sender_id = :target OR receiver_id = :target OR cc_id = :target

// Binds: three CDOBind objects, all with name ':target' and value 99.
// When executed, the statement binds :target once and reuses it.
```

Compare with passing the scalar `99` three times:

```php
$qb = Qb::or(
    Qb::eq('sender_id',   99),
    Qb::eq('receiver_id', 99),
    Qb::eq('cc_id',       99),
);

// SQL:
// sender_id = :iqb0 OR receiver_id = :iqb1 OR cc_id = :iqb2

// Binds: three separate placeholders, each bound to 99 independently.
```

Both approaches produce **correct results**.  Use `CDOBind` reuse when you
want a single canonical placeholder for the same logical value.

---

## Supported value types

`CDOBind` accepts `mixed` — any PHP value can be stored.  The bound value is
passed as-is to `CDOStatement::bindTypedValue()`, which maps PHP types to PDO
parameter types.

Typical value types:

| PHP type | PDO binding |
|----------|-------------|
| `int` | `PDO::PARAM_INT` |
| `bool` | `PDO::PARAM_BOOL` |
| `null` | `PDO::PARAM_NULL` |
| `string` | `PDO::PARAM_STR` |
| `float` | `PDO::PARAM_STR` (PDO has no PARAM_FLOAT) |
| `Stringable` | cast to string |
| `DateTimeInterface` | formatted as string |
| `BackedEnum` | uses `->value` |

---

## CDOBind in Qb methods — type signature

Every `Qb` operator that accepts a scalar value also accepts `CDOBind` as a
**union type**:

```php
Qb::eq(string $column, CDOBind|bool|int|float|string|null $value)
Qb::gt(string $column, CDOBind|int|float|string $value)
Qb::like(string $column, CDOBind|string $value)
// … and all other operators
```

When you pass a `CDOBind`, `Qb::inject()` returns it unchanged — no new
placeholder is generated:

```php
private static function inject(CDOBind|string|int|float $value): CDOBind
{
    if ($value instanceof CDOBind) {
        return $value;   // ← reused as-is
    }
    return new CDOBind(':iqb' . (self::$placeholderCounter++), $value);
}
```
