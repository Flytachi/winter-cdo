<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;
use Flytachi\Winter\Cdo\Config\Common\EntityCallDbTrait;

abstract class DbConfig extends BaseDbConfig
{
    use EntityCallDbTrait;

    protected string $driver;
    protected string $host;
    protected int $port;
    protected string $database;
    protected string $username;
    protected string $password;

    final public function getDriver(): string
    {
        return $this->driver;
    }
}
