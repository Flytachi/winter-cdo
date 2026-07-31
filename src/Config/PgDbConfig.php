<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;

/**
 * PgDbConfig — PostgreSQL Configuration Base
 *
 * Extend this class to define a PostgreSQL connection.
 * Sensible defaults are provided; override only what differs in your
 * `setUp()` implementation.
 *
 * The `$schema` property is returned via {@see getSchema()} and is intended
 * for use by higher-level layers that set `search_path`.  CDO itself does not
 * automatically apply the schema.
 *
 * ```
 * class AppDb extends PgDbConfig
 * {
 *     public function setUp(): void
 *     {
 *         $this->host     = env('DB_HOST', 'localhost');
 *         $this->port     = (int) env('DB_PORT', 5432);
 *         $this->database = env('DB_NAME', 'postgres');
 *         $this->username = env('DB_USER', 'postgres');
 *         $this->password = env('DB_PASS', '');
 *         $this->schema   = 'app_schema';  // optional
 *         $this->charset  = 'UTF8';        // optional
 *     }
 * }
 *
 * $cdo = ConnectionPool::db(AppDb::class);
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config
 * @author  Flytachi
 */
abstract class PgDbConfig extends BaseDbConfig
{
    protected string $host = 'localhost';
    protected int $port = 5432;
    protected string $database = 'postgres';
    protected string $username = 'postgres';
    protected string $password = '';
    /** @var string Default schema name (returned by {@see getSchema()}). */
    protected string $schema = 'public';
    /** @var string|null Optional client encoding appended to the DSN (e.g. `'UTF8'`). */
    protected ?string $charset = null;
    /**
     * SSL mode appended to the DSN. Default `'disable'`: under Swoole the pgsql
     * socket is non-blocking (SWOOLE_HOOK_PDO_PGSQL) and libpq's SSL negotiation
     * races with the async connect on a cold/remote connection ("could not send SSL
     * negotiation packet: Resource temporarily unavailable"), so the first connect
     * fails. Override in `setUp()` (e.g. `'require'`, `'verify-full'`) for a database
     * that mandates TLS; set `''` to omit the key entirely (libpq default `prefer`).
     */
    protected string $sslmode = 'disable';

    public function getDns(): string
    {
        $dns = parent::getDns();
        if ($this->sslmode !== '') {
            $dns .= 'sslmode=' . $this->sslmode . ';';
        }
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
}
