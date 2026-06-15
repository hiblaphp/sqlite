<?php

declare(strict_types=1);

namespace Hibla\Sqlite\ValueObjects;

final readonly class SqliteConfig
{
    /**
     * @param string $database Path to the SQLite database file, or ':memory:'.
     * @param int $busyTimeout Milliseconds to wait when the database is locked.
     * @param string $journalMode SQLite journal mode. Default is 'WAL' (Write-Ahead Logging) for high concurrency.
     * @param bool $foreignKeys Whether to enforce foreign key constraints.
     * @param bool $killWorkerOnCancel Whether to terminate the worker process if a query promise is cancelled.
     * @param int $connectTimeout Compatibility with pool interfaces (seconds).
     */
    public function __construct(
        public string $database,
        public int $busyTimeout = 5000,
        public string $journalMode = 'WAL',
        public bool $foreignKeys = true,
        public bool $killWorkerOnCancel = true,
        public int $connectTimeout = 10,
    ) {
        if ($this->busyTimeout < 0) {
            throw new \InvalidArgumentException('busyTimeout must be greater than or equal to zero.');
        }
    }

    /**
     * Parses a DSN-like URI, e.g., sqlite:///var/www/data/db.sqlite?busy_timeout=5000
     */
    public static function fromUri(string $uri): self
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['path'])) {
            throw new \InvalidArgumentException('Invalid SQLite URI: ' . $uri);
        }

        $query = [];
        if (isset($parts['query']) && \is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return new self(
            database: $parts['path'] === ':memory:' ? ':memory:' : urldecode($parts['path']),
            busyTimeout: isset($query['busy_timeout']) ? (int) $query['busy_timeout'] : 5000,
            journalMode: isset($query['journal_mode']) ? (string) $query['journal_mode'] : 'WAL',
            foreignKeys: isset($query['foreign_keys']) ? filter_var($query['foreign_keys'], FILTER_VALIDATE_BOOLEAN) : true,
            killWorkerOnCancel: isset($query['kill_worker_on_cancel']) ? filter_var($query['kill_worker_on_cancel'], FILTER_VALIDATE_BOOLEAN) : true,
        );
    }
}