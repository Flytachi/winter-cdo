<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Common;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\ConnectionPool;

/**
 * EntityCallDbTrait — Static CDO Access Shortcut
 *
 * Mixed into config classes (`DbConfig`, `MySqlDbConfig`, `PgDbConfig`, etc.)
 * to provide a convenient `::instance()` class method that retrieves the CDO
 * connection for the calling class directly from {@see ConnectionPool}.
 *
 * ```
 * class MyDb extends PgDbConfig
 * {
 *     public function setUp(): void
 *     {
 *         $this->host     = 'db.example.com';
 *         $this->database = 'myapp';
 *         $this->username = 'app';
 *         $this->password = 'secret';
 *     }
 * }
 *
 * // Equivalent to ConnectionPool::db(MyDb::class):
 * $cdo = MyDb::instance();
 * $cdo->insert('users', $data);
 * ```
 *
 * @package Flytachi\Winter\Cdo\Config\Common
 */
trait EntityCallDbTrait
{
    /**
     * Returns the CDO connection associated with the calling config class.
     *
     * Delegates to `ConnectionPool::db(static::class)`, which lazily
     * initialises and caches the config before returning the connection.
     *
     * @return CDO
     */
    final public static function instance(): CDO
    {
        return ConnectionPool::db(static::class);
    }
}
