# Changelog

All notable changes to `flytachi/winter-cdo` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases prior to `3.2.0` are not itemised here — see the
[GitHub releases](https://github.com/flytachi/winter-cdo/releases) and git tags
for their history.

## [Unreleased]

## [4.0.0] - 2026-08-05

Batch writes no longer hold the whole job in memory, and the two batch methods
are renamed to say what they do.

### Changed — BREAKING

- **`CDO::insertGroup()` → `CDO::insertBatch()`**, and
  **`CDO::upsertGroup()` → `CDO::upsertBatch()`.** No alias is kept: two names
  for one method would outlive the migration. "Batch" is the word both canons
  use for grouped database execution — JDBC's `addBatch()`/`executeBatch()`,
  Spring's `batchUpdate()`, Yii's `batchInsert()` — and it matches what the
  `$chunkSize` parameter actually does.
- **`$entities` widened from `array` to `iterable`** in both methods. Arrays keep
  working unchanged; generators and any `Traversable` are now accepted too.
- **Rows are streamed instead of materialised.** Previously every row was
  converted and buffered before the first statement was sent, so peak memory grew
  with the size of the job. Rows are now buffered per column shape and each batch
  is issued as its buffer fills. Measured on 200 000 rows: **1.5 MiB** peak versus
  **147 MiB** before — the array conversion alone costs ~4.4× the objects it
  converts, and it used to happen for all rows up front.
- **A failure now leaves earlier batches committed.** The eager version validated
  every row before writing anything, so a malformed row wrote nothing at all.
  Batches are sent as they fill, so that guarantee is gone; wrap the call in
  `transaction()` when the whole job must be all-or-nothing. (Partial writes were
  always possible when the *server* rejected a later chunk — this extends the same
  behaviour to row validation.)
- **`upsertBatch()` validates its configuration before its data.** An empty
  `conflictColumns` now throws even when the collection turns out to be empty;
  previously the empty-collection check ran first and hid the mistake.
- `insertBatch()` no longer returns early on empty input — an `iterable` cannot be
  inspected without consuming it. It still returns `0`, just by completing the pass.

### Added

- **`$updateColumns` now rejects a plain list.** `['qty', 'created_at']` is the
  shape Laravel's `upsert()` takes for the same argument, and it used to reach the
  database as `SET 0 = qty`, surfacing as `no such column: 0` — a message pointing
  at the schema rather than the call. It now throws a `CDOException` naming the
  column and showing the corrected call, and mentioning `:current`. The list is
  *not* accepted as shorthand: it would only ever cover the trivial `:new` case,
  so the map has to be learned at the first real expression anyway, leaving two
  shapes to carry forever for no gain.
- `chunkSize` below 1 is documented as one statement per row (it always behaved
  that way; a clamp that changed nothing was removed).

### Removed

- `groupRowsBySignature()` (private) — replaced by `normalizeRow()`, which does
  the same work one row at a time.

### Migration

```php
// before
$cdo->insertGroup('users', $rows);
$cdo->upsertGroup('inventory', $rows, ['sku'], ['qty' => ':new']);

// after
$cdo->insertBatch('users', $rows);
$cdo->upsertBatch('inventory', $rows, ['sku'], ['qty' => ':new']);
```

Nothing else changes for an array caller. To take the memory win, hand the method
a generator instead of an array.

## [3.2.0] - 2026-07-16

### Added

- **SQLite support** across the full DML surface (`insert`, `insertGroup`,
  `update`, `delete`, `upsert`, `upsertGroup`). SQLite uses PostgreSQL-style
  `ON CONFLICT (...) DO NOTHING | DO UPDATE SET ... EXCLUDED.col` for upserts.
- New configuration classes `Config\SqliteDbConfig` (application-level) and
  `Config\Call\SqliteDbCall` (inline). SQLite is addressed by a file path or
  `:memory:` and requires no credentials.
- `CDO::insertGroup()` now returns the total number of inserted rows.
- End-to-end SQLite test suite running against an in-memory database, plus unit
  tests for row grouping and the SQLite configuration.

### Changed

- **`CDO::insertGroup()` signature changed from `: void` to `: int`.** Callers
  that ignored the return value are unaffected. A plain `INSERT` reports this
  row count identically on PostgreSQL, MySQL and MariaDB.
- **Persistent connections are now actually applied.** Previously `ATTR_PERSISTENT`
  was set via `setAttribute()` after connecting, which PDO ignores (a silent
  no-op); it is now passed in the constructor's driver-options array. If you set
  `$isPersistent = true`, the connection is now genuinely persistent — review
  this if any code implicitly relied on the previous no-op behaviour.
- Batch `insertGroup()` / `upsertGroup()` now group rows by their column
  signature before building statements (Hibernate `@DynamicInsert` style). As a
  result, rows within a chunk may be re-ordered by group; do not rely on
  auto-increment ids following the input array order when rows differ in shape.
- `upsertGroup()` remains `: void` by design — an upsert's affected-row count is
  not comparable across drivers, so no unstable value is returned.

### Fixed

- Batch `insertGroup()` / `upsertGroup()` produced a column/value count mismatch
  (invalid SQL on every driver) when rows in a chunk had different sets of
  non-null columns. Rows are now grouped by shape, so each statement is valid.
- `ATTR_TIMEOUT` is likewise applied at construction time instead of via an
  ineffective post-connect `setAttribute()`.
- Inserting a row whose columns are all `NULL` now raises a clear `CDOException`
  before hitting the database, instead of behaving differently per driver
  (silent insert on MySQL vs. a syntax error on PostgreSQL).
- `CDO::transaction()` no longer lets a failing `rollback()` mask the original
  callback exception: the rollback is guarded by `inTransaction()`, wrapped so
  its own failure is logged instead of thrown, and the original error is always
  the one propagated. This matters when a transaction was ended out-of-band
  (e.g. a DDL statement causing an implicit commit on MySQL/MariaDB/Oracle).

[Unreleased]: https://github.com/flytachi/winter-cdo/compare/v4.0.0...HEAD
[4.0.0]: https://github.com/flytachi/winter-cdo/releases/tag/v4.0.0
[3.2.0]: https://github.com/flytachi/winter-cdo/releases/tag/v3.2.0
