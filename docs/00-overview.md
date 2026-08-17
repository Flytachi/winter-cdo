# Winter CDO — Documentation Overview

**CDO** (Connection Data Object) is an extended PDO wrapper that provides:

- **Safe DML operations** — `insert`, `update`, `delete`, `upsert` and their
  batch variants, all using parameterised statements
- **Type-aware binding** — automatic `PDO::PARAM_*` selection based on PHP type
- **Query Builder (`Qb`)** — a composable, injection-safe condition builder for
  `WHERE` clauses
- **Connection management** — lazy connection with caching via `ConnectionPool`

**Full web documentation:** https://winterframe.net/packages/cdo

---

## How the pieces fit together

```
DbConfigInterface
    └── BaseDbConfig (abstract)
            ├── MySqlDbConfig  ──┐
            ├── PgDbConfig       ├── extend to define your DB config
            ├── SqliteDbConfig   │
            └── DbConfig        ─┘
            └── Call variants (MySqlDbCall, PgDbCall, SqliteDbCall, DbCall) — inline config

ConnectionPool
    └── caches config instances, returns CDO on demand

CDO (extends PDO)
    ├── insert / insertBatch
    ├── upsert / upsertBatch
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
        └── Special:    raw, empty
```

---

## Where to go next

The page map, with a route for each common question, lives in
[README.md](README.md) — kept in one place so the two cannot drift apart.
