<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

use function Hibla\await;

describe('SqliteClient - Concurrency & SLEEP()', function () {

    it('supports the custom SLEEP() function and pauses execution', function () {
        $client = makeClient();

        try {
            $start = microtime(true);

            $result = await($client->fetchValue('SELECT SLEEP(0.2)'));

            $elapsed = microtime(true) - $start;

            expect((int)$result)->toBe(0)
                ->and($elapsed)->toBeGreaterThanOrEqual(0.2)
            ;
        } finally {
            $client->close();
        }
    });

    it('executes queries truly asynchronously and concurrently in background workers', function () {
        $client = makeClient([
            'force_sync' => false,
            'maxConnections' => 3,
        ]);

        try {
            $start = microtime(true);

            $promises = [
                $client->query('SELECT SLEEP(0.5)'),
                $client->query('SELECT SLEEP(0.5)'),
                $client->query('SELECT SLEEP(0.5)'),
            ];

            await(Promise::all($promises));

            $elapsed = microtime(true) - $start;

            expect($elapsed)->toBeLessThan(1.2);
            expect($client->stats['total_connections'])->toBe(3);
        } finally {
            $client->close();
        }
    });

    it('executes queries sequentially when force_sync is enabled', function () {
        $client = makeClient([
            'force_sync' => true,
            'maxConnections' => 3,
        ]);

        try {
            $start = microtime(true);

            $promises = [
                $client->query('SELECT SLEEP(0.3)'),
                $client->query('SELECT SLEEP(0.3)'),
                $client->query('SELECT SLEEP(0.3)'),
            ];

            await(Promise::all($promises));

            $elapsed = microtime(true) - $start;

            expect($elapsed)->toBeGreaterThanOrEqual(0.9);
            expect($client->stats['total_connections'])->toBe(3);
        } finally {
            $client->close();
        }
    });

});
