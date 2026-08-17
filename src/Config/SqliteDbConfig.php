<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;

/**
 * SqliteDbConfig — SQLite Configuration Base
 *
 * Extend this class to define a SQLite connection. Unlike the client/server
 * drivers, SQLite is addressed by a file path (or the special `:memory:`
 * database), so the generic host/port/dbname DSN does not apply — this class
 * builds a `sqlite:<path>` DSN instead.
 *
 * SQLite has no authentication, so username/password are unused (kept empty to
 * satisfy the base contract). Upserts use PostgreSQL-style `ON CONFLICT`
 * syntax, which CDO applies automatically for this driver.
 *
 * ```
 * class AppDb extends SqliteDbConfig
 * {
 *     public function setUp(): void
 *     {
 *         $this->path = env('DB_PATH', __DIR__ . '/app.sqlite');
 *         // or ':memory:' for an ephemeral in-memory database
 *     }
 * }
 *
 * $cdo = ConnectionPool::db(AppDb::class);
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config
 * @author  Flytachi
 *
 * @link https://winterframe.net/packages/cdo/configuration SQLite config, file or in-memory
 */
abstract class SqliteDbConfig extends BaseDbConfig
{
    /** @var string Filesystem path to the database file, or ':memory:'. */
    protected string $path = ':memory:';
    /** @var string Unused by SQLite; present only to satisfy the base contract. */
    protected string $username = '';
    /** @var string Unused by SQLite; present only to satisfy the base contract. */
    protected string $password = '';

    public function getDns(): string
    {
        return 'sqlite:' . $this->path;
    }

    final public function getDriver(): string
    {
        return 'sqlite';
    }
}
