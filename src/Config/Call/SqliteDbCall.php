<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Call;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;

/**
 * SqliteDbCall — Inline SQLite Configuration
 *
 * A concrete SQLite config that accepts the database path in the constructor.
 * Pass a filesystem path, or the default `:memory:` for an ephemeral in-memory
 * database. SQLite has no authentication, so no credentials are required.
 *
 * ```
 * // File-backed:
 * $config = new SqliteDbCall(path: __DIR__ . '/app.sqlite');
 *
 * // In-memory (default):
 * $config = new SqliteDbCall();
 *
 * $cdo = $config->connection();
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config\Call
 * @author  Flytachi
 */
final class SqliteDbCall extends BaseDbConfig
{
    /** @var string Unused by SQLite; present only to satisfy the base contract. */
    protected string $username = '';
    /** @var string Unused by SQLite; present only to satisfy the base contract. */
    protected string $password = '';

    /**
     * @param string $path Filesystem path to the database file, or ':memory:'.
     */
    public function __construct(
        public string $path = ':memory:',
    ) {
        parent::__construct();
    }

    public function getDns(): string
    {
        return 'sqlite:' . $this->path;
    }

    final public function getDriver(): string
    {
        return 'sqlite';
    }

    public function setUp(): void
    {
    }
}
