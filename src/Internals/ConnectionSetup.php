<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Sqlite\Interfaces\ConnectionInterface;
use Hibla\Sqlite\Interfaces\ConnectionSetupInterface;

/**
 * @internal
 */
final class ConnectionSetup implements ConnectionSetupInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    /**
     * @inheritDoc
     */
    public function query(string $sql): PromiseInterface
    {
        return $this->connection->query($sql);
    }

    /**
     * @inheritDoc
     */
    public function execute(string $sql): PromiseInterface
    {
        return $this->connection->query($sql)
            ->then(fn (Result $result) => $result->affectedRows)
        ;
    }
}
