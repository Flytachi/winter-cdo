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

Both `in` and `notIn` **throw** on an empty array:

```php
Qb::in('id', []);
// InvalidArgumentException: Values cannot be empty for IN condition

Qb::notIn('id', []);
// InvalidArgumentException: Values cannot be empty for NOT IN condition
```

Refusing is the safe answer, because there is no harmless one. `col IN ()` is not
valid SQL, so the alternatives would be to drop the condition or to make it always
false — and dropping it is a hole: an empty allow-list would stop restricting
anything and every row would pass. A list that came back empty is a decision the
caller has to make deliberately, so the builder makes it explicit instead of
guessing.

Guard dynamic lists at the call site. Logical operators skip `null`, which makes the
guard a one-liner:

```php
$tagIds = [];   // e.g. came back empty from a query

Qb::and(
    Qb::eq('published', true),
    $tagIds ? Qb::in('tag_id', $tagIds) : null,   // no tags → no condition
);
// SQL:  published IS TRUE
```

When an empty list should instead match *nothing*, say so explicitly — that is a
different intent and deserves to be visible:

```php
$tagIds ? Qb::in('tag_id', $tagIds) : Qb::raw('1 = 0')
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
        $categoryIds    ? Qb::in('category_id', $categoryIds)     : null,
        $excludedBrands ? Qb::notIn('brand_id', $excludedBrands)  : null,
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
