<?php

declare(strict_types=1);

/**
 * Example: Authentication, users, roles, and permissions.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Visorcraft\MongrelDB\Database;

// Connect as admin (assuming daemon started with --auth-users)
$db = new Database('http://127.0.0.1:8453', username: 'admin', password: 'admin-pw');

// Create a table
$db->createTable('orders', [
    ['id' => 1, 'name' => 'id',     'ty' => 'int64',   'primary_key' => true,  'nullable' => false],
    ['id' => 2, 'name' => 'amount', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
]);

$db->put('orders', [1 => 1, 2 => 100.0]);
$db->put('orders', [1 => 2, 2 => 200.0]);

// Create a read-only user
$db->createUser('analyst', 'analyst-pw');
$db->createRole('read_only');
$db->grantPermission('read_only', 'select:orders');
$db->grantRole('analyst', 'read_only');

echo "Created user 'analyst' with read-only access to 'orders'\n";

// List users and roles
echo "Users: " . implode(', ', $db->users()) . "\n";
echo "Roles: " . implode(', ', $db->roles()) . "\n";

// Connect as the analyst
$analystDb = new Database('http://127.0.0.1:8453', username: 'analyst', password: 'analyst-pw');

// Analyst can read
$count = $analystDb->count('orders');
echo "Analyst sees {$count} orders\n";

// Analyst cannot write (PermissionDenied)
try {
    $analystDb->put('orders', [1 => 99, 2 => 999.0]);
    echo "BUG: analyst was able to insert!\n";
} catch (\Visorcraft\MongrelDB\Exceptions\AuthException $e) {
    echo "Correctly denied: analyst cannot insert\n";
}

// Verify credentials
echo "Verify analyst: " . ($db->verifyUser('analyst', 'analyst-pw') ? 'OK' : 'FAIL') . "\n";
echo "Verify wrong: " . ($db->verifyUser('analyst', 'wrong-pw') ? 'OK' : 'FAIL') . "\n";

// Cleanup
$db->dropTable('orders');
echo "Done\n";
