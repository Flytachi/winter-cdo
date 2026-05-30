# Special Methods — `raw` and `empty`

---

## `raw` — Raw SQL Fragment

```php
public static function raw(string $query, array $binds = []): Qb
```

Inserts an arbitrary SQL string **verbatim** into the query. The query string
itself is **not escaped or validated**, but values can be bound safely through
the optional `$binds` argument using named placeholders.

Without `$binds`, the resulting `Qb` contains the raw string as its query and an
empty bind list.

```php
Qb::raw('NOW()')
// SQL:  NOW()
// Bind: (none)

Qb::raw('JSON_CONTAINS(tags, \'"php"\')')
// SQL:  JSON_CONTAINS(tags, '"php"')
// Bind: (none)

Qb::raw('ST_Distance(location, POINT(55.75, 37.62)) < 5000')
// SQL:  ST_Distance(location, POINT(55.75, 37.62)) < 5000
// Bind: (none)
```

### Binding values with `$binds`

`$binds` accepts either shape — and a mix of both:

- `name => value` pairs: `['tag' => '"php"']`
- `CDOBind` objects: `[new CDOBind('tag', '"php"')]`

String keys are turned into `CDOBind` automatically (the leading `:` is
optional); existing `CDOBind` elements are used as-is.

```php
// Associative pairs:
Qb::raw('JSON_CONTAINS(tags, :tag) AND views > :v', ['tag' => '"php"', 'v' => 100])
// SQL:  JSON_CONTAINS(tags, :tag) AND views > :v
// Bind: :tag => '"php"', :v => 100

// CDOBind objects:
Qb::raw('views > :v', [new CDOBind('v', 100)])
// SQL:  views > :v
// Bind: :v => 100

// Mixed:
Qb::raw('a = :x AND b = :y', ['x' => 1, new CDOBind('y', 2)])
// SQL:  a = :x AND b = :y
// Bind: :x => 1, :y => 2
```

These binds flow through `getBinds()` and merge automatically when the fragment
is combined via `and()` / `or()` / `xor()` — exactly like any other `Qb`
condition.

### When to use `raw`

Use `raw` only when no standard `Qb` method covers the SQL construct you need:

- Vendor-specific functions (`JSON_CONTAINS`, `ST_Distance`, `tsquery`, …)
- Window function expressions
- Subquery conditions (though prefer composing via your ORM / CDO layer)
- Any other raw expression where you control the query structure programmatically

### When NOT to use `raw`

Do not use `raw` to avoid writing a few extra `Qb::eq` calls.  Even though
`$binds` parameterises the *values*, the **query string is still raw** — the
standard operators give you that safety for the whole expression.

### Security rule

> **Never** interpolate user-supplied data into the query string passed to
> `raw`. Dynamic values belong in `$binds`, referenced by a named placeholder.

```php
// ✅ Safe — value passed through $binds:
Qb::raw("DATE_FORMAT(created_at, '%Y-%m') = :month", ['month' => $userMonth])

// ✅ Safe — static SQL with no dynamic parts:
Qb::raw("DATE_FORMAT(created_at, '%Y-%m') = '2024-06'")

// ❌ UNSAFE — user input injected into the query string:
$month = $_GET['month'];   // e.g. "' OR 1=1 --"
Qb::raw("DATE_FORMAT(created_at, '%Y-%m') = '{$month}'")  // SQL injection!
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
