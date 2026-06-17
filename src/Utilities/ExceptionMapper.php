<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Utilities;

use Hibla\Sql\Exceptions\ConstraintViolationException;
use Hibla\Sql\Exceptions\DeadlockException;
use Hibla\Sql\Exceptions\LockWaitTimeoutException;
use Hibla\Sql\Exceptions\QueryException;

/**
 * @internal
 *
 * Maps raw SQLite error codes to standard, typed Hibla SQL Exceptions.
 */
final class ExceptionMapper
{
    private const int SQLITE_BUSY = 5;
    private const int SQLITE_LOCKED = 6;
    private const int SQLITE_CONSTRAINT = 19;
    private const int SQLITE_BUSY_SNAPSHOT = 517;
    private const int SQLITE_LOCKED_SHAREDCACHE = 1542;

    /**
     * Maps SQLite error codes to standard Hibla SQL Exceptions.
     */
    public static function map(int $code, string $message): \Throwable
    {
        return match ($code) {
            self::SQLITE_CONSTRAINT => new ConstraintViolationException($message, $code),

            self::SQLITE_LOCKED,
            self::SQLITE_BUSY_SNAPSHOT,
            self::SQLITE_LOCKED_SHAREDCACHE => new DeadlockException($message, $code),
            
            self::SQLITE_BUSY => new LockWaitTimeoutException($message, $code),
            default => new QueryException($message, $code),
        };
    }
}
