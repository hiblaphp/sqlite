<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\PreparedStatement as PreparedStatementInterface;

/**
 * A wrapper around PreparedStatement that pins the checked-out Pool Connection
 * for rapid execution and safely releases it on close or destruction.
 * 
 * @internal
 */
final class ManagedPreparedStatement implements PreparedStatementInterface
{
    private bool $isReleased = false;

    public function __construct(
        private readonly PreparedStatementInterface $statement,
        private readonly Connection $connection,
        private readonly object $pool 
    ) {}

    /**
     * {@inheritDoc}
     * 
     * @return PromiseInterface<Result>
     */
    public function execute(array $params = []): PromiseInterface
    {
        /** @var PromiseInterface<Result> $promise */
        $promise = $this->statement->execute($params);
        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritDoc}
     * 
     * @return PromiseInterface<SqliteRowStream>
     */
    public function executeStream(array $params = []): PromiseInterface
    {
        /** @var PromiseInterface<SqliteRowStream> $promise */
        $promise = $this->statement->executeStream($params);
        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritDoc}
     * 
     * @return PromiseInterface<void>
     */
    public function close(): PromiseInterface
    {
        /** @var PromiseInterface<void> */
        return $this->statement->close()
            ->finally($this->releaseConnection(...));
    }

    private function releaseConnection(): void
    {
        if ($this->isReleased) {
            return;
        }

        $this->isReleased = true;
        
        /** @var mixed $poolManager */
        $poolManager = $this->pool;
        $poolManager->release($this->connection);
    }

    public function __destruct()
    {
        $this->releaseConnection();
    }
}