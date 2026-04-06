# Set Operators — IN / NOT IN

## `in` — Set Membership

```
column IN (:p0, :p1, :p2, ...)
```

Returns `TRUE` when the column value matches **any** element in the list.  
Each element in the array is bound to its own placeholder.

```php
Qb::in('status', ['active', 'pending', 'trial'])
// SQL:  status IN (:iqb0, :iqb1, :iqb2)
// Bind: :iqb0 => 'active', :iqb1 => 'pending', :iqb2 => 'trial'

Qb::in('id', [1, 2, 3, 4])
// SQL:  id IN (:iqb0, :iqb1, :iqb2, :iqb3)
// Bind: :iqb0 => 1, :iqb1 => 2, :iqb2 => 3, :iqb3 => 4

Qb::in('category_id', [10, 20, 30])
// SQL:  category_id IN (:iqb0, :iqb1, :iqb2)
```

---

## `notIn` — Set Exclusion

```
column NOT IN (:p0, :p1, ...)
```

Returns `TRUE` when the column value does **not** match any element in the list.

```php
Qb::notIn('role', ['banned', 'suspended', 'ghost'])
// SQL:  role NOT IN (:iqb0, :iqb1, :iqb2)
// Bind: :iqb0 => 'banned', :iqb1 => 'suspended', :iqb2 => 'ghost'

Qb::notIn('country_code', ['XX', 'ZZ'])
// SQL:  country_code NOT IN (:iqb0, :iqb1)
```

---

## Empty array behaviour

Both `in` and `notIn` return an **empty `Qb`** when the array is empty.  
An empty `Qb` is silently ignored by all logical operators (`and`, `or`, etc.),
so the surrounding condition is not broken.

```php
$allowedIds = [];  // e.g. came from an empty database query

$condition = Qb::and(
    Qb::eq('active', true),
    Qb::in('id', $allowedIds),  // array is empty → skipped
);

// SQL:  active IS TRUE
// (no broken "active IS TRUE AND id IN ()" syntax)
```

This means you can pass dynamic lists to `in` / `notIn` **without a pre-check**:

```php
// ✅ Safe — no need to guard with if (count($ids) > 0):
Qb::and(
    Qb::eq('published', true),
    Qb::in('tag_id', $tagIds),
)
```

---

## NULL safety note

Neither `in` nor `notIn` handle `NULL` elements in the array specially.  
If `$values` contains `null`, it will be bound as a NULL parameter and the
database will evaluate it according to standard SQL three-valued logic
(which means `col IN (NULL)` will never match any row).

To check for NULL membership, use `isNull` / `isNotNull` explicitly and combine
with `or`:

```php
// "status IN ('active', 'pending') OR status IS NULL"
Qb::or(
    Qb::in('status', ['active', 'pending']),
    Qb::isNull('status'),
)
```

---

## Practical patterns

### Dynamic filter from request parameters

```php
function buildProductFilter(array $categoryIds, array $excludedBrands): Qb
{
    return Qb::and(
        Qb::eq('is_published', true),
        Qb::in('category_id', $categoryIds),        // safe even if empty
        Qb::notIn('brand_id', $excludedBrands),     // safe even if empty
    );
}
```

### Combining IN with other conditions

```php
Qb::and(
    Qb::in('role', ['admin', 'moderator']),
    Qb::isNull('banned_at'),
    Qb::gte('created_at', '2023-01-01'),
)
// role IN (:iqb0, :iqb1) AND banned_at IS NULL AND created_at >= :iqb2
```

### NOT IN for blocklist

```php
$blocklist = loadBlockedUserIds(); // returns int[]

Qb::and(
    Qb::eq('active', true),
    Qb::notIn('user_id', $blocklist),
)
// active IS TRUE AND user_id NOT IN (:iqb0, :iqb1, ...)
```
