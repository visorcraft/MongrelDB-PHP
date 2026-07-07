<?php

declare(strict_types=1);

/**
 * Example: Batch transactions with idempotency.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\Exceptions\ConstraintException;

$db = new Database('http://127.0.0.1:8453');

// Setup
$db->createTable('accounts', [
    ['id' => 1, 'name' => 'id',      'ty' => 'int64',   'primary_key' => true,  'nullable' => false],
    ['id' => 2, 'name' => 'name',    'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
    ['id' => 3, 'name' => 'balance', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
]);

$db->put('accounts', [1 => 1, 2 => 'Checking', 3 => 1000.0]);
$db->put('accounts', [1 => 2, 2 => 'Savings',  3 => 5000.0]);

// Atomic batch: transfer $500 from Checking to Savings
$txn = $db->beginTransaction();
$txn->put('accounts', [1 => 1, 2 => 'Checking', 3 => 500.0]);   // overwrite with new balance
$txn->put('accounts', [1 => 2, 2 => 'Savings',  3 => 5500.0]);  // overwrite with new balance

try {
    $results = $txn->commit();
    echo "Transfer committed: " . count($results) . " operations\n";

    // Idempotent retry (same key) returns the original result
    $txn2 = $db->beginTransaction();
    $txn2->put('accounts', [1 => 1, 2 => 'Checking', 3 => 500.0]);
    $txn2->put('accounts', [1 => 2, 2 => 'Savings',  3 => 5500.0]);
    $txn2->commit(idempotencyKey: 'transfer-001');

    echo "Idempotent retry succeeded (no double-apply)\n";
} catch (ConstraintException $e) {
    echo "Transfer failed: {$e->errorCode} — {$e->getMessage()}\n";
    $txn->rollback();
}

// Cleanup
$db->dropTable('accounts');
