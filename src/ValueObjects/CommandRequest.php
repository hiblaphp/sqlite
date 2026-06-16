<?php

declare(strict_types=1);

namespace Hibla\Sqlite\ValueObjects;

use Hibla\Promise\Promise;
use Hibla\Sqlite\Internals\SqliteRowStream;

/**
 * @internal
 */
final class CommandRequest
{
    public const string TYPE_QUERY = 'query';
    public const string TYPE_STREAM_QUERY = 'stream_query';
    public const string TYPE_EXECUTE = 'execute';
    public const string TYPE_EXECUTE_STREAM = 'execute_stream';

    public function __construct(
        public string $type,
        public Promise $promise,
        public string $sql,
        public array $params = [],
        public ?SqliteRowStream $streamContext = null,
        public string $id = '',
    ) {
        if ($this->id === '') {
            $this->id = bin2hex(random_bytes(16));
        }
    }
}