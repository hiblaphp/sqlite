<?php

use function Hibla\await;
use function Hibla\delay;

describe('PoolManager - Connection Reset', function (): void {
    it('drops the connection and satisfies the next waiter if the reset hook fails', function (): void {
        $attempts = 0;
        $pool = makePool([
            'maxSize' => 1,
            'reset_connection' => true,
            'onConnect' => function () use (&$attempts) {
                $attempts++;
                if ($attempts === 2) {
                    throw new RuntimeException('Simulated setup failure during reset');
                }
            },
        ]);

        try {
            $conn = await($pool->get());
            expect($attempts)->toBe(1);

            $pool->release($conn);

            await(delay(0.2));

            expect($pool->stats['total_connections'])->toBe(0);

            $conn2 = await($pool->get());
            expect($attempts)->toBe(3);

            $pool->release($conn2);

            $conn = null;
            $conn2 = null;
            gc_collect_cycles();
        } finally {
            $pool->close();
        }
    });

    it('clears temporary tables and session state when resetConnection is enabled (Async)', function () {
        $pool = makePool([
            'maxSize' => 1,
            'force_sync' => false,
            'reset_connection' => true,
        ]);

        try {
            $conn1 = await($pool->get());

            await($conn1->query('CREATE TEMP TABLE reset_test (id INT)'));
            await($conn1->query('INSERT INTO reset_test VALUES (1)'));

            $pool->release($conn1);
            await(delay(0.1));

            $conn2 = await($pool->get());

            $exception = null;
            try {
                await($conn2->query('SELECT * FROM reset_test'));
            } catch (\Throwable $e) {
                $exception = $e;
            }

            expect($exception)->not->toBeNull()
                ->and($exception->getMessage())->toContain('no such table: reset_test');

            $pool->release($conn2);
        } finally {
            $conn1 = null;
            $conn2 = null;
            gc_collect_cycles();

            $pool->close();
        }
    });

    it('clears temporary tables and session state when resetConnection is enabled (Sync)', function () {
        $pool = makePool([
            'maxSize' => 1,
            'force_sync' => true,
            'reset_connection' => true,
        ]);

        try {
            $conn1 = await($pool->get());

            await($conn1->query('CREATE TEMP TABLE reset_test_sync (id INT)'));
            await($conn1->query('INSERT INTO reset_test_sync VALUES (99)'));

            $pool->release($conn1);

            $conn2 = await($pool->get());

            $exception = null;
            try {
                await($conn2->query('SELECT * FROM reset_test_sync'));
            } catch (\Throwable $e) {
                $exception = $e;
            }

            expect($exception)->not->toBeNull()
                ->and($exception->getMessage())->toContain('no such table: reset_test_sync');

            $pool->release($conn2);
        } finally {
            $conn1 = null;
            $conn2 = null;
            gc_collect_cycles();

            $pool->close();
        }
    });
});
