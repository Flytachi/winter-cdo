<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Call;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;

/**
 * DbCall — Inline Generic Database Configuration
 *
 * A concrete, driver-agnostic config that accepts all connection parameters
 * directly in the constructor.  Use this when you need a one-off connection
 * without defining a dedicated config class.
 *
 * ```
 * $config = new DbCall(
 *     driver:   'mysql',
 *     host:     '127.0.0.1',
 *     port:     3306,
 *     database: 'myapp',
 *     username: 'root',
 *     password: 'secret',
 * );
 *
 * $cdo = $config->connection();
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config\Call
 * @author  Flytachi
 */
final class DbCall extends BaseDbConfig
{
    /**
     * @param string $driver   PDO driver name (e.g. `'mysql'`, `'pgsql'`, `'oci'`).
     * @param string $host     Database host.
     * @param int    $port     Database port.
     * @param string $database Database name.
     * @param string $username Authentication username.
     * @param string $password Authentication password.
     */
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password
    ) {
    }

    final public function setUp(): void
    {
    }

    final public function getDriver(): string
    {
        return $this->driver;
    }
}
