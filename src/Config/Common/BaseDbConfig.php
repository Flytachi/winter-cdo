<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Common;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOException;

/**
 * BaseDbConfig — Abstract Database Configuration Base
 *
 * Provides the full connection lifecycle implementation for all database config
 * classes.  Subclasses must implement {@see DbConfigInterface::setUp()} (for
 * credential loading) and {@see DbConfigInterface::getDriver()} (to return the
 * PDO driver string).
 *
 * Connection is managed lazily — no socket is opened until {@see connection()}
 * or {@see connect()} is first called.  The `CDO` instance is stored internally
 * and reused for the lifetime of this config object.
 *
 * **Persistence** is disabled by default (`$isPersistent = false`).  Set the
 * property to `true` in your subclass to enable PDO persistent connections.
 *
 * @package Flytachi\Winter\Cdo\Config\Common
 * @author  Flytachi
 */
abstract class BaseDbConfig implements DbConfigInterface
{
    /** @var CDO|null Active connection, or null if not yet opened. */
    private ?CDO $cdo = null;

    /** @var bool Whether to use PDO persistent connections. */
    protected bool $isPersistent = false;

    public function getDns(): string
    {
        return $this->getDriver()
            . ':host=' . $this->host
            . ';port=' . $this->port
            . ';dbname=' . $this->database
            . ';';
    }

    final public function getUsername(): string
    {
        return $this->username;
    }

    final public function getPassword(): string
    {
        return $this->password;
    }

    final public function getPersistentStatus(): bool
    {
        return $this->isPersistent;
    }

    /**
     * Opens the CDO connection if it is not already open.
     *
     * Debug mode is controlled by the `DEBUG` environment variable —
     * when truthy, `PDO::ERRMODE_EXCEPTION` is activated.
     *
     * @param int $timeout Connection timeout in seconds (passed to {@see CDO}).
     * @throws CDOException If the connection attempt fails.
     */
    final public function connect(int $timeout = 3): void
    {
        if (is_null($this->cdo)) {
            $this->cdo = new CDO($this, $timeout, (bool) env('DEBUG', false));
        }
    }

    /**
     * Releases the CDO connection by setting the internal reference to null.
     *
     * The underlying PDO connection is closed when all references to the CDO
     * object are released (PHP garbage collection).
     */
    final public function disconnect(): void
    {
        $this->cdo = null;
    }

    /**
     * Closes and re-opens the connection.
     *
     * Useful after detecting a stale or lost connection
     * (e.g. after a long idle period or a server-side timeout).
     *
     * @throws CDOException If the reconnection attempt fails.
     */
    final public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * Returns the active CDO connection, opening it lazily if needed.
     *
     * @return CDO
     * @throws CDOException If the connection cannot be established.
     */
    final public function connection(): CDO
    {
        $this->connect();
        return $this->cdo;
    }

    /**
     * Tests reachability with a simple `SELECT 1` query.
     *
     * @return bool `true` if the database responded, `false` on any error.
     */
    final public function ping(): bool
    {
        try {
            $this->connect();
            $stmt = $this->cdo->query("SELECT 1");
        } catch (CDOException $e) {
            $stmt = false;
        } finally {
            return $stmt !== false;
        }
    }

    /**
     * Tests reachability and measures round-trip latency.
     *
     * @return array{status: bool, latency: float|null, error: string|null}
     */
    final public function pingDetail(): array
    {
        $start = microtime(true);
        $status = true;
        $error = null;

        try {
            $this->connect();
            $stmt = $this->cdo->query("SELECT 1");

            if ($stmt === false) {
                $status = false;
            }
        } catch (CDOException $e) {
            $status = false;
            $error = $e->getMessage();
        }

        $latency = (microtime(true) - $start) * 1000;

        return [
            'status' => $status,
            'latency' => round($latency, 2),
            'error' => $error,
        ];
    }

    public function getSchema(): ?string
    {
        return null;
    }
}
