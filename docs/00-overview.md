# Winter CDO — Documentation Overview

**CDO** (Connection Data Object) is an extended PDO wrapper that provides:

- **Safe DML operations** — `insert`, `update`, `delete`, `upsert` and their
  batch variants, all using parameterised statements
- **Type-aware binding** — automatic `PDO::PARAM_*` selection based on PHP type
- **Query Builder (`Qb`)** — a composable, injection-safe condition builder for
  `WHERE` clauses
- **Connection management** — lazy connection with caching via `ConnectionPool`

**Full web documentation:** https://winterframe.net/docs/cdo

---

## How the pieces fit together

```
DbConfigInterface
    └── BaseDbConfig (abstract)
            ├── MySqlDbConfig ──┐
            ├── PgDbConfig     ├── extend to define your DB config
            └── DbConfig       ┘
            └── Call variants (MySqlDbCall, PgDbCall, DbCall) — inline config

ConnectionPool
    └── caches config instances, returns CDO on demand

CDO (extends PDO)
    ├── insert / insertGroup
    ├── upsert / upsertGroup
    ├── update
    └── delete
            └── uses CDOStatement (type-aware binding)
                        └── uses CDOBind (name + value pair)

Qb (Query Builder)
    └── builds parameterised SQL fragments
        ├── CDOBind — named placeholder container
        ├── Comparison: eq, neq, gt, gte, lt, lte, nsEq
        ├── NULL:       isNull, isNotNull
        ├── Set:        in, notIn
        ├── Pattern:    like, notLike
        ├── Range:      between, notBetween, betweenBy, notBetweenBy
        ├── Logical:    and, or, xor, clip
        ├── Mutable:    addAnd, addOr, addXor
        ├── CASE:       case
        └── Special:    custom, empty
```

---

## Documentation Index

### Connection

| # | File | Contents |
|---|------|----------|
| 01 | [01-configuration.md](01-configuration.md) | Config classes (`MySqlDbConfig`, `PgDbConfig`, `DbConfig`) and inline Call classes |
| 02 | [02-connection-pool.md](02-connection-pool.md) | `ConnectionPool` — config registry, CDO factory, health checks |
| 03 | [03-cdo.md](03-cdo.md) | `CDO` — all DML methods: insert, update, delete, upsert, batch |
| 04 | [04-cdo-statement.md](04-cdo-statement.md) | `CDOStatement` — type-aware binding, object serialisation |
| 05 | [05-exceptions.md](05-exceptions.md) | `CDOException` — error handling, SQLSTATE reference |

### Qb — Query Builder

| # | File | Contents |
|---|------|----------|
| 06 | [06-cdobind.md](06-cdobind.md) | `CDOBind` — named parameter container, reuse across conditions |
| 07 | [07-comparison-operators.md](07-comparison-operators.md) | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `nsEq` |
| 08 | [08-null-checks.md](08-null-checks.md) | `isNull`, `isNotNull` |
| 09 | [09-set-operators.md](09-set-operators.md) | `in`, `notIn` — including empty-array behaviour |
| 10 | [10-pattern-matching.md](10-pattern-matching.md) | `like`, `notLike` — wildcards, DB compatibility |
| 11 | [11-range-operators.md](11-range-operators.md) | `between`, `notBetween`, `betweenBy`, `notBetweenBy` |
| 12 | [12-logical-operators.md](12-logical-operators.md) | `and`, `or`, `xor`, `clip` — operator precedence |
| 13 | [13-mutable-methods.md](13-mutable-methods.md) | `addAnd`, `addOr`, `addXor` — incremental condition building |
| 14 | [14-case-expression.md](14-case-expression.md) | `case` — CASE WHEN … THEN … END |
| 15 | [15-special.md](15-special.md) | `custom` (raw SQL), `empty` (no-op) |
| 16 | [16-advanced-examples.md](16-advanced-examples.md) | Real-world combinations: e-commerce, RBAC, dynamic filters |
