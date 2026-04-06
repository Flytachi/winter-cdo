# Special Methods — `custom` and `empty`

---

## `custom` — Raw SQL Fragment

```php
public static function custom(string $query): Qb
```

Inserts an arbitrary SQL string **verbatim** into the query, with **no
parameterisation** and **no SQL-injection protection**.

The resulting `Qb` contains the raw string as its query and an empty bind list.

```php
Qb::custom('NOW()')
// SQL:  NOW()
// Bind: (none)

Qb::custom('JSON_CONTAINS(tags, \'"php"\')')
// SQL:  JSON_CONTAINS(tags, '"php"')
// Bind: (none)

Qb::custom('ST_Distance(location, POINT(55.75, 37.62)) < 5000')
// SQL:  ST_Distance(location, POINT(55.75, 37.62)) < 5000
// Bind: (none)
```

### When to use `custom`

Use `custom` only when no standard `Qb` method covers the SQL construct you need:

- Vendor-specific functions (`JSON_CONTAINS`, `ST_Distance`, `tsquery`, …)
- Window function expressions
- Subquery conditions (though prefer composing via your ORM / CDO layer)
- Any other raw expression where you control all values programmatically

### When NOT to use `custom`

Do not use `custom` to avoid writing a few extra `Qb::eq` calls.  The entire
point of the builder is to parameterise values — bypassing it for convenience
introduces risk.

### Security rule

> **Never** interpolate user-supplied data into the string passed to `custom`.

```php
// ✅ Safe — all values are known constants:
Qb::custom("DATE_FORMAT(created_at, '%Y-%m') = '2024-06'")

// ❌ UNSAFE — user input injected directly:
$month = $_GET['month'];   // e.g. "' OR 1=1 --"
Qb::custom("DATE_FORMAT(created_at, '%Y-%m') = '{$month}'")  // SQL injection!
```

If you need to parameterise a value inside a vendor-specific function, combine
`Qb::custom` for the expression with a manually bound parameter outside the
builder, or use `CDOBind` and reference the placeholder name inside the string:

```php
// Manually construct the placeholder and bind it yourself:
$bind = new CDOBind('month_val', '2024-06');

// Reference the placeholder inside the raw string:
$qb = Qb::custom("DATE_FORMAT(created_at, '%Y-%m') = {$bind->getName()}");

// Pass the bind separately — custom() produces no binds on its own:
// You will need to bind :month_val manually when executing.
```

---

## `empty` — No-Op Condition

```php
public static function empty(): Qb
```

Returns a `Qb` instance whose query is an empty string and whose bind list is
empty.  It produces no SQL output.

```php
$qb = Qb::empty();

$qb->getQuery();   // ""
$qb->getBinds();   // []
```

### Where `empty` is used

#### 1. Returned automatically

`in()` and `notIn()` return `Qb::empty()` when their value array is empty,
preventing invalid SQL like `id IN ()`:

```php
Qb::in('id', [])
// Returns Qb::empty()
// getQuery() === ''
```

#### 2. Starting point for incremental build

When you do not have an initial condition but need to accumulate one:

```php
$qb = Qb::empty();

foreach ($activeFilters as $filter) {
    $qb->addAnd($filter);
}

// If $activeFilters is empty, $qb is still Qb::empty()
// and getQuery() returns '' — safe to pass to a WHERE clause builder
// that checks for an empty string.
```

#### 3. Conditional placeholder in logical operators

```php
Qb::and(
    Qb::eq('published', true),
    $showDrafts ? null : Qb::empty(),  // either null or empty — both skipped
)
```

### Behaviour in logical operators

Empty conditions are always skipped inside `and`, `or`, `xor`, `addAnd`,
`addOr`, and `addXor`.  They never contribute a fragment or a bind:

```php
Qb::and(
    Qb::eq('a', 1),
    Qb::empty(),          // skipped
    Qb::eq('b', 2),
    null,                 // skipped
)
// SQL:  a = :iqb0 AND b = :iqb1
```
