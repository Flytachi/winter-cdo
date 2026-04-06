<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;
use Flytachi\Winter\Cdo\Config\Common\EntityCallDbTrait;

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
 * $cdo = AppDb::instance();
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config
 * @author  Flytachi
 */
abstract class PgDbConfig extends BaseDbConfig
{
    use EntityCallDbTrait;

    protected string $host = 'localhost';
    protected int $port = 5432;
    protected string $database = 'postgres';
    protected string $username = 'postgres';
    protected string $password = '';
    /** @var string Default schema name (returned by {@see getSchema()}). */
    protected string $schema = 'public';
    /** @var string|null Optional client encoding appended to the DSN (e.g. `'UTF8'`). */
    protected ?string $charset = null;

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
}
