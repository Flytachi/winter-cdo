<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Config\Common;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\ConnectionPool;

trait EntityCallDbTrait
{
    /**
     * @return CDO
     */
    final public static function instance(): CDO
    {
        return ConnectionPool::db(static::class);
    }
}
