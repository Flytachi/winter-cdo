<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Cdo\Connection\CDO;

/**
 * ConnectionPool — Config Registry and CDO Factory
 *
 * A process-level singleton registry that maps config class names to their
 * initialised {@see DbConfigInterface} instances.
 *
 * Each config class is instantiated **at most once** per process: on first
 * access the class is constructed, `setUp()` is called, and the result is
 * cached by a base-64 key derived from the class name.  Subsequent calls to
 * {@see db()} or {@see getConfigDb()} return the cached instance.
 *
 * **Typical usage** — call `db()` to obtain a ready-to-use CDO connection:
 *
 * ```
 * $cdo = ConnectionPool::db(MyDatabaseConfig::class);
 * $cdo->insert('users', $data);
 * ```
 *
 * @package Flytachi\Winter\Cdo
 * @author  Flytachi
 */
final class ConnectionPool
{
    /** @var array<string, DbConfigInterface> Cached config instances keyed by base64(className). */
    private static array $dbConfig = [];

    /**
     * Returns the initialised config instance for the given class.
     *
     * On first call the class is instantiated and `setUp()` is invoked.
     * Subsequent calls return the cached instance without re-initialisation.
     *
     * @param string $className Fully-qualified name of a class implementing {@see DbConfigInterface}.
     * @return DbConfigInterface
     */
    public static function getConfigDb(string $className): DbConfigInterface
    {
        $key = base64_encode($className);
        if (!array_key_exists($key, self::$dbConfig)) {
            /** @var DbConfigInterface $newDbConfig */
            $newDbConfig = new $className();
            $newDbConfig->setUp();
            self::$dbConfig[$key] = $newDbConfig;
        }
        return self::$dbConfig[$key];
    }

    /**
     * Returns an active CDO connection for the given config class.
     *
     * Resolves the config via {@see getConfigDb()} and calls
     * `DbConfigInterface::connection()`, which lazily opens the PDO
     * connection on first call and reuses it thereafter.
     *
     * @param string $className Fully-qualified name of a {@see DbConfigInterface} implementation.
     * @return CDO
     */
    final public static function db(string $className): CDO
    {
        $config = self::getConfigDb($className);
        return $config->connection();
    }

    /**
     * Returns all currently registered config instances.
     *
     * Useful for health checks, diagnostics, or iterating all known
     * database connections.
     *
     * @return DbConfigInterface[]
     */
    public static function showDbConfigs(): array
    {
        return self::$dbConfig;
    }
}
