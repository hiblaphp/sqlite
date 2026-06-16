<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Handlers;

use Hibla\Sqlite\Internals\Connection;
use Hibla\Sqlite\Utilities\ExceptionMapper;
use Hibla\Sqlite\ValueObjects\CommandRequest;

/**
 * Handles row-by-row streaming queries and executions.
 *
 * @internal
 */
final class StreamHandler
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function start(CommandRequest $request): void
    {
        $payload = \json_encode([
            'id' => $request->id,
            'cmd' => 'stream',
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
        $stream = $cmd->streamContext;
        
        if ($stream === null) {
            return true; 
        }

        if ($response['status'] === 'ERROR') {
            $exception = ExceptionMapper::map($response['errorCode'], $response['errorMessage']);
            $stream->error($exception);
            $cmd->promise->reject($exception);
            return true;
        }

        if ($response['status'] === 'ROW') {
            $stream->push($response['row']);
            return false;
        }

        if ($response['status'] === 'COMPLETED') {
            $stream->complete();
            if (!$cmd->promise->isSettled()) {
                $cmd->promise->resolve($stream);
            }
            return true;
        }

        return false;
    }
}