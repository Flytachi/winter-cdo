<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;
use Flytachi\Winter\Cdo\Config\Common\EntityCallDbTrait;

/**
 * DbConfig — Generic Driver-Agnostic Configuration Base
 *
 * Extend this class when you need to specify the PDO driver explicitly
 * (e.g. `$this->driver = 'mysql'`).  For driver-specific defaults and extras
 * use {@see MySqlDbConfig} or {@see PgDbConfig} instead.
 *
 * All connection properties must be assigned in your `setUp()` implementation:
 *
 * ```
 * class AppDb extends DbConfig
 * {
 *     public function setUp(): void
 *     {
 *         $this->driver   = 'mysql';
 *         $this->host     = env('DB_HOST', 'localhost');
 *         $this->port     = (int) env('DB_PORT', 3306);
 *         $this->database = env('DB_NAME', 'app');
 *         $this->username = env('DB_USER', 'root');
 *         $this->password = env('DB_PASS', '');
 *     }
 * }
 *
 * // Retrieve CDO:
 * $cdo = AppDb::instance();
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config
 * @author  Flytachi
 */
abstract class DbConfig extends BaseDbConfig
{
    use EntityCallDbTrait;

    protected string $driver;
    protected string $host;
    protected int $port;
    protected string $database;
    protected string $username;
    protected string $password;

    final public function getDriver(): string
    {
        return $this->driver;
    }
}
