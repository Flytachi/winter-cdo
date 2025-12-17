<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Common;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOException;

abstract class BaseDbConfig implements DbConfigInterface
{
    private ?CDO $cdo = null;
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
     * @throws CDOException
     */
    final public function connect(int $timeout = 3): void
    {
        if (is_null($this->cdo)) {
            $this->cdo = new CDO($this, $timeout, (bool) env('DEBUG', false));
        }
    }

    final public function disconnect(): void
    {
        $this->cdo = null;
    }

    /**
     * @throws CDOException
     */
    final public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * @return CDO
     * @throws CDOException
     */
    final public function connection(): CDO
    {
        $this->connect();
        return $this->cdo;
    }

    /**
     * @return bool
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
     * @return array{
     *     status: bool, latency: float|null, error: string|null
     * }
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
