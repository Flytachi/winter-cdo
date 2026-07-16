# Changelog

All notable changes to `flytachi/winter-cdo` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases prior to `3.2.0` are not itemised here — see the
[GitHub releases](https://github.com/flytachi/winter-cdo/releases) and git tags
for their history.

## [Unreleased]

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

[Unreleased]: https://github.com/flytachi/winter-cdo/compare/v3.2.0...HEAD
[3.2.0]: https://github.com/flytachi/winter-cdo/releases/tag/v3.2.0
