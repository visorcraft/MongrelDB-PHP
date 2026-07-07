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

// Create a table
$db->createTable('users', [
    ['id' => 1, 'name' => 'id',    'ty' => 'int64',   'primary_key' => true,  'nullable' => false],
    ['id' => 2, 'name' => 'name',  'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
    ['id' => 3, 'name' => 'email', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
    ['id' => 4, 'name' => 'score', 'ty' => 'float64', 'primary_key' => false, 'nullable' => true],
]);

echo "Created table 'users'\n";

// Insert rows
$db->put('users', [1 => 1, 2 => 'Alice',   3 => 'alice@example.com',  4 => 95.5]);
$db->put('users', [1 => 2, 2 => 'Bob',     3 => 'bob@example.com',    4 => 82.0]);
$db->put('users', [1 => 3, 2 => 'Charlie', 3 => 'charlie@example.com', 4 => 78.3]);

echo "Inserted 3 rows\n";

// Query all
$rows = $db->query('users')->execute();
echo "All users: " . count($rows) . " rows\n";

// Query with range condition
$highScorers = $db->query('users')
    ->where('range', ['column' => 4, 'min' => 80.0])
    ->execute();
echo "High scorers (>80): " . count($highScorers) . " rows\n";

// Upsert (update existing)
$db->upsert('users', [1 => 1, 2 => 'Alice', 3 => 'alice@example.com', 4 => 100.0], updateCells: [4 => 100.0]);
echo "Updated Alice's score to 100.0\n";

// Count
echo "Total users: " . $db->count('users') . "\n";

// Delete
$db->deleteByPk('users', 3);
echo "Deleted Charlie\n";
echo "Remaining users: " . $db->count('users') . "\n";

// Cleanup
$db->dropTable('users');
echo "Dropped table 'users'\n";
