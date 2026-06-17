<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Interfaces;

use Hibla\Sql\Result as ResultInterface;

/**
 * Extends the core Result interface with SQLite-specific metadata.
 */
interface SqliteResult extends ResultInterface
{
    /**
     * The unique memory ID of the connection that executed this query.
     * Useful for verifying connection recycling inside a pool.
     */
    public int $connectionId { get; }
}
