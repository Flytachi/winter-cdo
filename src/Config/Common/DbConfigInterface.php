<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Common;

use Flytachi\Winter\Cdo\Connection\CDO;

/**
 * DbConfigInterface — Database Configuration Contract
 *
 * Defines the full lifecycle of a database configuration: initialisation,
 * connection management, health checks, and credential access.
 *
 * Implement this interface (or extend {@see BaseDbConfig}) to define a
 * database connection.  The implementation is then registered with
 * {@see ConnectionPool} to obtain a shared {@see CDO} instance.
 *
 * @package Flytachi\Winter\Cdo\Config\Common
 */
interface DbConfigInterface
{
    /**
     * Initialise the config (load credentials, env vars, service discovery, etc.).
     *
     * Called once by {@see ConnectionPool::getConfigDb()} immediately after
     * the object is instantiated.  Put any bootstrap logic here instead of
     * in the constructor.
     */
    public function setUp(): void;

    /**
     * Returns the PDO DSN string for this connection.
     *
     * Example: `"pgsql:host=127.0.0.1;port=5432;dbname=mydb;"`
     *
     * @return string
     */
    public function getDns(): string;

    /**
     * Whether PDO persistent connections are enabled for this config.
     *
     * When `true`, PDO reuses an existing connection from the connection
     * pool rather than opening a new socket each time.
     *
     * @return bool
     */
    public function getPersistentStatus(): bool;

    /**
     * Returns the PDO driver name (e.g. `'pgsql'`, `'mysql'`, `'oci'`).
     *
     * @return string
     */
    public function getDriver(): string;

    /**
     * Returns the database username.
     *
     * @return string
     */
    public function getUsername(): string;

    /**
     * Returns the database password.
     *
     * @return string
     */
    public function getPassword(): string;

    /**
     * Opens the database connection (lazy — no-op if already connected).
     *
     * @param int $timeout Connection timeout in seconds.
     */
    public function connect(int $timeout = 3): void;

    /**
     * Closes the database connection by releasing the internal CDO reference.
     */
    public function disconnect(): void;

    /**
     * Closes and re-opens the connection.
     *
     * Useful after detecting a stale or broken connection.
     */
    public function reconnect(): void;

    /**
     * Returns the active CDO connection, opening it if necessary.
     *
     * @return CDO
     */
    public function connection(): CDO;

    /**
     * Tests whether the database is reachable.
     *
     * Executes `SELECT 1` and returns `true` on success, `false` on any error.
     *
     * @return bool
     */
    public function ping(): bool;

    /**
     * Tests reachability and returns latency details.
     *
     * Returns an associative array:
     * ```
     * [
     *     'status'  => bool,         // true = reachable
     *     'latency' => float|null,   // round-trip time in milliseconds
     *     'error'   => string|null,  // error message if status is false
     * ]
     * ```
     *
     * @return array{status: bool, latency: float|null, error: string|null}
     */
    public function pingDetail(): array;

    /**
     * Returns the default schema name for this connection, or `null` if not applicable.
     *
     * Used by PostgreSQL configs (`PgDbConfig`, `PgDbCall`) to indicate which
     * schema `search_path` should target.
     *
     * @return string|null
     */
    public function getSchema(): ?string;
}
