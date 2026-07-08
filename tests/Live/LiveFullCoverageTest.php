<?php

declare(strict_types=1);

/**
 * Comprehensive live test: EVERY Database/QueryBuilder/Transaction/MongrelDB
 * method exercised against a real mongreldb-server daemon.
 *
 * Each test uses a UNIQUE table name to avoid cross-test contamination from
 * the daemon's schema/table-id caching.
 *
 * Methods that require auth are tested in LiveAuthTest.php.
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\MongrelDB;

final class LiveFullCoverageTest extends LiveTestCase
{
    /** Unique table name per test method to avoid cross-contamination. */
    private function uniqueTable(string $prefix = 'php_fc'): string
    {
        return $prefix . '_' . substr(md5(uniqid('', true)), 0, 8);
    }

    /** Create a standard 3-column test table and return its name. */
    private function makeTable(string $prefix = 'php_fc'): string
    {
        $name = $this->uniqueTable($prefix);
        $this->db->createTable($name, [
            ['id' => 1, 'name' => 'id',     'ty' => 'int64',  'primary_key' => true,  'nullable' => false],
            ['id' => 2, 'name' => 'name',   'ty' => 'varchar','primary_key' => false, 'nullable' => false],
            ['id' => 3, 'name' => 'amount', 'ty' => 'float64','primary_key' => false, 'nullable' => false],
        ]);

        return $name;
    }

    private function seed(string $table): void
    {
        $this->db->put($table, [1 => 1, 2 => 'Alice', 3 => 50.0]);
        $this->db->put($table, [1 => 2, 2 => 'Bob',   3 => 120.0]);
        $this->db->put($table, [1 => 3, 2 => 'Carol', 3 => 200.0]);
    }

    // ── MongrelDB (low-level client) ────────────────────────────────────────

    #[Test]
    public function mongreldb_get_health(): void
    {
        $resp = $this->db->getClient()->get('/health');
        $this->assertSame(200, $resp->status);
    }

    #[Test]
    public function mongreldb_post_sql(): void
    {
        $tbl = $this->makeTable();
        $resp = $this->db->getClient()->post('/sql', ['sql' => "INSERT INTO {$tbl} (id, name, amount) VALUES (99,'Z',1.0)"]);
        $this->assertSame(200, $resp->status);
    }

    #[Test]
    public function mongreldb_delete(): void
    {
        $tbl = $this->makeTable();
        $resp = $this->db->getClient()->delete("/tables/{$tbl}");
        $this->assertContains($resp->status, [200, 204]);
    }

    #[Test]
    public function mongreldb_health(): void
    {
        $this->assertTrue($this->db->getClient()->health());
    }

    #[Test]
    public function mongreldb_sql_raw(): void
    {
        $result = $this->db->getClient()->sqlRaw('SELECT 1');
        $this->assertIsString($result);
    }

    #[Test]
    public function mongreldb_get_url(): void
    {
        $this->assertSame($this->baseUrl, $this->db->getClient()->getUrl());
    }

    #[Test]
    public function mongreldb_request_error_mapping_404(): void
    {
        // Use /kit/schema/{nonexistent} which returns 404
        $this->expectException(\Visorcraft\MongrelDB\Exceptions\NotFoundException::class);
        $this->db->getClient()->get('/kit/schema/nonexistent_table_xyz');
    }

    // ── Database: health, tables, createTable, dropTable, count ────────────

    #[Test]
    public function database_health(): void
    {
        $this->assertTrue($this->db->health());
    }

    #[Test]
    public function database_tables(): void
    {
        $tbl = $this->makeTable();
        $tables = $this->db->tables();
        $this->assertContains($tbl, $tables);
    }

    #[Test]
    public function database_create_table_returns_id(): void
    {
        $tbl = $this->uniqueTable();
        $id = $this->db->createTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->assertIsInt($id);
        $this->db->dropTable($tbl);
    }

    #[Test]
    public function database_drop_table(): void
    {
        $tbl = $this->uniqueTable();
        $this->db->createTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->db->dropTable($tbl);
        $this->assertNotContains($tbl, $this->db->tables());
    }

    #[Test]
    public function database_count(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $this->assertSame(3, $this->db->count($tbl));
    }

    // ── Database: put, upsert, delete, deleteByPk ──────────────────────────

    #[Test]
    public function database_put_returns_result(): void
    {
        $tbl = $this->makeTable();
        $result = $this->db->put($tbl, [1 => 1, 2 => 'Alice', 3 => 50.0]);
        $this->assertIsArray($result);
        $this->assertSame(1, $this->db->count($tbl));
    }

    #[Test]
    public function database_put_with_idempotency_key(): void
    {
        $tbl = $this->makeTable();
        $key = 'idem-' . uniqid();
        $this->db->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0], idempotencyKey: $key);
        $this->db->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0], idempotencyKey: $key);
        $this->assertSame(1, $this->db->count($tbl));
    }

    #[Test]
    public function database_upsert_insert_then_update(): void
    {
        $tbl = $this->makeTable();
        $r1 = $this->db->upsert($tbl, [1 => 1, 2 => 'Alice', 3 => 10.0], updateCells: [3 => 10.0]);
        $this->assertSame(1, $this->db->count($tbl));
        $r2 = $this->db->upsert($tbl, [1 => 1, 2 => 'Alice', 3 => 20.0], updateCells: [3 => 20.0]);
        $this->assertSame(1, $this->db->count($tbl));
    }

    #[Test]
    public function database_upsert_do_nothing_without_update_cells(): void
    {
        $tbl = $this->makeTable();
        $this->db->upsert($tbl, [1 => 1, 2 => 'Alice', 3 => 10.0]);
        $this->assertSame(1, $this->db->count($tbl));
    }

    #[Test]
    public function database_delete_by_pk(): void
    {
        $tbl = $this->makeTable();
        $this->db->put($tbl, [1 => 5, 2 => 'X', 3 => 1.0]);
        $this->assertSame(1, $this->db->count($tbl));
        $this->db->deleteByPk($tbl, 5);
        $this->assertSame(0, $this->db->count($tbl));
    }

    #[Test]
    public function database_delete_by_pk_nonexistent(): void
    {
        $tbl = $this->makeTable();
        $this->db->deleteByPk($tbl, 999);
        $this->expectNotToPerformAssertions();
    }

    // ── Database: sql ──────────────────────────────────────────────────────

    #[Test]
    public function database_sql_insert(): void
    {
        $tbl = $this->makeTable();
        $result = $this->db->sql("INSERT INTO {$tbl} (id, name, amount) VALUES (10,'SQL',42.0)");
        $this->assertIsArray($result);
    }

    #[Test]
    public function database_sql_create_table_as_select(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $archive = $this->uniqueTable('php_ctas');
        try {
            $this->db->sql("CREATE TABLE {$archive} AS SELECT * FROM {$tbl} WHERE amount > 100");
            $this->assertGreaterThanOrEqual(1, $this->db->count($archive));
        } finally {
            try { $this->db->dropTable($archive); } catch (\Throwable) {}
        }
    }

    // ── Database: schema, schemaFor ────────────────────────────────────────

    #[Test]
    public function database_schema(): void
    {
        $tbl = $this->makeTable();
        $schema = $this->db->schema();
        $this->assertArrayHasKey($tbl, $schema);
    }

    #[Test]
    public function database_schema_for(): void
    {
        $tbl = $this->makeTable();
        $desc = $this->db->schemaFor($tbl);
        $this->assertArrayHasKey('columns', $desc);
        $this->assertCount(3, $desc['columns']);
    }

    // ── Database: procedures ───────────────────────────────────────────────

    #[Test]
    public function database_procedures_lifecycle(): void
    {
        $procName = 'php_full_proc_' . substr(md5(uniqid('', true)), 0, 8);
        $tblName = $this->makeTable('php_proc_tbl');
        // Overwrite with a simpler 2-column table for the procedure
        $this->db->dropTable($tblName);
        $this->db->createTable($tblName, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'v', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
        ]);
        $this->db->put($tblName, [1 => 1, 2 => 'test']);
        try {
            $created = $this->db->createProcedure([
                'name' => $procName, 'version' => 1, 'mode' => 'read_only', 'params' => [],
                'body' => [
                    'steps' => [[
                        'kind' => 'native_query', 'id' => 'q1', 'table' => $tblName,
                        'conditions' => [['kind' => 'pk', 'value' => ['kind' => 'literal', 'value' => ['Int64' => 1]]]],
                    ]],
                    'return_value' => ['kind' => 'step_rows', 'value' => 'q1'],
                ],
                'checksum' => '', 'created_epoch' => 0, 'updated_epoch' => 0,
            ]);
            $this->assertSame($procName, $created['name'] ?? null);
            $this->assertContains($procName, array_column($this->db->procedures(), 'name'));
            $fetched = $this->db->procedure($procName);
            $this->assertSame($procName, $fetched['name'] ?? null);
            $result = $this->db->callProcedure($procName, []);
            $this->assertNotNull($result);
            $this->db->dropProcedure($procName);
            $this->assertNotContains($procName, array_column($this->db->procedures(), 'name'));
        } finally {
            try { $this->db->dropProcedure($procName); } catch (\Throwable) {}
        }
    }

    // ── Database: compact, compactTable ────────────────────────────────────

    #[Test]
    public function database_compact(): void
    {
        $result = $this->db->compact();
        $this->assertIsArray($result);
    }

    #[Test]
    public function database_compact_table(): void
    {
        $tbl = $this->makeTable();
        $result = $this->db->compactTable($tbl);
        $this->assertIsArray($result);
    }

    #[Test]
    public function database_begin_transaction(): void
    {
        $txn = $this->db->beginTransaction();
        $this->assertInstanceOf(\Visorcraft\MongrelDB\Transaction::class, $txn);
    }

    // ── QueryBuilder: every condition type + projection + limit ────────────

    #[Test]
    public function query_builder_pk(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $cnt = $this->db->count($tbl);
        // Also try a raw /kit/query to see the exact response
        $raw = $this->db->getClient()->post('/kit/query', [
            'table' => $tbl,
            'conditions' => [['pk' => ['value' => 2]]],
        ]);
        $rawBody = $raw->body;
        $rows = $this->db->query($tbl)->where('pk', ['value' => 2])->execute();
        $this->assertCount(1, $rows, "pk query returned " . count($rows) . " rows (count=$cnt, table=$tbl, raw=$rawBody)");
    }

    #[Test]
    public function query_builder_range(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $rows = $this->db->query($tbl)
            ->where('range', ['column' => 3, 'min' => 100, 'max' => 250])
            ->execute();
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    #[Test]
    public function query_builder_projection(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $rows = $this->db->query($tbl)
            ->where('pk', ['value' => 1])
            ->projection([1])
            ->execute();
        $this->assertCount(1, $rows);
        $this->assertSame([1, 1], $rows[0]['cells']);
    }

    #[Test]
    public function query_builder_limit(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $q = $this->db->query($tbl)->limit(2);
        $rows = $q->execute();
        $this->assertCount(2, $rows);
        $this->assertTrue($q->truncated());
    }

    #[Test]
    public function query_builder_limit_not_truncated_when_under(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $q = $this->db->query($tbl)->limit(10);
        $q->execute();
        $this->assertFalse($q->truncated());
    }

    #[Test]
    public function query_builder_build_payload(): void
    {
        $tbl = $this->makeTable();
        $q = $this->db->query($tbl)
            ->where('pk', ['value' => 1])
            ->projection([1, 2])
            ->limit(5);
        $payload = $q->build();
        $this->assertSame($tbl, $payload['table']);
        $this->assertSame([['pk' => ['value' => 1]]], $payload['conditions']);
        $this->assertSame([1, 2], $payload['projection']);
        $this->assertSame(5, $payload['limit']);
    }

    #[Test]
    public function query_builder_bitmap_eq(): void
    {
        $tbl = $this->uniqueTable('php_bm');
        $this->db->sql("CREATE TABLE {$tbl} (id BIGINT PRIMARY KEY, status VARCHAR(20))");
        try {
            $this->db->sql("INSERT INTO {$tbl} (id, status) VALUES (1,'active'),(2,'inactive'),(3,'active')");
            $this->db->sql("CREATE INDEX bm_status ON {$tbl} (status)");
            $rows = $this->db->query($tbl)
                ->where('bitmap_eq', ['column' => 2, 'value' => 'active'])
                ->execute();
            $this->assertGreaterThanOrEqual(1, count($rows));
        } finally {
            try { $this->db->sql("DROP TABLE {$tbl}"); } catch (\Throwable) {}
        }
    }

    #[Test]
    public function query_builder_multiple_conditions(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $rows = $this->db->query($tbl)
            ->where('range', ['column' => 3, 'min' => 100, 'max' => 250])
            ->where('pk', ['value' => 2])
            ->execute();
        $this->assertCount(1, $rows);
    }

    // ── Transaction: every method ──────────────────────────────────────────

    #[Test]
    public function transaction_put(): void
    {
        $tbl = $this->makeTable();
        $txn = $this->db->beginTransaction();
        $txn->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0]);
        $this->assertSame(1, $txn->count());
        $txn->commit();
        $this->assertSame(1, $this->db->count($tbl));
    }

    #[Test]
    public function transaction_put_with_returning(): void
    {
        $tbl = $this->makeTable();
        $txn = $this->db->beginTransaction();
        $txn->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0], returning: true);
        $results = $txn->commit();
        $this->assertCount(1, $results);
    }

    #[Test]
    public function transaction_upsert(): void
    {
        $tbl = $this->makeTable();
        $txn = $this->db->beginTransaction();
        $txn->upsert($tbl, [1 => 1, 2 => 'A', 3 => 1.0], updateCells: [3 => 1.0]);
        $results = $txn->commit();
        $this->assertCount(1, $results);
        $this->assertSame(1, $this->db->count($tbl));
    }

    #[Test]
    public function transaction_delete_by_pk(): void
    {
        $tbl = $this->makeTable();
        $this->db->put($tbl, [1 => 5, 2 => 'X', 3 => 1.0]);
        $txn = $this->db->beginTransaction();
        $txn->deleteByPk($tbl, 5);
        $txn->commit();
        $this->assertSame(0, $this->db->count($tbl));
    }

    #[Test]
    public function transaction_count(): void
    {
        $tbl = $this->makeTable();
        $txn = $this->db->beginTransaction();
        $txn->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0]);
        $txn->put($tbl, [1 => 2, 2 => 'B', 3 => 2.0]);
        $this->assertSame(2, $txn->count());
        $txn->rollback();
    }

    #[Test]
    public function transaction_rollback(): void
    {
        $tbl = $this->makeTable();
        $txn = $this->db->beginTransaction();
        $txn->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0]);
        $this->assertSame(1, $txn->count());
        $txn->rollback();
        $this->assertSame(0, $this->db->count($tbl));
    }

    #[Test]
    public function transaction_commit_with_idempotency_key(): void
    {
        $tbl = $this->makeTable();
        $key = 'txn-idem-' . uniqid();
        $txn1 = $this->db->beginTransaction();
        $txn1->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0]);
        $txn1->commit(idempotencyKey: $key);
        $this->assertSame(1, $this->db->count($tbl));

        $txn2 = $this->db->beginTransaction();
        $txn2->put($tbl, [1 => 2, 2 => 'B', 3 => 2.0]);
        $txn2->commit(idempotencyKey: $key);
        $this->assertSame(1, $this->db->count($tbl));
    }

    #[Test]
    public function transaction_mixed_ops(): void
    {
        // Use distinct PKs to avoid self-conflict within the batch
        $tbl = $this->makeTable();
        $this->db->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0]);
        $txn = $this->db->beginTransaction();
        $txn->put($tbl, [1 => 2, 2 => 'B', 3 => 2.0]);
        $txn->deleteByPk($tbl, 1);
        $this->assertSame(2, $txn->count());
        $results = $txn->commit();
        $this->assertCount(2, $results);
        $this->assertSame(1, $this->db->count($tbl));
    }

    #[Test]
    public function transaction_double_commit_throws(): void
    {
        $tbl = $this->makeTable();
        $txn = $this->db->beginTransaction();
        $txn->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0]);
        $txn->commit();
        $this->expectException(\LogicException::class);
        $txn->commit();
    }

    #[Test]
    public function transaction_rollback_after_commit_throws(): void
    {
        $tbl = $this->makeTable();
        $txn = $this->db->beginTransaction();
        $txn->put($tbl, [1 => 1, 2 => 'A', 3 => 1.0]);
        $txn->commit();
        $this->expectException(\LogicException::class);
        $txn->rollback();
    }

    #[Test]
    public function transaction_empty_commit(): void
    {
        $txn = $this->db->beginTransaction();
        $this->assertSame([], $txn->commit());
    }

    // ── Error handling ─────────────────────────────────────────────────────

    #[Test]
    public function error_not_found_exception(): void
    {
        $this->expectException(\Visorcraft\MongrelDB\Exceptions\NotFoundException::class);
        $this->db->count('table_that_does_not_exist_' . uniqid());
    }

    #[Test]
    public function error_constraint_exception(): void
    {
        $tbl = $this->uniqueTable('php_uq');
        $this->db->getClient()->post('/kit/create_table', [
            'name' => $tbl,
            'columns' => [['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false]],
            'constraints' => ['uniques' => [['id' => 1, 'name' => 'uq', 'columns' => [1]]]],
        ]);
        try {
            $this->db->put($tbl, [1 => 1]);
            $this->expectException(\Visorcraft\MongrelDB\Exceptions\ConstraintException::class);
            $this->db->put($tbl, [1 => 1]);
        } finally {
            try { $this->db->dropTable($tbl); } catch (\Throwable) {}
        }
    }

    #[Test]
    public function error_constraint_exception_has_error_code(): void
    {
        $tbl = $this->uniqueTable('php_uq2');
        $this->db->getClient()->post('/kit/create_table', [
            'name' => $tbl,
            'columns' => [['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false]],
            'constraints' => ['uniques' => [['id' => 1, 'name' => 'uq', 'columns' => [1]]]],
        ]);
        try {
            $this->db->put($tbl, [1 => 1]);
            try {
                $this->db->put($tbl, [1 => 1]);
            } catch (\Visorcraft\MongrelDB\Exceptions\ConstraintException $e) {
                $this->assertNotEmpty($e->errorCode);
            }
        } finally {
            try { $this->db->dropTable($tbl); } catch (\Throwable) {}
        }
    }

    // ── SQL ─────────────────────────────────────────────────────────────────

    #[Test]
    public function sql_recursive_cte(): void
    {
        $this->expectNotToPerformAssertions();
        $this->db->sql("WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n<5) SELECT n FROM r");
    }

    #[Test]
    public function sql_window_function(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $this->expectNotToPerformAssertions();
        $this->db->sql("SELECT id, ROW_NUMBER() OVER (ORDER BY amount DESC) FROM {$tbl}");
    }

    #[Test]
    public function sql_update(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $this->db->sql("UPDATE {$tbl} SET amount = 999.0 WHERE name = 'Alice'");
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function sql_delete(): void
    {
        $tbl = $this->makeTable();
        $this->seed($tbl);
        $this->db->sql("DELETE FROM {$tbl} WHERE name = 'Bob'");
        $this->assertSame(2, $this->db->count($tbl));
    }

    #[Test]
    public function sql_drop_if_exists(): void
    {
        $tbl = $this->uniqueTable('php_die');
        $this->db->sql("CREATE TABLE {$tbl} (id BIGINT PRIMARY KEY)");
        $this->db->sql("DROP TABLE IF EXISTS {$tbl}");
        $this->assertNotContains($tbl, $this->db->tables());
    }

    // ── createTable with foreign key ───────────────────────────────────────

    #[Test]
    public function create_table_with_foreign_key(): void
    {
        $parent = $this->uniqueTable('php_fkp');
        $child = $this->uniqueTable('php_fkc');
        try {
            $this->db->getClient()->post('/kit/create_table', [
                'name' => $parent,
                'columns' => [['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false]],
            ]);
            // The server's FkAction enum is PascalCase: Cascade, Restrict, SetNull
            $this->db->getClient()->post('/kit/create_table', [
                'name' => $child,
                'columns' => [['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
                              ['id' => 2, 'name' => 'parent_id', 'ty' => 'int64', 'primary_key' => false, 'nullable' => false]],
                'constraints' => ['foreign_keys' => [[
                    'id' => 1,
                    'name' => 'fk_child_parent',
                    'columns' => [2],
                    'ref_table' => $parent,
                    'ref_columns' => [1],
                    'on_delete' => 'Cascade',
                ]]],
            ]);
            $this->db->put($parent, [1 => 1]);
            $this->db->put($child, [1 => 10, 2 => 1]);
            // FK violation
            try {
                $this->db->put($child, [1 => 20, 2 => 999]);
                $this->fail('Expected ConstraintException for FK violation');
            } catch (\Visorcraft\MongrelDB\Exceptions\ConstraintException $e) {
                // Expected
            }
        } finally {
            try { $this->db->dropTable($child); } catch (\Throwable) {}
            try { $this->db->dropTable($parent); } catch (\Throwable) {}
        }
    }

    // ── Persistent sharing (PHP 8.5) ───────────────────────────────────────

    #[Test]
    public function persistent_sharing_enabled_request_succeeds(): void
    {
        if (!function_exists('curl_share_init_persistent')) {
            $this->markTestSkipped('Requires PHP 8.5+ persistent cURL API');
        }
        $transport = new \Visorcraft\MongrelDB\Transport\CurlTransport(persistentSharing: true);
        $client = new MongrelDB($this->baseUrl, transport: $transport);
        $resp = $client->get('/health');
        $this->assertSame(200, $resp->status);
    }
}
