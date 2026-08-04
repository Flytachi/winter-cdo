# Contributing to Winter CDO

Thanks for considering a contribution! This guide covers the local workflow and
the checks a pull request must pass.

## Requirements

- PHP >= 8.3 with `ext-pdo`
- [Composer](https://getcomposer.org/)
- `pdo_sqlite` (bundled with most PHP builds) — the test suite runs an in-memory
  SQLite database, so no external server is needed to run the tests.

## Getting started

```bash
git clone https://github.com/flytachi/winter-cdo.git
cd winter-cdo
composer install
```

## Running the checks

```bash
composer test         # run the PHPUnit suite
composer test-detail  # run with testdox (human-readable test names)
composer cs-check     # check coding standard (PSR-12 via PHP_CodeSniffer)
composer cs-fix       # auto-fix fixable coding-standard violations
```

A pull request should have **all tests green** and **no `cs-check` violations**
in `src/`.

## Coding standard

- Source follows **PSR-12** (enforced by `phpcs.xml`; `tests/` and `vendor/` are
  excluded from the style check).
- Use `declare(strict_types=1);` in every PHP file.
- Public methods are documented with PHPDoc, including `@param`, `@return` and
  `@throws`.
- **The package targets PHP 8.3, and that applies to `tests/` too.** Syntax
  introduced later will parse locally on a newer PHP and fail for everyone on the
  minimum version — `new Foo()->bar()` (8.4) is the easy one to slip in; write
  `(new Foo())->bar()`. The style check does not catch this, only running the suite
  on 8.3 does.

## Tests

- Add or update tests for any behavioural change. Unit tests live in
  `tests/Unit/`.
- Prefer fast, self-contained tests: bind against mocks (`createMock(...)`) or the
  in-memory SQLite database (`new SqliteDbCall()`) rather than a live server.
- When touching driver-specific SQL generation (PostgreSQL / MySQL / MariaDB /
  SQLite), state clearly in the PR which drivers you verified and how.

## Pull requests

- Branch from `main` and keep each PR focused on a single concern.
- Describe the change and its motivation; link any related issue.
- Update `README.md`, the `docs/` pages, and `CHANGELOG.md` (under an
  `[Unreleased]` heading) when your change is user-facing.

## Reporting bugs & security issues

- Functional bugs: open a [GitHub issue](https://github.com/flytachi/winter-cdo/issues).
- Security vulnerabilities: **do not** open a public issue — follow
  [SECURITY.md](SECURITY.md).

By contributing, you agree that your contributions are licensed under the
project's [MIT License](LICENSE).
