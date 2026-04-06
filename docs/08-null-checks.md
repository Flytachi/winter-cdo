# NULL Checks

## `isNull` — IS NULL

```
column IS NULL
```

Returns `TRUE` for rows where the column contains a SQL `NULL`.  
No bind parameter is produced (NULL is not a value that can be parameterised
in a standard `IS NULL` check).

```php
Qb::isNull('deleted_at')
// SQL:  deleted_at IS NULL
// Bind: (none)

Qb::isNull('manager_id')
// SQL:  manager_id IS NULL
// Bind: (none)
```

---

## `isNotNull` — IS NOT NULL

```
column IS NOT NULL
```

Returns `TRUE` for rows where the column contains any non-NULL value.

```php
Qb::isNotNull('email')
// SQL:  email IS NOT NULL
// Bind: (none)

Qb::isNotNull('confirmed_at')
// SQL:  confirmed_at IS NOT NULL
// Bind: (none)
```

---

## Automatic dispatch from `eq` and `neq`

You do **not** need to call `isNull` / `isNotNull` explicitly when working
with `eq` or `neq`.  Both methods detect a `null` argument and delegate
automatically:

```php
Qb::eq('deleted_at', null)   // → Qb::isNull('deleted_at')
// SQL:  deleted_at IS NULL

Qb::neq('email', null)       // → Qb::isNotNull('email')
// SQL:  email IS NOT NULL
```

Use the explicit `isNull` / `isNotNull` forms when you build conditions
dynamically and want the intent to be unmistakably clear.

---

## Practical patterns

### Filter soft-deleted rows

```php
// Only active (not deleted) records:
Qb::isNull('deleted_at')
// deleted_at IS NULL

// Only deleted records:
Qb::isNotNull('deleted_at')
// deleted_at IS NOT NULL
```

### Combine NULL check with other conditions

```php
Qb::and(
    Qb::eq('status', 'active'),
    Qb::isNull('banned_at'),
    Qb::isNotNull('email'),
)
// status = :iqb0 AND banned_at IS NULL AND email IS NOT NULL
```

### Optional filter — include all if value is null

```php
function filterByManager(?int $managerId): Qb
{
    if ($managerId === null) {
        return Qb::isNull('manager_id');       // unassigned rows only
    }
    return Qb::eq('manager_id', $managerId);   // specific manager
}
```
