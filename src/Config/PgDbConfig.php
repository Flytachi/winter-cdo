<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;
use Flytachi\Winter\Cdo\Config\Common\EntityCallDbTrait;

abstract class PgDbConfig extends BaseDbConfig
{
    use EntityCallDbTrait;

    protected string $host = 'localhost';
    protected int $port = 5432;
    protected string $database = 'postgres';
    protected string $username = 'postgres';
    protected string $password = '';
    protected string $schema = 'public';
    protected ?string $charset = null;

    public function getDns(): string
    {
        $dns = parent::getDns();
        if ($this->charset !== null) {
            $dns .= "options='--client_encoding=" . $this->charset . "';";
        }
        return $dns;
    }

    final public function getDriver(): string
    {
        return 'pgsql';
    }

    public function getSchema(): ?string
    {
        return $this->schema;
    }
}
