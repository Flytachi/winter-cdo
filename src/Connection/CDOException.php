<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Connection;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;

class CDOException extends Exception
{
    protected string $logLevel = LogLevel::ALERT;
}
