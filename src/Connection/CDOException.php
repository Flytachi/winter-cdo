<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Connection;

use Flytachi\Winter\Base\Exception\Exception;
use Psr\Log\LogLevel;

/**
 * CDOException — Database Operation Exception
 *
 * Thrown by {@see CDO} for all database-level failures: connection errors,
 * query execution errors, constraint violations, and invalid arguments
 * (e.g. empty `conflictColumns` in upsert).
 *
 * The exception always wraps the original `PDOException` as its `$previous`
 * cause, so the full PDO error — including SQLSTATE code and driver message —
 * is preserved in the chain.
 *
 * All `CDOException` instances are logged at `LogLevel::ALERT` severity,
 * indicating that immediate operator attention may be required.
 *
 * ```
 * try {
 *     $cdo->insert('users', $data);
 * } catch (CDOException $e) {
 *     // $e->getMessage()  — human-readable CDO message
 *     // $e->getPrevious() — original PDOException with SQLSTATE details
 * }
 * ```
 *
 * @package Flytachi\Winter\Cdo\Connection
 * @author  Flytachi
 */
class CDOException extends Exception
{
    protected string $logLevel = LogLevel::ALERT;
}
