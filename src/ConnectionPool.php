<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Cdo\Connection\CDO;
use Psr\Log\LoggerInterface;

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
     * On first call the class is instantiated, `setUp()` is invoked, and the
     * optional `$logger` (if provided) is attached via {@see DbConfigInterface::setLogger()}.
     * Subsequent calls return the cached instance; `$logger` is ignored after first access.
     *
     * @param string               $className Fully-qualified name of a class implementing {@see DbConfigInterface}.
     * @param LoggerInterface|null $logger    Optional PSR-3 logger to attach on first instantiation.
     * @return DbConfigInterface
     */
    public static function getConfigDb(string $className, ?LoggerInterface $logger = null): DbConfigInterface
    {
        $key = base64_encode($className);
        if (!array_key_exists($key, self::$dbConfig)) {
            /** @var DbConfigInterface $newDbConfig */
            $newDbConfig = new $className();
            $newDbConfig->setUp();
            if ($logger !== null) {
                $newDbConfig->setLogger($logger);
            }
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
