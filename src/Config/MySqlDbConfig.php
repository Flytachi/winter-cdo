<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;

/**
 * MySqlDbConfig — MySQL / MariaDB Configuration Base
 *
 * Extend this class to define a MySQL or MariaDB connection.
 * Sensible defaults are provided; override only what differs in your
 * `setUp()` implementation.
 *
 * ```
 * class AppDb extends MySqlDbConfig
 * {
 *     public function setUp(): void
 *     {
 *         $this->host     = env('DB_HOST', 'localhost');
 *         $this->port     = (int) env('DB_PORT', 3306);
 *         $this->database = env('DB_NAME', 'app');
 *         $this->username = env('DB_USER', 'root');
 *         $this->password = env('DB_PASS', '');
 *         $this->charset  = 'utf8mb4';     // optional
 *     }
 * }
 *
 * $cdo = ConnectionPool::db(AppDb::class);
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config
 * @author  Flytachi
 */
abstract class MySqlDbConfig extends BaseDbConfig
{
    protected string $host = 'localhost';
    protected int $port = 3306;
    protected string $database = '';
    protected string $username = 'root';
    protected string $password = '';
    /** @var string|null Optional charset appended to the DSN (e.g. `'utf8mb4'`). */
    protected ?string $charset = null;

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
}
