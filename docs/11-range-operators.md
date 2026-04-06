# Range Operators — BETWEEN

`BETWEEN` is shorthand for `column >= min AND column <= max`.
Both bounds are **inclusive**.

---

## `between` — Column Within a Range

```
column BETWEEN :min AND :max
```

The column value must lie within `[min, max]` (inclusive on both ends).  
Works with numbers, date strings, and any ordered type your database supports.

```php
Qb::between('age', 18, 65)
// SQL:  age BETWEEN :iqb0 AND :iqb1
// Bind: :iqb0 => 18, :iqb1 => 65

Qb::between('price', 100.0, 999.99)
// SQL:  price BETWEEN :iqb0 AND :iqb1
// Bind: :iqb0 => 100.0, :iqb1 => 999.99

Qb::between('created_at', '2024-01-01', '2024-12-31')
// SQL:  created_at BETWEEN :iqb0 AND :iqb1
// Bind: :iqb0 => '2024-01-01', :iqb1 => '2024-12-31'
```

### With named CDOBind

```php
$minBind = new CDOBind('price_min', 50);
$maxBind = new CDOBind('price_max', 200);

Qb::between('price', $minBind, $maxBind)
// SQL:  price BETWEEN :price_min AND :price_max
// Bind: :price_min => 50, :price_max => 200
```

---

## `notBetween` — Column Outside a Range

```
column NOT BETWEEN :min AND :max
```

Returns `TRUE` when the column value is **strictly outside** `[min, max]`.

```php
Qb::notBetween('price', 10, 50)
// SQL:  price NOT BETWEEN :iqb0 AND :iqb1
// Bind: :iqb0 => 10, :iqb1 => 50

Qb::notBetween('score', 0, 59)
// SQL:  score NOT BETWEEN :iqb0 AND :iqb1
// Bind: :iqb0 => 0, :iqb1 => 59
// Matches: score >= 60 or score < 0  (i.e. a passing score)
```

---

## `betweenBy` — Scalar Value Within a Column Range

```
:value BETWEEN column1 AND column2
```

The operands are flipped: the **scalar value** is tested against the
range `[column1, column2]` that is stored *in the row itself*.

This is useful when each row holds its own validity window, price range,
or schedule slot.

```php
Qb::betweenBy('2024-06-15', 'valid_from', 'valid_to')
// SQL:  :iqb0 BETWEEN valid_from AND valid_to
// Bind: :iqb0 => '2024-06-15'
// TRUE for rows where valid_from <= '2024-06-15' <= valid_to

Qb::betweenBy(750, 'min_score', 'max_score')
// SQL:  :iqb0 BETWEEN min_score AND max_score
// Bind: :iqb0 => 750
```

### With a named CDOBind

```php
$today = new CDOBind('today', date('Y-m-d'));

Qb::betweenBy($today, 'starts_at', 'ends_at')
// SQL:  :today BETWEEN starts_at AND ends_at
// Bind: :today => '2024-06-15'
```

---

## `notBetweenBy` — Scalar Value Outside a Column Range

```
:value NOT BETWEEN column1 AND column2
```

Returns `TRUE` when the scalar value lies **outside** the per-row range.

```php
Qb::notBetweenBy('2020-01-01', 'valid_from', 'valid_to')
// SQL:  :iqb0 NOT BETWEEN valid_from AND valid_to
// Bind: :iqb0 => '2020-01-01'
// TRUE for rows where the date is before valid_from or after valid_to

Qb::notBetweenBy(30, 'min_age', 'max_age')
// SQL:  :iqb0 NOT BETWEEN min_age AND max_age
// Bind: :iqb0 => 30
```

---

## BETWEEN vs. Two Comparisons

`BETWEEN` is exactly equivalent to `>= min AND <= max`.  Both approaches
produce the same execution plan on modern databases.

```php
// These are identical in result:
Qb::between('age', 18, 65)

Qb::and(
    Qb::gte('age', 18),
    Qb::lte('age', 65),
)
```

Use `between` when the range has a clear semantic meaning (an age window, a
date period, a price band).  Use separate comparisons when you need the
individual bounds in more complex logic.

---

## Practical patterns

### Date range filter (event listing)

```php
$from = '2024-06-01';
$to   = '2024-06-30';

Qb::and(
    Qb::eq('is_published', true),
    Qb::between('event_date', $from, $to),
)
// is_published IS TRUE AND event_date BETWEEN :iqb0 AND :iqb1
```

### Row-level validity window (e.g., promotions)

```php
// Find promotions that are active right now:
$now = date('Y-m-d H:i:s');

Qb::and(
    Qb::eq('is_active', true),
    Qb::betweenBy($now, 'starts_at', 'ends_at'),
)
// is_active IS TRUE AND :iqb0 BETWEEN starts_at AND ends_at
```

### Price range with named binds (readable query log)

```php
$min = new CDOBind('min_price', $_GET['price_min'] ?? 0);
$max = new CDOBind('max_price', $_GET['price_max'] ?? 99999);

Qb::between('price', $min, $max)
// price BETWEEN :min_price AND :max_price
// Makes the query log immediately readable when debugging
```

### Exclude a forbidden range

```php
// Products whose price is NOT in the €10–€50 clearance range:
Qb::and(
    Qb::eq('in_stock', true),
    Qb::notBetween('price', 10, 50),
)
// in_stock IS TRUE AND price NOT BETWEEN :iqb0 AND :iqb1
```
