# winter-cdo — internal reference

Technical documentation for the library itself: exact method contracts, the SQL each Qb
operator emits, and the reasoning behind decisions that are not obvious from the code.

This is **not** the getting-started guide — that is the [root README](../README.md) and the
[user documentation](https://winterframe.net/packages/cdo). These pages assume you already
have a connection and now need to know precisely what a call does, or why it does it that
way. Written for the people who maintain the library, debug a query that came out wrong, or
build on top of it.

---

## Map

| # | Page | Go here when |
|---|------|--------------|
| 00 | [Overview](00-overview.md) | You want the architecture in one picture — how config, pool, CDO and Qb fit together |
| 01 | [Configuration](01-configuration.md) | Declaring a database: config classes, inline `Call` variants, driver-specific options |
| 02 | [ConnectionPool](02-connection-pool.md) | Getting a connection, config caching, health checks |
| 03 | [CDO](03-cdo.md) | The DML surface: `insert`, `update`, `delete`, `upsert`, batches, transactions |
| 04 | [CDOStatement](04-cdo-statement.md) | How a value reaches PDO — type selection, objects, `NULL` |
| 05 | [Exceptions](05-exceptions.md) | What is thrown, what it carries, which SQLSTATE means what |
| 06 | [CDOBind](06-cdobind.md) | Reusing one placeholder across several conditions |

**Qb — the query builder**

| # | Page | Operators |
|---|------|-----------|
| 07 | [Comparison](07-comparison-operators.md) | `eq` `neq` `gt` `gte` `lt` `lte` `nsEq` |
| 08 | [NULL checks](08-null-checks.md) | `isNull` `isNotNull` |
| 09 | [Sets](09-set-operators.md) | `in` `notIn` — including the empty-array case |
| 10 | [Pattern matching](10-pattern-matching.md) | `like` `notLike` |
| 11 | [Ranges](11-range-operators.md) | `between` `notBetween` `betweenBy` `notBetweenBy` |
| 12 | [Logical](12-logical-operators.md) | `and` `or` `xor` `clip` — and precedence |
| 13 | [Mutable](13-mutable-methods.md) | `addAnd` `addOr` `addXor` |
| 14 | [CASE](14-case-expression.md) | `case` |
| 15 | [Special](15-special.md) | `raw` `empty` |
| 16 | [Advanced examples](16-advanced-examples.md) | Whole conditions assembled from the above |

---

## Routes through it

- **"How do I connect?"** → [01](01-configuration.md) for the config class,
  [02](02-connection-pool.md) for how it becomes a `CDO`.
- **"My query has the wrong parentheses"** → [12](12-logical-operators.md): a group of more
  than one part is wrapped, and `clip` is how you force your own grouping.
- **"`in` with an empty array"** → [09](09-set-operators.md). It does not silently match
  everything; the page says what it does instead.
- **"Batch insert eats memory"** → [03](03-cdo.md): memory follows `$chunkSize`, not the size
  of the job — feed a generator.
- **"A batch failed halfway"** → [03](03-cdo.md): batches are sent as they fill, so wrap the
  call in `transaction()` when you need all-or-nothing.
- **"What comes out for a `DateTimeInterface` / an enum / an object?"** →
  [04](04-cdo-statement.md).
- **"Which driver am I on and does it matter?"** → [03](03-cdo.md) for identifier quoting and
  the upsert dialect per driver.

---

## Invariants these pages rely on

Everything else is detail; break one of these and the rest stops being true.

1. **Every value goes through a bound parameter.** SQL text and data never meet by string
   concatenation — the only deliberate exception is `raw()`, which is why it is documented as
   the one place the caller owns the safety.
2. **A group of more than one condition is parenthesised.** Without it an `OR` inside a group
   would break the surrounding `AND`, which is a permission bypass, not a formatting bug.
3. **`Qb` returns fragments, never executes.** It hands back SQL plus its bind list; execution
   belongs to `CDO`.
4. **A config is instantiated once per class and cached by `ConnectionPool`**; the connection
   underneath it is opened lazily on first use.
5. **Batch methods stream.** Memory is bounded by the chunk size, and each chunk is a separate
   statement execution — so partial success is possible outside a transaction.

---

## Keeping it honest

Every code sample here is meant to run as written against the current `src/`. When you change
behaviour, the page that describes it is part of the change — a sample that no longer executes
is worse than no sample, because it is trusted. Claims about generated SQL belong in a test,
and the page should say which one.
