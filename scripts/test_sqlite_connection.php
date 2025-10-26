<?php
/**
 * Test script for SQLite_Connection class
 */
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "Testing SQLite_Connection class...\n\n";

try {
    // Create a test database in /tmp
    $testDbPath = sys_get_temp_dir() . '/test_sqlite_connection.db';
    
    // Remove old test database if exists
    if (file_exists($testDbPath)) {
        unlink($testDbPath);
    }
    
    echo "1. Creating SQLite_Connection instance...\n";
    $conn = new SQLite_Connection($testDbPath);
    echo "   ✓ Connection created successfully\n\n";
    
    echo "2. Creating test table...\n";
    $conn->execute("CREATE TABLE test_table (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        value INTEGER
    )");
    echo "   ✓ Table created successfully\n\n";
    
    echo "3. Inserting test data...\n";
    $conn->execute("INSERT INTO test_table (name, value) VALUES (?, ?)", ['test1', 100]);
    $lastId = $conn->lastInsertId();
    echo "   ✓ Inserted row with ID: $lastId\n\n";
    
    echo "4. Fetching data with fetchAll...\n";
    $rows = $conn->fetchAll("SELECT * FROM test_table WHERE value > ?", [50]);
    echo "   ✓ Found " . count($rows) . " row(s)\n";
    print_r($rows);
    echo "\n";
    
    echo "5. Fetching scalar value...\n";
    $count = $conn->scalar("SELECT COUNT(*) FROM test_table");
    echo "   ✓ Count: $count\n\n";
    
    echo "6. Testing transaction...\n";
    $conn->beginTransaction();
    $conn->execute("INSERT INTO test_table (name, value) VALUES (?, ?)", ['test2', 200]);
    $conn->commit();
    echo "   ✓ Transaction committed\n\n";
    
    echo "7. Testing quoteIdent...\n";
    $quoted = $conn->quoteIdent('my_table');
    echo "   ✓ Quoted identifier: $quoted\n\n";
    
    echo "8. Getting PDO instance...\n";
    $pdo = $conn->getPdo();
    echo "   ✓ PDO instance retrieved: " . get_class($pdo) . "\n\n";
    
    echo "9. Cleaning up...\n";
    $conn->close();
    unlink($testDbPath);
    echo "   ✓ Test database removed\n\n";
    
    echo "All tests passed! ✓\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
