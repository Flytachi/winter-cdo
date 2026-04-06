# Mutable Combination Methods

While the static operators (`Qb::and`, `Qb::or`, `Qb::xor`) are **immutable**
and always return a new `Qb`, the instance methods below **modify the receiver
in place**.

| Method | Behaviour |
|--------|-----------|
| `addAnd(Qb $qb)` | Appends `AND condition` to the current instance |
| `addOr(Qb $qb)` | Appends `OR condition` to the current instance |
| `addXor(Qb $qb)` | Appends `XOR condition` to the current instance |

---

## When to use mutable methods

Use the mutable API when you need to **build a condition incrementally** —
typically inside a loop or a sequence of `if` checks — and you want to avoid
accumulating a list of `Qb` objects before combining them at the end.

Use the static API (`Qb::and`, `Qb::or`) everywhere else.  It is easier to
read and reason about because conditions are composed in a single expression.

---

## `addAnd`

```php
$qb->addAnd(Qb $condition): void
```

Appends `AND condition` to the existing SQL.  If the receiver is currently
empty (e.g. created via `Qb::empty()`), the condition is set without a leading
`AND`.

### Example — building a filter in a loop

```php
$qb = Qb::empty();

$filters = [
    'status'  => 'active',
    'country' => 'DE',
    'role'    => 'user',
];

foreach ($filters as $column => $value) {
    $qb->addAnd(Qb::eq($column, $value));
}

$qb->getQuery();
// status = :iqb0 AND country = :iqb1 AND role = :iqb2
```

### Example — conditional accumulation

```php
$qb = Qb::eq('is_published', true);

if ($minPrice !== null) {
    $qb->addAnd(Qb::gte('price', $minPrice));
}

if ($maxPrice !== null) {
    $qb->addAnd(Qb::lte('price', $maxPrice));
}

if (!empty($categoryIds)) {
    $qb->addAnd(Qb::in('category_id', $categoryIds));
}

// Result depending on what was non-null:
// is_published IS TRUE AND price >= :iqb0 AND price <= :iqb1 AND category_id IN (...)
```

---

## `addOr`

```php
$qb->addOr(Qb $condition): void
```

Appends `OR condition` to the existing SQL.

### Example — permission check across multiple columns

```php
$userId = 42;
$qb = Qb::empty();

foreach (['owner_id', 'author_id', 'reviewer_id'] as $column) {
    $qb->addOr(Qb::eq($column, $userId));
}

$qb->getQuery();
// owner_id = :iqb0 OR author_id = :iqb1 OR reviewer_id = :iqb2
```

---

## `addXor`

```php
$qb->addXor(Qb $condition): void
```

Appends `XOR condition` to the existing SQL.

```php
$qb = Qb::eq('flag_a', 1);
$qb->addXor(Qb::eq('flag_b', 1));

$qb->getQuery();
// flag_a = :iqb0 XOR flag_b = :iqb1
```

> **Note:** `XOR` is native to MySQL/MariaDB only — see
> [07-logical-operators.md](07-logical-operators.md) for portability details.

---

## Starting from empty vs. from a condition

You can start with either `Qb::empty()` or an actual condition:

```php
// Starting from empty:
$qb = Qb::empty();
$qb->addAnd(Qb::eq('a', 1));
$qb->getQuery();  // "a = :iqb0"  — no leading "AND"

// Starting from a condition:
$qb = Qb::eq('a', 1);
$qb->addAnd(Qb::eq('b', 2));
$qb->getQuery();  // "a = :iqb0 AND b = :iqb1"
```

---

## Mutable vs. Immutable — side-by-side

```php
// ─── Immutable (static) ─────────────────────────────────────────
$where = Qb::and(
    Qb::eq('status', 'active'),
    Qb::gte('age', 18),
    Qb::isNotNull('email'),
);

// ─── Mutable (incremental) ───────────────────────────────────────
$where = Qb::empty();
$where->addAnd(Qb::eq('status', 'active'));
$where->addAnd(Qb::gte('age', 18));
$where->addAnd(Qb::isNotNull('email'));

// Both produce exactly the same result:
// status = :iqb0 AND age >= :iqb1 AND email IS NOT NULL
```

Choose the style that reads most naturally for your use case.  The immutable
form is preferred for static, known-at-write-time conditions; the mutable form
shines when the list of conditions is determined at runtime.
