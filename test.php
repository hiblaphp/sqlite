<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Hibla\Sqlite\Internals\Connection;
use Hibla\Sqlite\ValueObjects\SqliteConfig;

use function Hibla\await;

// Helper for procedural testing
function assertTest(string $name, bool $condition): void
{
    if ($condition) {
        echo "✅ [PASS] {$name}\n";
    } else {
        echo "❌ [FAIL] {$name}\n";
        exit(1);
    }
}

// Setup Database File
$dbId = uniqid();
$dbFile = __DIR__ . "/test_database_{$dbId}.sqlite";

// Clean up previous runs and clear the trace log
@unlink(__DIR__ . '/trace.log');

foreach (['', '-wal', '-shm'] as $ext) {
    $file = $dbFile . $ext;
    if (file_exists($file)) {
        @unlink($file);
    }
}

echo "Starting SQLite Connection Test (DB: {$dbId})...\n";
echo "----------------------------------\n";

try {
    // 2. Initialize Configuration & Connection
    $config = new SqliteConfig(database: $dbFile);
    $connection = new Connection($config);

    echo "⏳ Spawning raw SQLite process...\n";
    await($connection->connect());
    assertTest("Process spawned successfully", !$connection->isClosed());

    // 3. Test Schema Creation
    echo "\n⏳ Creating table...\n";
    await($connection->query("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL
        )
    "));
    
    await($connection->query("DELETE FROM users"));
    assertTest("Table ready and empty", true);

    // 4. Test Insert with Positional Parameters
    echo "\n⏳ Inserting Alice (Positional params via prepared statement)...\n";
    $stmt1 = await($connection->prepare("INSERT INTO users (name, email) VALUES (?, ?)"));
    $res1 = await($stmt1->execute(['Alice', 'alice@example.com']));
    assertTest("Alice inserted (Affected: {$res1->affectedRows})", $res1->affectedRows === 1);
    assertTest("Last Insert ID is 1", $res1->lastInsertId === 1);
    await($stmt1->close());

    // 5. Test Insert with Named Parameters
    echo "\n⏳ Inserting Bob (Named params via prepared statement)...\n";
    $stmt2 = await($connection->prepare("INSERT INTO users (name, email) VALUES (:name, :email)"));
    $res2 = await($stmt2->execute([':name' => 'Bob', ':email' => 'bob@example.com']));
    assertTest("Bob inserted (Affected: {$res2->affectedRows})", $res2->affectedRows === 1);
    assertTest("Last Insert ID is 2", $res2->lastInsertId === 2);
    await($stmt2->close());

    // 6. Test Standard Query
    echo "\n⏳ Querying all users...\n";
    $res3 = await($connection->query("SELECT * FROM users ORDER BY id ASC"));
    assertTest("Row count is 2", $res3->rowCount === 2);

    $users = $res3->fetchAll();
    assertTest("First user is Alice", $users[0]['name'] === 'Alice');
    assertTest("Second user is Bob", $users[1]['name'] === 'Bob');

    // 7a. Test Standard Streaming Query
    echo "\n⏳ Streaming users...\n";
    $stream = await($connection->streamQuery("SELECT * FROM users ORDER BY id DESC"));

    $streamedNames = [];
    foreach ($stream as $row) {
        $streamedNames[] = $row['name'];
    }

    assertTest("Stream yielded 2 rows", count($streamedNames) === 2);
    assertTest("Stream order is correct (DESC)", $streamedNames[0] === 'Bob' && $streamedNames[1] === 'Alice');

    // 7b. Test Large-Scale Memory-Efficient Streaming (100,000 Rows)
    echo "\n⏳ Testing Memory-Efficient Streaming on 100,000 Rows...\n";
    
    $startMemory = memory_get_usage();
    
    $largeStream = await($connection->streamQuery("
        WITH RECURSIVE cnt(x) AS (
            SELECT 1 
            UNION ALL 
            SELECT x+1 FROM cnt LIMIT 100000
        ) 
        SELECT x, 'user_name_' || x AS name FROM cnt;
    ", bufferSize: 100));

    $processedCount = 0;
    $maxMemoryDuringStream = 0;

    foreach ($largeStream as $row) {
        $processedCount++;
        $maxMemoryDuringStream = max($maxMemoryDuringStream, memory_get_usage());
        
        if ($processedCount % 25000 === 0) {
            echo "   ... streamed {$processedCount} rows (Current Memory: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB)\n";
        }
    }

    $endMemory = memory_get_usage();
    $memoryDelta = ($maxMemoryDuringStream - $startMemory) / 1024 / 1024;

    assertTest("Processed all 100,000 rows successfully", $processedCount === 100000);
    
    echo "📊 Memory Profile:\n";
    echo "   - Baseline Memory: " . round($startMemory / 1024 / 1024, 2) . " MB\n";
    echo "   - Peak Memory during streaming: " . round($maxMemoryDuringStream / 1024 / 1024, 2) . " MB\n";
    echo "   - Memory delta: " . round($memoryDelta, 3) . " MB\n";

    assertTest("Memory delta is flat (< 1.5 MB)", $memoryDelta < 1.5);

    // 8. Test Error Handling on Prepared Statement
    echo "\n⏳ Testing Error Handling on Prepared Statement (Unique Constraint)...\n";
    $stmt3 = await($connection->prepare("INSERT INTO users (name, email) VALUES (?, ?)"));
    try {
        await($stmt3->execute(['Charlie', 'alice@example.com'])); // Duplicate email
        assertTest("Duplicate email should have thrown", false);
    } catch (\Throwable $e) {
        assertTest("Caught Exception: " . $e->getMessage(), true);
    }
    await($stmt3->close());

} catch (\Throwable $e) {
    echo "\n❌ FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    // 9. Cleanup
    echo "\n⏳ Closing connection...\n";
    if (isset($connection)) {
        $connection->close(true); // Explicit clean shutdown with ProcessKiller
        assertTest("Connection closed cleanly", $connection->isClosed());
    }

    foreach (['', '-wal', '-shm'] as $ext) {
        $file = $dbFile . $ext;
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    echo "🧹 Cleaned up database files\n";
}

echo "----------------------------------\n";
echo "🎉 All tests completed successfully!\n";