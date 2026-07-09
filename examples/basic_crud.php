<?php

declare(strict_types=1);

/**
 * Example: Basic CRUD operations with MongrelDB PHP client.
 *
 * Run: php examples/basic_crud.php
 * Requires: mongreldb-server running on http://127.0.0.1:8453
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Visorcraft\MongrelDB\Database;

$db = new Database('http://127.0.0.1:8453');

// Check health
if (!$db->health()) {
    fwrite(STDERR, "Cannot connect to mongreldb-server at {$db->getClient()->getUrl()}\n");
    exit(1);
}

echo "Connected to MongrelDB\n";

// Unique table name per run so re-running the example never collides with a
// leftover table from a previous (possibly failed) run.
$table = 'users_' . time();

try {
// Create a table
$db->createTable($table, [
    ['id' => 1, 'name' => 'id',    'ty' => 'int64',   'primary_key' => true,  'nullable' => false],
    ['id' => 2, 'name' => 'name',  'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
    ['id' => 3, 'name' => 'email', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
    ['id' => 4, 'name' => 'score', 'ty' => 'float64', 'primary_key' => false, 'nullable' => true],
]);

echo "Created table '{$table}'\n";

// Insert rows
$db->put($table, [1 => 1, 2 => 'Alice',   3 => 'alice@example.com',  4 => 95.5]);
$db->put($table, [1 => 2, 2 => 'Bob',     3 => 'bob@example.com',    4 => 82.0]);
$db->put($table, [1 => 3, 2 => 'Charlie', 3 => 'charlie@example.com', 4 => 78.3]);

echo "Inserted 3 rows\n";

// Query all
$rows = $db->query($table)->execute();
echo "All users: " . count($rows) . " rows\n";

// Query with range condition. Column 4 (score) is float64, so use range_f64
// (plain "range" expects an int64 column).
$highScorers = $db->query($table)
    ->where('range_f64', ['column' => 4, 'min' => 80.0, 'max' => 200.0, 'min_inclusive' => true, 'max_inclusive' => true])
    ->execute();
echo "High scorers (>=80): " . count($highScorers) . " rows\n";

// Upsert (update existing)
$db->upsert($table, [1 => 1, 2 => 'Alice', 3 => 'alice@example.com', 4 => 100.0], updateCells: [4 => 100.0]);
echo "Updated Alice's score to 100.0\n";

// Count
echo "Total users: " . $db->count($table) . "\n";

// Delete
$db->deleteByPk($table, 3);
echo "Deleted Charlie\n";
echo "Remaining users: " . $db->count($table) . "\n";
} finally {
    // Always clean up, even if something above threw.
    $db->dropTable($table);
    echo "Dropped table '{$table}'\n";
}
