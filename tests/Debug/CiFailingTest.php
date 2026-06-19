<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Promise;

use function Hibla\await;
use function Hibla\delay;

describe('CI Debugging - PoolManager', function () {

    it('gracefully closeAsync() waits for multiple active connections with cancelled queries to settle before resolving', function () {
        fwrite(STDERR, "\n[TEST 1] Starting closeAsync() test\n");
        $pool = makePool([
            'maxSize' => 2,
            'kill_worker_on_cancel' => false,
        ]);

        try {
            $conn1 = await($pool->get());
            $conn2 = await($pool->get());
            fwrite(STDERR, "[TEST 1] Connections acquired\n");

            $p1 = $conn1->query(slowCteQuery());
            $p2 = $conn2->query(slowCteQuery());
            fwrite(STDERR, "[TEST 1] Queries dispatched\n");

            Loop::addTimer(0.1, function () use ($p1, $p2) {
                fwrite(STDERR, "[TEST 1] Cancelling promises now...\n");
                $p1->cancel();
                $p2->cancel();
            });

            try {
                await(Promise::all([$p1, $p2]));
            } catch (CancelledException $e) {
                fwrite(STDERR, "[TEST 1] Promises threw CancelledException as expected\n");
            }

            $resolved = false;
            fwrite(STDERR, "[TEST 1] Calling closeAsync()\n");
            $shutdown = $pool->closeAsync()->then(function () use (&$resolved): void {
                $resolved = true;
                fwrite(STDERR, "[TEST 1] closeAsync() resolved\n");
            });

            await(delay(0.2));
            expect($resolved)->toBeFalse();

            fwrite(STDERR, "[TEST 1] Releasing connections back to pool\n");
            $pool->release($conn1);
            $pool->release($conn2);

            await($shutdown);
            expect($resolved)->toBeTrue()
                ->and($pool->stats['total_connections'])->toBe(0)
            ;
            fwrite(STDERR, "[TEST 1] Test completed successfully\n");
        } finally {
            $pool->close();
        }
    });

    it('pings only idle connections and ignores active, checked-out connections', function () {
        fwrite(STDERR, "\n[TEST 2] Starting healthCheck() test\n");
        $pool = makePool(['maxSize' => 2]);

        try {
            $activeConn = await($pool->get());
            fwrite(STDERR, "[TEST 2] Active connection acquired\n");

            $idleConn = await($pool->get());
            $pool->release($idleConn);
            fwrite(STDERR, "[TEST 2] Idle connection acquired and released\n");

            expect($pool->stats['pooled_connections'])->toBe(1)
                ->and($pool->stats['active_connections'])->toBe(1)
            ;

            fwrite(STDERR, "[TEST 2] Waiting 0.5s before healthCheck...\n");
            await(delay(0.5));

            fwrite(STDERR, "[TEST 2] Executing healthCheck()\n");
            $check = await($pool->healthCheck());
            
            fwrite(STDERR, "[TEST 2] HealthCheck result: " . json_encode($check) . "\n");

            expect($check['total_checked'])->toBe(1)
                ->and($check['healthy'])->toBe(1)
                ->and($check['unhealthy'])->toBe(0)
            ;

            $pool->release($activeConn);
            fwrite(STDERR, "[TEST 2] Test completed successfully\n");
        } finally {
            $pool->close();
        }
    });
});