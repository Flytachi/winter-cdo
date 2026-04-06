# Advanced Examples

This file demonstrates how to compose multiple `Qb` features to solve
real-world filtering, search, and data-access problems.

---

## Example 1 — E-commerce Product Listing

**Requirements:**
- Only published products
- Category matches one of the selected categories (or all if none selected)
- Price within a user-specified range
- Keyword match in name or description
- Not from a blocked brand

```php
function buildProductQuery(
    array  $categoryIds,
    ?float $priceMin,
    ?float $priceMax,
    ?string $keyword,
    array  $blockedBrandIds
): Qb {
    return Qb::and(
        Qb::eq('is_published', true),

        // Category filter — skipped entirely if $categoryIds is empty
        Qb::in('category_id', $categoryIds),

        // Price range — each bound applied independently if present
        $priceMin !== null ? Qb::gte('price', $priceMin) : null,
        $priceMax !== null ? Qb::lte('price', $priceMax) : null,

        // Keyword search across two columns — grouped with OR inside AND
        $keyword !== null
            ? Qb::clip(Qb::or(
                Qb::like('name',        '%' . $keyword . '%'),
                Qb::like('description', '%' . $keyword . '%'),
            ))
            : null,

        // Brand exclusion — skipped if blocklist is empty
        Qb::notIn('brand_id', $blockedBrandIds),
    );
}

// buildProductQuery([5, 12], 100, 500, 'wireless', [99, 101])
//
// is_published IS TRUE
//   AND category_id IN (:iqb0, :iqb1)
//   AND price >= :iqb2
//   AND price <= :iqb3
//   AND (name LIKE :iqb4 OR description LIKE :iqb5)
//   AND brand_id NOT IN (:iqb6, :iqb7)
```

---

## Example 2 — Multi-Role Access Control

**Requirements:**
- Admins see everything
- Editors see only their own published content or drafts assigned to them
- Regular users see only published content

```php
function buildAccessFilter(int $userId, string $role): Qb
{
    return match ($role) {
        'admin' => Qb::empty(),   // no restriction

        'editor' => Qb::clip(
            Qb::or(
                Qb::eq('is_published', true),
                Qb::and(
                    Qb::eq('is_published', false),
                    Qb::clip(
                        Qb::or(
                            Qb::eq('author_id',   $userId),
                            Qb::eq('assignee_id', $userId),
                        )
                    ),
                ),
            )
        ),

        default => Qb::eq('is_published', true),
    };
}

// For role = 'editor', userId = 7:
//
// (
//   is_published IS TRUE
//   OR (
//     is_published IS FALSE
//     AND (author_id = :iqb0 OR assignee_id = :iqb1)
//   )
// )
```

---

## Example 3 — Reusing a CDOBind Across Conditions

**Requirement:**  
Find all records where a given user is involved in any capacity.

```php
$uid = new CDOBind('uid', $currentUserId);

$involved = Qb::or(
    Qb::eq('created_by',  $uid),
    Qb::eq('assigned_to', $uid),
    Qb::eq('reviewed_by', $uid),
    Qb::eq('closed_by',   $uid),
);

// SQL:
// created_by = :uid OR assigned_to = :uid
//   OR reviewed_by = :uid OR closed_by = :uid
//
// Bind list contains four CDOBind objects, all with name ':uid'.
// PDO binds :uid once; the value is shared across all occurrences.
```

---

## Example 4 — Row-Level Validity Window

**Requirement:**  
Find promotions that are currently active (today falls between `starts_at` and
`ends_at`) and belong to an allowed category.

```php
$today      = new CDOBind('today', date('Y-m-d'));
$categories = [1, 2, 3];

$condition = Qb::and(
    Qb::eq('is_enabled', true),
    Qb::betweenBy($today, 'starts_at', 'ends_at'),
    Qb::in('category_id', $categories),
);

// SQL:
// is_enabled IS TRUE
//   AND :today BETWEEN starts_at AND ends_at
//   AND category_id IN (:iqb0, :iqb1, :iqb2)
//
// Bind: :today => '2024-06-15', :iqb0 => 1, :iqb1 => 2, :iqb2 => 3
```

---

## Example 5 — Dynamic Filter Builder

**Requirement:**  
Build a filter from an arbitrary map of `[column => value]` pairs, where each
value may be a scalar, an array (→ `IN`), or `null` (→ `IS NULL`).

```php
function buildFromMap(array $filters): Qb
{
    $qb = Qb::empty();

    foreach ($filters as $column => $value) {
        if (is_array($value)) {
            $qb->addAnd(Qb::in($column, $value));
        } elseif ($value === null) {
            $qb->addAnd(Qb::isNull($column));
        } else {
            $qb->addAnd(Qb::eq($column, $value));
        }
    }

    return $qb;
}

buildFromMap([
    'status'   => 'active',
    'role'     => ['admin', 'editor'],
    'deleted_at' => null,
    'country'  => 'DE',
])

// status = :iqb0
//   AND role IN (:iqb1, :iqb2)
//   AND deleted_at IS NULL
//   AND country = :iqb3
```

---

## Example 6 — CASE Expression in a Condition

**Requirement:**  
Retrieve records where the computed tier (derived from `points`) is either
'Gold' or 'Platinum'.

```php
$tierExpr = Qb::case([
    'points >= 10000' => 'Platinum',
    'points >= 5000'  => 'Gold',
    'points >= 1000'  => 'Silver',
], else: 'Bronze');

// To use the CASE as a column expression inside IN(), extract the query string:
$condition = Qb::and(
    Qb::eq('is_active', true),
    // Embed CASE inline — note: CASE binds must be collected separately
    Qb::custom(
        $tierExpr->getQuery() . " IN ('Gold', 'Platinum')"
    ),
);

// The CASE binds are in $tierExpr->getBinds().
// Combine them with the $condition->getBinds() when executing.

// Full SQL fragment:
// is_active IS TRUE
//   AND CASE
//         WHEN points >= 10000 THEN :iqb0
//         WHEN points >= 5000  THEN :iqb1
//         WHEN points >= 1000  THEN :iqb2
//         ELSE :iqb3
//       END IN ('Gold', 'Platinum')
```

---

## Example 7 — Composing Sub-Queries from Separate Methods

**Requirement:**  
A search endpoint that filters users by status, age range, country, and
optional text search — each applied only when provided.

```php
class UserSearchQuery
{
    private array $conditions = [];

    public function status(string ...$statuses): static
    {
        $this->conditions[] = Qb::in('status', $statuses);
        return $this;
    }

    public function ageBetween(int $min, int $max): static
    {
        $this->conditions[] = Qb::between('age', $min, $max);
        return $this;
    }

    public function country(string $code): static
    {
        $this->conditions[] = Qb::eq('country_code', $code);
        return $this;
    }

    public function search(string $term): static
    {
        $p = '%' . $term . '%';
        $this->conditions[] = Qb::clip(Qb::or(
            Qb::like('first_name', $p),
            Qb::like('last_name',  $p),
            Qb::like('email',      $p),
        ));
        return $this;
    }

    public function build(): Qb
    {
        return Qb::and(...$this->conditions);
    }
}

$where = (new UserSearchQuery())
    ->status('active', 'trial')
    ->ageBetween(18, 45)
    ->country('DE')
    ->search('müller')
    ->build();

// status IN (:iqb0, :iqb1)
//   AND age BETWEEN :iqb2 AND :iqb3
//   AND country_code = :iqb4
//   AND (first_name LIKE :iqb5 OR last_name LIKE :iqb6 OR email LIKE :iqb7)
```
