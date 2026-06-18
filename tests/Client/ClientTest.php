<?php

declare(strict_types=1);

use Hibla\Sql\RowStream as RowStreamInterface;
use Hibla\Sqlite\Interfaces\SqliteResult;
use Hibla\Sqlite\Internals\ManagedPreparedStatement;

use function Hibla\await;

describe('SqliteClient (Async Connection)', function () {

    it('executes a standard query() and parameter bindings', function () {
        $client = makeClient(['force_sync' => false]);

        try {
            $result = await($client->query('SELECT :a AS a, :b AS b', ['b' => 'world', 'a' => 'hello']));

            expect($result)->toBeInstanceOf(SqliteResult::class);

            $row = $result->fetchOne();
            expect($row['a'])->toBe('hello')
                ->and($row['b'])->toBe('world')
            ;
        } finally {
            $client->close();
        }
    });

    it('returns affected rows with execute()', function () {
        $client = makeClient(['force_sync' => false]);

        try {
            await($client->query('CREATE TABLE t (v TEXT)'));
            await($client->query("INSERT INTO t VALUES ('A'), ('B')"));

            $affected = await($client->execute("UPDATE t SET v = 'C'"));
            expect($affected)->toBe(2);
        } finally {
            $client->close();
        }
    });

    it('returns the last inserted ID with executeGetId()', function () {
        $client = makeClient(['force_sync' => false]);

        try {
            await($client->query('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)'));

            $id1 = await($client->executeGetId("INSERT INTO t (v) VALUES ('first')"));
            $id2 = await($client->executeGetId("INSERT INTO t (v) VALUES ('second')"));

            expect($id1)->toBe(1)
                ->and($id2)->toBe(2)
            ;
        } finally {
            $client->close();
        }
    });

    it('returns the first row using fetchOne()', function () {
        $client = makeClient(['force_sync' => false]);

        try {
            await($client->query('CREATE TABLE t (v TEXT)'));
            await($client->query("INSERT INTO t VALUES ('first'), ('second')"));

            $row = await($client->fetchOne('SELECT v FROM t ORDER BY rowid ASC'));
            expect($row)->toBeArray()
                ->and($row['v'])->toBe('first')
            ;
        } finally {
            $client->close();
        }
    });

    it('retrieves single values using fetchValue() with multiple column formats', function () {
        $client = makeClient(['force_sync' => false]);

        try {
            $val1 = await($client->fetchValue('SELECT 100 AS first, 200 AS second'));
            expect($val1)->toBe(100);

            $val2 = await($client->fetchValue('SELECT 100 AS first, 200 AS second', 1));
            expect($val2)->toBe(200);
            $val3 = await($client->fetchValue('SELECT 100 AS first, 200 AS second', 'second'));
            expect($val3)->toBe(200);

            await($client->query('CREATE TABLE t (v TEXT)'));
            $val4 = await($client->fetchValue('SELECT v FROM t'));
            expect($val4)->toBeNull();
        } finally {
            $client->close();
        }
    });

    it('creates and runs prepared statements with prepare()', function () {
        $client = makeClient(['force_sync' => false]);

        try {
            $stmt = await($client->prepare('SELECT :val AS val'));
            expect($stmt)->toBeInstanceOf(ManagedPreparedStatement::class);

            $result = await($stmt->execute(['val' => 'hello']));
            expect($result->fetchOne()['val'])->toBe('hello');

            await($stmt->close());
        } finally {
            $client->close();
        }
    });

    it('streams rows completely using stream()', function () {
        $client = makeClient(['force_sync' => false]);

        try {
            $sql = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < 5) SELECT x AS n FROM cnt;';
            $stream = await($client->stream($sql));

            expect($stream)->toBeInstanceOf(RowStreamInterface::class);

            $rows = [];
            foreach ($stream as $row) {
                $rows[] = (int) $row['n'];
            }

            expect($rows)->toBe([1, 2, 3, 4, 5]);
        } finally {
            $client->close();
        }
    });
});

describe('SqliteClient (Sync Connection Fallback)', function () {

    it('executes a standard query() and parameter bindings', function () {
        $client = makeClient(['force_sync' => true]);

        try {
            $result = await($client->query('SELECT :a AS a, :b AS b', ['b' => 'world', 'a' => 'hello']));

            expect($result)->toBeInstanceOf(SqliteResult::class);

            $row = $result->fetchOne();
            expect($row['a'])->toBe('hello')
                ->and($row['b'])->toBe('world')
            ;
        } finally {
            $client->close();
        }
    });

    it('returns affected rows with execute()', function () {
        $client = makeClient(['force_sync' => true]);

        try {
            await($client->query('CREATE TABLE t (v TEXT)'));
            await($client->query("INSERT INTO t VALUES ('A'), ('B')"));

            $affected = await($client->execute("UPDATE t SET v = 'C'"));
            expect($affected)->toBe(2);
        } finally {
            $client->close();
        }
    });

    it('returns the last inserted ID with executeGetId()', function () {
        $client = makeClient(['force_sync' => true]);

        try {
            await($client->query('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)'));

            $id1 = await($client->executeGetId("INSERT INTO t (v) VALUES ('first')"));
            $id2 = await($client->executeGetId("INSERT INTO t (v) VALUES ('second')"));

            expect($id1)->toBe(1)
                ->and($id2)->toBe(2)
            ;
        } finally {
            $client->close();
        }
    });

    it('returns the first row using fetchOne()', function () {
        $client = makeClient(['force_sync' => true]);

        try {
            await($client->query('CREATE TABLE t (v TEXT)'));
            await($client->query("INSERT INTO t VALUES ('first'), ('second')"));

            $row = await($client->fetchOne('SELECT v FROM t ORDER BY rowid ASC'));
            expect($row)->toBeArray()
                ->and($row['v'])->toBe('first')
            ;
        } finally {
            $client->close();
        }
    });

    it('retrieves single values using fetchValue() with multiple column formats', function () {
        $client = makeClient(['force_sync' => true]);

        try {
            $val1 = await($client->fetchValue('SELECT 100 AS first, 200 AS second'));
            expect($val1)->toBe(100);

            $val2 = await($client->fetchValue('SELECT 100 AS first, 200 AS second', 1));
            expect($val2)->toBe(200);

            $val3 = await($client->fetchValue('SELECT 100 AS first, 200 AS second', 'second'));
            expect($val3)->toBe(200);
        } finally {
            $client->close();
        }
    });

    it('creates and runs prepared statements with prepare()', function () {
        $client = makeClient(['force_sync' => true]);

        try {
            $stmt = await($client->prepare('SELECT :val AS val'));
            expect($stmt)->toBeInstanceOf(ManagedPreparedStatement::class);

            $result = await($stmt->execute(['val' => 'hello']));
            expect($result->fetchOne()['val'])->toBe('hello');

            await($stmt->close());
        } finally {
            $client->close();
        }
    });

    it('streams rows completely using stream()', function () {
        $client = makeClient(['force_sync' => true]);

        try {
            $sql = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < 5) SELECT x AS n FROM cnt;';
            $stream = await($client->stream($sql));

            expect($stream)->toBeInstanceOf(RowStreamInterface::class);

            $rows = [];
            foreach ($stream as $row) {
                $rows[] = (int) $row['n'];
            }

            expect($rows)->toBe([1, 2, 3, 4, 5]);
        } finally {
            $client->close();
        }
    });
});
