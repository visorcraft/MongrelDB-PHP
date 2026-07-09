<?php

declare(strict_types=1);

/**
 * Example: SQL queries, recursive CTEs, and window functions.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Visorcraft\MongrelDB\Database;

$db = new Database('http://127.0.0.1:8453');

// Unique table names per run so re-running the example never collides with a
// leftover table from a previous (possibly failed) run. The CTAS below also
// creates a table, so make that unique too.
$suffix = time();
$employees = 'employees_' . $suffix;
$highEarners = 'high_earners_' . $suffix;

try {
// Setup
$db->createTable($employees, [
    ['id' => 1, 'name' => 'id',       'ty' => 'int64',   'primary_key' => true,  'nullable' => false],
    ['id' => 2, 'name' => 'name',     'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
    ['id' => 3, 'name' => 'salary',   'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
    ['id' => 4, 'name' => 'manager',  'ty' => 'int64',   'primary_key' => false, 'nullable' => true],
]);

// Insert org hierarchy
$db->put($employees, [1 => 1, 2 => 'CEO',       3 => 500000.0, 4 => null]);
$db->put($employees, [1 => 2, 2 => 'VP Eng',     3 => 300000.0, 4 => 1]);
$db->put($employees, [1 => 3, 2 => 'VP Sales',   3 => 280000.0, 4 => 1]);
$db->put($employees, [1 => 4, 2 => 'Senior Dev', 3 => 150000.0, 4 => 2]);
$db->put($employees, [1 => 5, 2 => 'Junior Dev', 3 => 90000.0,  4 => 4]);

// Basic SQL
$db->sql("SELECT count(*) FROM {$employees}");

// Recursive CTE: walk the org chart from CEO
$db->sql("WITH RECURSIVE org_chart(id, name, depth) AS " .
         "(SELECT id, name, 0 FROM {$employees} WHERE id = 1 " .
         "UNION ALL " .
         "SELECT e.id, e.name, oc.depth + 1 FROM {$employees} e JOIN org_chart oc ON e.manager = oc.id) " .
         "SELECT name, depth FROM org_chart ORDER BY depth");

// Window function: rank by salary
$db->sql("SELECT name, salary, RANK() OVER (ORDER BY salary DESC) AS rank FROM {$employees}");

// CTAS: create a high-earners table
$db->sql("CREATE TABLE {$highEarners} AS SELECT id, name, salary FROM {$employees} WHERE salary > 100000");
echo "Created {$highEarners} table\n";

// FTS ranking
$db->sql("SELECT name, mongreldb_fts_rank(name, 'Dev VP') AS score FROM {$employees} ORDER BY score DESC");
} finally {
    // Always clean up, even if something above threw.
    $db->dropTable($highEarners);
    $db->dropTable($employees);
}
echo "Done\n";
