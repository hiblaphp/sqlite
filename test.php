<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Hibla\Sqlite\Internals\Connection;
use Hibla\Sqlite\ValueObjects\SqliteConfig;

use function Hibla\await;

ini_set('memory_limit', '32M');

function mem(): string {
    return number_format(memory_get_usage(true) / 1024 / 1024, 2) . ' MB';
}

function memPeak(): string {
    return number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB';
}

echo "=================================================\n";
echo "   SQLite IPC Streaming & Backpressure Test\n";
echo "=================================================\n";
echo "Parent Memory limit : 32M (Will fatal if backpressure fails)\n";
echo "Target Row Count    : 500,000\n";
echo "Buffer Size         : 100\n";
echo "-------------------------------------------------\n";

$dbId = uniqid();
$dbFile = __DIR__ . "/stream_test_{$dbId}.sqlite";

try {
    $config = new SqliteConfig(database: $dbFile);
    $connection = new Connection($config);

    echo "⏳ Spawning raw SQLite worker daemon...\n";
    await($connection->connect());
    
    echo "✅ Worker connected. Baseline Memory: " . mem() . "\n\n";

    echo "⏳ Initiating 500,000 row stream from worker...\n";
    
    $start = microtime(true);
    
    $stream = await($connection->streamQuery("
        WITH RECURSIVE cnt(x) AS (
            SELECT 1 
            UNION ALL 
            SELECT x+1 FROM cnt LIMIT 500000
        ) 
        SELECT x, 'user_name_' || x AS name, randomblob(50) AS payload FROM cnt;
    ", bufferSize: 100));

    $count = 0;
    $memSamples = [];

    foreach ($stream as $row) {
        $count++;

        if ($count % 10000 === 0) {
            await(\Hibla\delay(0.001));
        }

        if ($count % 50000 === 0) {
            $currentMem = memory_get_usage(true) / 1024 / 1024;
            $memSamples[] = $currentMem;
            printf(
                "  Received %s rows | Current Mem: %.2f MB | Peak: %s\n",
                number_format($count),
                $currentMem,
                memPeak()
            );
        }
    }

    echo "\n=================================================\n";
    echo "✅ STREAM COMPLETE\n";
    echo "-------------------------------------------------\n";
    echo "Total Rows Streamed : " . number_format($count) . "\n";
    echo "Time Taken          : " . number_format(microtime(true) - $start, 2) . "s\n";
    echo "Final Peak Memory   : " . memPeak() . "\n";

    $min = min($memSamples);
    $max = max($memSamples);
    $drift = $max - $min;
    
    echo "Memory Drift        : " . round($drift, 2) . " MB\n";

    if ($drift < 2.0) {
        echo "✅ PASS: IPC Backpressure works flawlessly. Parent memory stayed completely flat!\n";
    } else {
        echo "❌ FAIL: Memory drifted significantly. Backpressure may be leaking.\n";
    }

} catch (\Throwable $e) {
    echo "\n❌ FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    echo "\n⏳ Tearing down worker...\n";
    if (isset($connection)) {
        $connection->close(true);
    }
    foreach (['', '-wal', '-shm'] as $ext) {
        $file = $dbFile . $ext;
        if (file_exists($file)) @unlink($file);
    }
    echo "🧹 Cleanup complete.\n";
}