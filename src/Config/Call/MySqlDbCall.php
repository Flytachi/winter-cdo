<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Call;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;

/**
 * MySqlDbCall — Inline MySQL / MariaDB Configuration
 *
 * A concrete MySQL/MariaDB config that accepts all connection parameters in
 * the constructor.  All arguments are optional and fall back to sane defaults,
 * so you only need to pass what differs from the defaults.
 *
 * ```
 * $config = new MySqlDbCall(
 *     host:     '127.0.0.1',
 *     database: 'myapp',
 *     username: 'root',
 *     password: 'secret',
 *     charset:  'utf8mb4',   // optional
 * );
 *
 * $cdo = $config->connection();
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config\Call
 * @author  Flytachi
 */
final class MySqlDbCall extends BaseDbConfig
{
    /**
     * @param string      $host     Database host (default: `'localhost'`).
     * @param int         $port     Database port (default: `3306`).
     * @param string      $database Database name.
     * @param string      $username Authentication username (default: `'root'`).
     * @param string      $password Authentication password.
     * @param string|null $charset  Optional charset appended to the DSN (e.g. `'utf8mb4'`).
     */
    public function __construct(
        public string $host = 'localhost',
        public int $port = 3306,
        public string $database = '',
        public string $username = 'root',
        public string $password = '',
        public ?string $charset = null,
    ) {
    }

    public function getDns(): string
    {
        $dns = parent::getDns();
        if ($this->charset !== null) {
            $dns .= 'charset=' . $this->charset . ';';
        }
        return $dns;
    }

    final public function getDriver(): string
    {
        return 'mysql';
    }

    public function setUp(): void
    {
    }
}
