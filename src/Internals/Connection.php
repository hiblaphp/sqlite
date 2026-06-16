<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\ConnectionException;
use Hibla\Sqlite\Handlers\QueryHandler;
use Hibla\Sqlite\Handlers\StreamHandler;
use Hibla\Sqlite\Utilities\SystemHelper;
use Hibla\Sqlite\ValueObjects\CommandRequest;
use Hibla\Sqlite\ValueObjects\SqliteConfig;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;
use Rcalicdan\ProcessKiller\ProcessKiller;
use SplQueue;

use function Hibla\async;
use function Hibla\await;

/**
 * @internal
 */
final class Connection
{
    /** @var resource|null */
    private $processResource = null;

    private ?PromiseWritableStream $stdin = null;
    private ?PromiseReadableStream $stdout = null;
    private SplQueue $commandQueue;
    private ?CommandRequest $currentCommand = null;
    private bool $closed = false;
    private int $pid = 0;

    // Cooperative suspension properties
    private bool $paused = false;
    private ?Promise $pausePromise = null;

    private readonly SqliteConfig $config;
    private readonly QueryHandler $queryHandler;
    private readonly StreamHandler $streamHandler;

    public function __construct(SqliteConfig|array|string $config)
    {
        SystemHelper::validateEnvironment();

        $this->config = match (true) {
            $config instanceof SqliteConfig => $config,
            \is_array($config) => SqliteConfig::fromArray($config),
            \is_string($config) => SqliteConfig::fromUri($config),
        };

        $this->commandQueue = new SplQueue();
        $this->queryHandler = new QueryHandler($this);
        $this->streamHandler = new StreamHandler($this);
    }

    public static function create(SqliteConfig|array|string $config): PromiseInterface
    {
        $connection = new self($config);
        return $connection->connect();
    }

    public function connect(): PromiseInterface
    {
        /** @var Promise<self> $promise */
        $promise = new Promise();

        async(function () use ($promise) {
            try {
                $phpBinary = SystemHelper::getPhpBinary();
                $autoload = SystemHelper::getAutoloadPath();
                $configSerialized = base64_encode(serialize($this->config));
                $workerScript = __DIR__ . '/worker.php';

                $command = \sprintf(
                    '%s %s %s %s',
                    \escapeshellarg($phpBinary),
                    \escapeshellarg($workerScript),
                    \escapeshellarg($autoload),
                    \escapeshellarg($configSerialized)
                );

                $descriptorSpec = PHP_OS_FAMILY === 'Windows'
                    ? [0 => ['socket'], 1 => ['socket'], 2 => ['socket']]
                    : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

                $options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];

                $pipes = [];
                $this->processResource = @\proc_open($command, $descriptorSpec, $pipes, null, null, $options);

                if (!\is_resource($this->processResource)) {
                    throw new ConnectionException('Failed to spawn raw SQLite process.');
                }

                \stream_set_blocking($pipes[0], false);
                \stream_set_blocking($pipes[1], false);
                \stream_set_blocking($pipes[2], false);

                $this->stdin = new PromiseWritableStream($pipes[0]);
                $this->stdout = new PromiseReadableStream($pipes[1]);

                $status = \proc_get_status($this->processResource);
                $this->pid = $status['pid'];

                $promise->resolve($this);
                $this->startReadLoop();
            } catch (\Throwable $e) {
                $promise->reject(new ConnectionException('Failed to establish raw SQLite process connection.', 0, $e));
            }
        });

        return $promise;
    }

    /**
     * Safely writes a payload to the worker daemon. Handles crashes natively.
     */
    public function writeIpc(string $payload): void
    {
        if ($this->isClosed() || $this->stdin === null) {
            return;
        }

        async(function () use ($payload): void {
            try {
                await($this->stdin->writeAsync($payload));
            } catch (\Throwable $e) {
                $this->handleCrash(new ConnectionException('Failed to write command to SQLite IPC pipe.', 0, $e));
            }
        });
    }

    public function query(string $sql): PromiseInterface
    {
        return $this->enqueueCommand(CommandRequest::TYPE_QUERY, $sql);
    }

    public function streamQuery(string $sql, int $bufferSize = 100): PromiseInterface
    {
        return $this->enqueueCommand(CommandRequest::TYPE_STREAM_QUERY, $sql, [], $bufferSize);
    }

    public function prepare(string $sql): PromiseInterface
    {
        if ($this->isClosed()) {
            return Promise::rejected(new ConnectionException('Cannot prepare statement on a closed connection.'));
        }

        $stmt = new PreparedStatement($this, $sql);
        return Promise::resolved($stmt);
    }

    public function executeStatement(PreparedStatement $stmt, array $params): PromiseInterface
    {
        return $this->enqueueCommand(CommandRequest::TYPE_EXECUTE, $stmt->parsedSql, $params);
    }

    public function executeStream(PreparedStatement $stmt, array $params, int $bufferSize = 100): PromiseInterface
    {
        return $this->enqueueCommand(CommandRequest::TYPE_EXECUTE_STREAM, $stmt->parsedSql, $params, $bufferSize);
    }

    public function pause(): void
    {
        if ($this->paused) return;
        $this->paused = true;
        $this->pausePromise = new Promise();
    }

    public function resume(): void
    {
        if (!$this->paused) return;
        $this->paused = false;
        if ($this->pausePromise !== null) {
            $this->pausePromise->resolve(null);
            $this->pausePromise = null;
        }
    }

    public function ping(): PromiseInterface
    {
        if ($this->isClosed()) {
            return Promise::rejected(new ConnectionException('Connection is closed.'));
        }
        return $this->query('SELECT 1')->then(static fn() => true);
    }

    public function reset(): PromiseInterface
    {
        if ($this->isClosed()) {
            return Promise::rejected(new ConnectionException('Connection is closed.'));
        }
        return Promise::resolved(true);
    }

    public function close(bool $killProcess = true): void
    {
        if ($this->closed) return;

        $this->closed = true;

        if ($this->stdin !== null) {
            $this->stdin->close();
            $this->stdin = null;
        }
        if ($this->stdout !== null) {
            $this->stdout->close();
            $this->stdout = null;
        }

        if ($this->pausePromise !== null) {
            $this->pausePromise->resolve(null);
            $this->pausePromise = null;
        }

        if ($killProcess && $this->pid > 0) {
            ProcessKiller::killTreesAsync([$this->pid]);
        }

        if (\is_resource($this->processResource)) {
            @\proc_close($this->processResource);
            $this->processResource = null;
        }

        $this->rejectQueue(new ConnectionException('Connection has been closed.'));
    }

    public function isClosed(): bool
    {
        if ($this->closed) return true;

        if (!\is_resource($this->processResource)) {
            return true;
        }

        $status = \proc_get_status($this->processResource);
        return !$status['running'];
    }

    /**
     * @return PromiseInterface<mixed>
     */
    private function enqueueCommand(string $type, string $sql, array $params = [], ?int $bufferSize = null): PromiseInterface
    {
        if ($this->isClosed()) {
            return Promise::rejected(new ConnectionException('Cannot execute command on a closed connection.'));
        }

        $promise = new Promise();

        $request = new CommandRequest($type, $promise, $sql, $params);

        $isStream = ($type === CommandRequest::TYPE_STREAM_QUERY || $type === CommandRequest::TYPE_EXECUTE_STREAM);
        if ($isStream && $bufferSize !== null) {
            $stream = new SqliteRowStream($bufferSize, $promise);

            $stream->backpressureHandler = function (bool $shouldPause): void {
                $shouldPause ? $this->pause() : $this->resume();
            };

            $request->streamContext = $stream;
            $promise->resolve($stream);
        }

        $promise->onCancel(function () use ($request): void {
            $this->handleCommandCancellation($request);
        });

        $this->commandQueue->enqueue($request);
        $this->processNextCommand();

        return $promise;
    }

    private function handleCommandCancellation(CommandRequest $request): void
    {
        if ($this->removeFromQueue($request)) {
            $request->promise->reject(new \Hibla\Promise\Exceptions\CancelledException('Command cancelled in queue.'));
            return;
        }

        if ($this->currentCommand === $request && $this->config->killWorkerOnCancel) {
            $this->close(true);
        }
    }

    private function processNextCommand(): void
    {
        if ($this->currentCommand !== null || $this->commandQueue->isEmpty()) {
            return;
        }

        $this->currentCommand = $this->commandQueue->dequeue();

        $cmdType = $this->currentCommand->type;
        $isStream = ($cmdType === CommandRequest::TYPE_STREAM_QUERY || $cmdType === CommandRequest::TYPE_EXECUTE_STREAM);

        if ($isStream) {
            $this->streamHandler->start($this->currentCommand);
        } else {
            $this->queryHandler->start($this->currentCommand);
        }
    }

    private function startReadLoop(): void
    {
        async(function (): void {
            if ($this->stdout === null) return;

            try {
                while (null !== ($line = await($this->stdout->readLineAsync()))) {
                    $line = \trim($line);
                    if ($line === '') continue;

                    $response = \json_decode($line, true);

                    if (!\is_array($response)) {
                        throw new ConnectionException(
                            "Invalid JSON received from SQLite worker: " . \json_last_error_msg() .
                                " | Payload: " . \substr($line, 0, 200)
                        );
                    }

                    if ($this->currentCommand !== null && isset($response['id']) && $response['id'] === $this->currentCommand->id) {
                        $cmdType = $this->currentCommand->type;
                        $isStream = ($cmdType === CommandRequest::TYPE_STREAM_QUERY || $cmdType === CommandRequest::TYPE_EXECUTE_STREAM);

                        $isFinished = $isStream
                            ? $this->streamHandler->handleResponse($response, $this->currentCommand)
                            : $this->queryHandler->handleResponse($response, $this->currentCommand);

                        if ($isFinished) {
                            $this->currentCommand = null;
                            $this->processNextCommand();
                        }
                    }

                    if ($this->paused && $this->pausePromise !== null) {
                        await($this->pausePromise);
                    }
                }
            } catch (\Throwable $e) {
                $this->handleCrash(new ConnectionException('SQLite IPC pipe read loop failed.', 0, $e));
            } finally {
                $this->handleCrash(new ConnectionException('SQLite process stream closed.'));
            }
        });
    }

    private function handleCrash(\Throwable $e): void
    {
        if ($this->closed) return;

        $this->closed = true;

        if ($this->stdin !== null) {
            $this->stdin->close();
            $this->stdin = null;
        }
        if ($this->stdout !== null) {
            $this->stdout->close();
            $this->stdout = null;
        }

        if (\is_resource($this->processResource)) {
            @\proc_terminate($this->processResource);
            @\proc_close($this->processResource);
            $this->processResource = null;
        }

        if ($this->currentCommand !== null) {
            if ($this->currentCommand->streamContext !== null) {
                $this->currentCommand->streamContext->error($e);
            }
            $this->currentCommand->promise->reject($e);
            $this->currentCommand = null;
        }

        $this->rejectQueue($e);
    }

    private function rejectQueue(\Throwable $e): void
    {
        while (! $this->commandQueue->isEmpty()) {
            $cmd = $this->commandQueue->dequeue();
            $cmd->promise->reject($e);
        }
    }

    private function removeFromQueue(CommandRequest $request): bool
    {
        $found = false;
        $temp = new SplQueue();

        while (! $this->commandQueue->isEmpty()) {
            $cmd = $this->commandQueue->dequeue();
            if ($cmd === $request) {
                $found = true;
            } else {
                $temp->enqueue($cmd);
            }
        }

        $this->commandQueue = $temp;
        return $found;
    }

    public function __destruct()
    {
        $this->close(false);
    }
}
