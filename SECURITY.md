# Security Policy

## Supported Versions

Security fixes are provided for the latest minor release line. Please upgrade to
a supported version before reporting.

| Version | Supported |
|---------|:---------:|
| 4.0.x   | ✅ |
| 3.2.x   | ✅ |
| < 3.2   | ❌ |

The `3.2.x` line stays supported alongside `4.x`: the only breaking change in 4.0
is the batch-method rename, so a project that has not migrated should not be cut
off from security fixes over it.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Report suspected vulnerabilities privately to **jason.khan.x@gmail.com**, or via
GitHub's private ["Report a vulnerability"](https://github.com/flytachi/winter-cdo/security/advisories/new)
advisory form.

Include, where possible:

- affected version(s) and database driver (PostgreSQL / MySQL / MariaDB / SQLite);
- a minimal reproduction (config, code, and the SQL that is generated);
- the impact you believe it has.

You can expect an initial acknowledgement within a few days. Once confirmed, a
fix and a patched release will be prepared, and the reporter credited unless
anonymity is requested.

## Security Model

Understanding the trust boundary helps you use the library safely and report
issues accurately.

- **Values are always bound** as prepared-statement parameters — never
  interpolated into SQL. Passing user input as a *value* is safe.
- **Identifiers in CDO DML methods are quoted.** Table and column names passed to
  `insert`, `insertBatch`, `update`, `delete`, `upsert`, `upsertBatch` are quoted
  for the active driver, so they are safe even if built dynamically.
- **`Qb` column names are NOT sanitised.** The `$column` argument of every `Qb`
  method (`eq`, `gt`, `in`, `like`, `between`, …), the raw fragment of `Qb::raw()`,
  and the `WHEN` conditions of `Qb::case()` are injected **verbatim**. Never pass
  user input in the column position — that is a SQL-injection vector by design:

  ```php
  Qb::eq('status', $userInput)   // SAFE   — value is bound
  Qb::eq($userInput, 'active')   // UNSAFE — column name injected raw
  ```

A report that relies on passing untrusted input into a `Qb` column name, a
`Qb::raw()` fragment, or a `Qb::case()` condition is **working as documented**
and is not considered a vulnerability.
