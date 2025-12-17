<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Call;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;

final class DbCall extends BaseDbConfig
{
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password
    ) {
    }

    final public function sepUp(): void
    {
    }

    final public function getDriver(): string
    {
        return $this->driver;
    }
}
