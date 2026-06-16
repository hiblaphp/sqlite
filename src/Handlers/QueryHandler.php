<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

use Hibla\Sqlite\Internals\Connection;
use Hibla\Sqlite\Internals\Result;
use Hibla\Sqlite\Utilities\ExceptionMapper;
use Hibla\Sqlite\ValueObjects\CommandRequest;

/**
 * Handles standard, buffered queries and executions.
 *
 * @internal
 */
final class QueryHandler
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function start(CommandRequest $request): void
    {
        $payload = \json_encode([
            'id' => $request->id,
            'cmd' => 'query',
            'sql' => $request->sql,
            'params' => $request->params,
        ], JSON_UNESCAPED_SLASHES);

        $this->connection->writeIpc($payload . "\n");
    }

    /**
     * Processes incoming JSON frames.
     * 
     * @return bool True if the command is completely finished.
     */
    public function handleResponse(array $response, CommandRequest $cmd): bool
    {
        if ($response['status'] === 'ERROR') {
            $cmd->promise->reject(ExceptionMapper::map($response['errorCode'], $response['errorMessage']));
            return true;
        }

        if ($response['status'] === 'COMPLETED') {
            $result = new Result(
                affectedRows: $response['result']['affectedRows'] ?? 0,
                lastInsertId: $response['result']['lastInsertId'] ?? 0,
                rows: $response['result']['rows'] ?? []
            );
            $cmd->promise->resolve($result);
            return true;
        }

        return false;
    }
}