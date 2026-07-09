<?php

declare(strict_types=1);

/**
 * Example: Batch transactions with idempotency.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\Exceptions\ConstraintException;

$db = new Database('http://127.0.0.1:8453');

// Unique table name per run so re-running the example never collides with a
// leftover table from a previous (possibly failed) run.
$table = 'accounts_' . time();

try {
// Setup
$db->createTable($table, [
    ['id' => 1, 'name' => 'id',      'ty' => 'int64',   'primary_key' => true,  'nullable' => false],
    ['id' => 2, 'name' => 'name',    'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
    ['id' => 3, 'name' => 'balance', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
]);

$db->put($table, [1 => 1, 2 => 'Checking', 3 => 1000.0]);
$db->put($table, [1 => 2, 2 => 'Savings',  3 => 5000.0]);

// Idempotency key unique per run, but reused for the initial commit AND the
// retry below so the retry replays the original result (no double-apply).
$idempotencyKey = 'example-txn-' . time();

// Atomic batch: transfer $500 from Checking to Savings
$txn = $db->beginTransaction();
$txn->put($table, [1 => 1, 2 => 'Checking', 3 => 500.0]);   // overwrite with new balance
$txn->put($table, [1 => 2, 2 => 'Savings',  3 => 5500.0]);  // overwrite with new balance

try {
    $results = $txn->commit(idempotencyKey: $idempotencyKey);
    echo "Transfer committed: " . count($results) . " operations\n";

    // Idempotent retry (same key) returns the original result
    $txn2 = $db->beginTransaction();
    $txn2->put($table, [1 => 1, 2 => 'Checking', 3 => 500.0]);
    $txn2->put($table, [1 => 2, 2 => 'Savings',  3 => 5500.0]);
    $txn2->commit(idempotencyKey: $idempotencyKey);

    echo "Idempotent retry succeeded (no double-apply)\n";
} catch (ConstraintException $e) {
    echo "Transfer failed: {$e->errorCode} - {$e->getMessage()}\n";
    $txn->rollback();
}
} finally {
    // Always clean up, even if something above threw.
    $db->dropTable($table);
}
