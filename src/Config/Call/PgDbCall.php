<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Call;

use Flytachi\Winter\Cdo\Config\Common\BaseDbConfig;

final class PgDbCall extends BaseDbConfig
{
    public function __construct(
        public string $host = 'localhost',
        public int $port = 5432,
        public string $database = 'postgres',
        public string $username = 'postgres',
        public string $password = '',
        public string $schema = 'public',
        public ?string $charset = null
    ) {
    }

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

    public function sepUp(): void
    {
    }
}
