<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Call;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;

/**
 * PgDbCall — Inline PostgreSQL Configuration
 *
 * A concrete PostgreSQL config that accepts all connection parameters in the
 * constructor.  All arguments are optional and fall back to PostgreSQL
 * defaults, so you only need to pass what differs.
 *
 * ```
 * $config = new PgDbCall(
 *     host:     '127.0.0.1',
 *     database: 'myapp',
 *     username: 'postgres',
 *     password: 'secret',
 *     schema:   'app_schema',   // optional
 *     charset:  'UTF8',         // optional
 * );
 *
 * $cdo = $config->connection();
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config\Call
 * @author  Flytachi
 */
final class PgDbCall extends BaseDbConfig
{
    /**
     * @param string      $host     Database host (default: `'localhost'`).
     * @param int         $port     Database port (default: `5432`).
     * @param string      $database Database name (default: `'postgres'`).
     * @param string      $username Authentication username (default: `'postgres'`).
     * @param string      $password Authentication password.
     * @param string      $schema   Default schema name returned by {@see getSchema()} (default: `'public'`).
     * @param string|null $charset  Optional client encoding appended to the DSN (e.g. `'UTF8'`).
     */
    public function __construct(
        public string $host = 'localhost',
        public int $port = 5432,
        public string $database = 'postgres',
        public string $username = 'postgres',
        public string $password = '',
        public string $schema = 'public',
        public ?string $charset = null
    ) {
    }

    public function getDns(): string
    {
        $dns = parent::getDns();
        if ($this->charset !== null) {
            $dns .= "options='--client_encoding=" . $this->charset . "';";
        }
        return $dns;
    }

    final public function getDriver(): string
    {
        return 'pgsql';
    }

    public function getSchema(): ?string
    {
        return $this->schema;
    }

    public function setUp(): void
    {
    }
}
