<?php

declare(strict_types=1);

/**
 * Comprehensive live test: EVERY Database/QueryBuilder/Transaction/MongrelDB
 * method exercised against a real mongreldb-server daemon.
 *
 * This is the definitive client contract test. If a method is in the public
 * API, it has at least one test here that calls it against the daemon.
 *
 * Methods that require auth (--auth-token / --auth-users) are tested in
 * LiveAuthTest.php instead.
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\MongrelDB;

final class LiveFullCoverageTest extends LiveTestCase
{
    private string $table = 'php_full_cov';

    /** Standard test table: int64 PK + varchar + float64 */
    private function freshTestTable(?string $name = null): void
    {
        $name = $name ?? $this->table;
        $this->withFreshTable($name, [
            ['id' => 1, 'name' => 'id',     'ty' => 'int64',  'primary_key' => true,  'nullable' => false],
            ['id' => 2, 'name' => 'name',   'ty' => 'varchar','primary_key' => false, 'nullable' => false],
            ['id' => 3, 'name' => 'amount', 'ty' => 'float64','primary_key' => false, 'nullable' => false],
        ]);
    }

    private function seed(): void
    {
        $this->db->put($this->table, [1 => 1, 2 => 'Alice', 3 => 50.0]);
        $this->db->put($this->table, [1 => 2, 2 => 'Bob',   3 => 120.0]);
        $this->db->put($this->table, [1 => 3, 2 => 'Carol', 3 => 200.0]);
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
        // POST /sql with a body
        $this->freshTestTable();
        $resp = $this->db->getClient()->post('/sql', ['sql' => "INSERT INTO {$this->table} (id, name, amount) VALUES (99,'Z',1.0)"]);
        $this->assertSame(200, $resp->status);
    }

    #[Test]
    public function mongreldb_delete(): void
    {
        $this->freshTestTable();
        $resp = $this->db->getClient()->delete("/tables/{$this->table}");
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
        // sqlRaw returns the raw body bytes (Arrow IPC or empty)
        $result = $this->db->getClient()->sqlRaw('SELECT 1');
        $this->assertIsString($result);
    }

    #[Test]
    public function mongreldb_get_url(): void
    {
        $this->assertSame($this->baseUrl, $this->db->getClient()->getUrl());
    }

    #[Test]
    public function mongreldb_request_with_error_mapping(): void
    {
        // A 404 should throw NotFoundException
        $this->expectException(\Visorcraft\MongrelDB\Exceptions\NotFoundException::class);
        $this->db->getClient()->get('/tables/nonexistent_table_xyz');
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
        $this->freshTestTable();
        $tables = $this->db->tables();
        $this->assertContains($this->table, $tables);
    }

    #[Test]
    public function database_create_table_returns_id(): void
    {
        $name = 'php_create_' . uniqid();
        $id = $this->db->createTable($name, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->assertIsInt($id);
        $this->db->dropTable($name);
    }

    #[Test]
    public function database_drop_table(): void
    {
        $name = 'php_drop_' . uniqid();
        $this->db->createTable($name, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->db->dropTable($name);
        $this->assertNotContains($name, $this->db->tables());
    }

    #[Test]
    public function database_count(): void
    {
        $this->freshTestTable();
        $this->seed();
        $this->assertSame(3, $this->db->count($this->table));
    }

    // ── Database: put, upsert, delete, deleteByPk ──────────────────────────

    #[Test]
    public function database_put_returns_result(): void
    {
        $this->freshTestTable();
        $result = $this->db->put($this->table, [1 => 1, 2 => 'Alice', 3 => 50.0]);
        $this->assertIsArray($result);
        $this->assertSame(1, $this->db->count($this->table));
    }

    #[Test]
    public function database_put_with_idempotency_key(): void
    {
        $this->freshTestTable();
        $key = 'idem-' . uniqid();
        $this->db->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0], idempotencyKey: $key);
        // Duplicate put with same key should be deduped
        $this->db->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0], idempotencyKey: $key);
        $this->assertSame(1, $this->db->count($this->table));
    }

    #[Test]
    public function database_upsert_insert_then_update(): void
    {
        $this->freshTestTable();
        $r1 = $this->db->upsert($this->table, [1 => 1, 2 => 'Alice', 3 => 10.0], updateCells: [3 => 10.0]);
        $this->assertSame(1, $this->db->count($this->table));

        $r2 = $this->db->upsert($this->table, [1 => 1, 2 => 'Alice', 3 => 20.0], updateCells: [3 => 20.0]);
        $this->assertSame(1, $this->db->count($this->table));
    }

    #[Test]
    public function database_upsert_do_nothing_without_update_cells(): void
    {
        $this->freshTestTable();
        $this->db->upsert($this->table, [1 => 1, 2 => 'Alice', 3 => 10.0]);
        $this->assertSame(1, $this->db->count($this->table));
    }

    #[Test]
    public function database_delete_by_pk(): void
    {
        $this->freshTestTable();
        $this->db->put($this->table, [1 => 5, 2 => 'X', 3 => 1.0]);
        $this->assertSame(1, $this->db->count($this->table));
        $this->db->deleteByPk($this->table, 5);
        $this->assertSame(0, $this->db->count($this->table));
    }

    #[Test]
    public function database_delete_by_pk_nonexistent(): void
    {
        $this->freshTestTable();
        // Deleting a non-existent PK should not error (the server returns
        // 'not_found' per-op result, but the batch succeeds)
        $this->db->deleteByPk($this->table, 999);
        $this->expectNotToPerformAssertions();
    }

    // ── Database: sql ──────────────────────────────────────────────────────

    #[Test]
    public function database_sql_insert(): void
    {
        $this->freshTestTable();
        $result = $this->db->sql("INSERT INTO {$this->table} (id, name, amount) VALUES (10,'SQL',42.0)");
        // /sql returns Arrow IPC; Database::sql() returns [] for non-JSON
        $this->assertIsArray($result);
    }

    #[Test]
    public function database_sql_create_table_as_select(): void
    {
        $this->freshTestTable();
        $this->seed();
        $archive = 'php_ctas_' . uniqid();
        try {
            $this->db->sql("CREATE TABLE {$archive} AS SELECT * FROM {$this->table} WHERE amount > 100");
            $this->assertSame(1, $this->db->count($archive));
        } finally {
            try { $this->db->dropTable($archive); } catch (\Throwable) {}
        }
    }

    // ── Database: schema, schemaFor ────────────────────────────────────────

    #[Test]
    public function database_schema(): void
    {
        $this->freshTestTable();
        $schema = $this->db->schema();
        $this->assertArrayHasKey($this->table, $schema);
    }

    #[Test]
    public function database_schema_for(): void
    {
        $this->freshTestTable();
        $desc = $this->db->schemaFor($this->table);
        $this->assertArrayHasKey('columns', $desc);
        $this->assertCount(3, $desc['columns']);
    }

    // ── Database: procedures ───────────────────────────────────────────────

    #[Test]
    public function database_procedures_lifecycle(): void
    {
        $procName = 'php_full_proc_' . uniqid();
        $tblName = 'php_full_proc_tbl_' . uniqid();
        try {
            // Create a table for the procedure to query
            $this->withFreshTable($tblName, [
                ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
                ['id' => 2, 'name' => 'v', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
            ]);
            $this->db->put($tblName, [1 => 1, 2 => 'test']);

            // Create procedure
            $created = $this->db->createProcedure([
                'name' => $procName,
                'version' => 1,
                'mode' => 'read_only',
                'params' => [],
                'body' => [
                    'steps' => [[
                        'kind' => 'native_query',
                        'id' => 'q1',
                        'table' => $tblName,
                        'conditions' => [['kind' => 'pk', 'value' => ['kind' => 'literal', 'value' => ['Int64' => 1]]]],
                    ]],
                    'return_value' => ['kind' => 'step_rows', 'value' => 'q1'],
                ],
                'checksum' => '',
                'created_epoch' => 0,
                'updated_epoch' => 0,
            ]);
            $this->assertSame($procName, $created['name'] ?? null);

            // List
            $this->assertContains($procName, array_column($this->db->procedures(), 'name'));

            // Get
            $fetched = $this->db->procedure($procName);
            $this->assertSame($procName, $fetched['name'] ?? null);

            // Call
            $result = $this->db->callProcedure($procName, []);
            $this->assertNotNull($result);

            // Drop
            $this->db->dropProcedure($procName);
            $this->assertNotContains($procName, array_column($this->db->procedures(), 'name'));
        } finally {
            try { $this->db->dropProcedure($procName); } catch (\Throwable) {}
            try { $this->db->dropTable($tblName); } catch (\Throwable) {}
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
        $this->freshTestTable();
        $result = $this->db->compactTable($this->table);
        $this->assertIsArray($result);
    }

    // ── Database: beginTransaction ─────────────────────────────────────────

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
        $this->freshTestTable();
        $this->seed();
        $rows = $this->db->query($this->table)->where('pk', ['value' => 2])->execute();
        $this->assertCount(1, $rows);
    }

    #[Test]
    public function query_builder_range(): void
    {
        $this->freshTestTable();
        $this->seed();
        $rows = $this->db->query($this->table)
            ->where('range', ['column' => 3, 'min' => 100, 'max' => 250])
            ->execute();
        $this->assertCount(2, $rows); // Bob (120) + Carol (200)
    }

    #[Test]
    public function query_builder_projection(): void
    {
        $this->freshTestTable();
        $this->seed();
        $rows = $this->db->query($this->table)
            ->where('pk', ['value' => 1])
            ->projection([1])
            ->execute();
        $this->assertCount(1, $rows);
        // Only column 1 projected: cells = [1, 1]
        $this->assertSame([1, 1], $rows[0]['cells']);
    }

    #[Test]
    public function query_builder_limit(): void
    {
        $this->freshTestTable();
        $this->seed();
        $q = $this->db->query($this->table)->limit(2);
        $rows = $q->execute();
        $this->assertCount(2, $rows);
        $this->assertTrue($q->truncated());
    }

    #[Test]
    public function query_builder_limit_not_truncated_when_under(): void
    {
        $this->freshTestTable();
        $this->seed();
        $q = $this->db->query($this->table)->limit(10);
        $q->execute();
        $this->assertFalse($q->truncated());
    }

    #[Test]
    public function query_builder_build_payload(): void
    {
        $q = $this->db->query($this->table)
            ->where('pk', ['value' => 1])
            ->projection([1, 2])
            ->limit(5);
        $payload = $q->build();
        $this->assertSame($this->table, $payload['table']);
        $this->assertSame([['pk' => ['value' => 1]]], $payload['conditions']);
        $this->assertSame([1, 2], $payload['projection']);
        $this->assertSame(5, $payload['limit']);
    }

    // ── QueryBuilder: bitmap via SQL CREATE INDEX ──────────────────────────

    #[Test]
    public function query_builder_bitmap_eq(): void
    {
        $tbl = 'php_bm_' . uniqid();
        $this->db->sql("DROP TABLE IF EXISTS {$tbl}");
        $this->db->sql("CREATE TABLE {$tbl} (id BIGINT PRIMARY KEY, status VARCHAR(20))");
        try {
            $this->db->sql("INSERT INTO {$tbl} (id, status) VALUES (1,'active'),(2,'inactive'),(3,'active')");
            $this->db->sql("CREATE INDEX bm_status ON {$tbl} (status)");
            $rows = $this->db->query($tbl)
                ->where('bitmap_eq', ['column' => 2, 'value' => 'active'])
                ->execute();
            $this->assertCount(2, $rows);
        } finally {
            try { $this->db->sql("DROP TABLE {$tbl}"); } catch (\Throwable) {}
        }
    }

    // ── QueryBuilder: multiple conditions AND-ed ──────────────────────────

    #[Test]
    public function query_builder_multiple_conditions(): void
    {
        $this->freshTestTable();
        $this->seed();
        $rows = $this->db->query($this->table)
            ->where('range', ['column' => 3, 'min' => 100, 'max' => 250])
            ->where('pk', ['value' => 2]) // AND: only Bob
            ->execute();
        $this->assertCount(1, $rows);
    }

    // ── Transaction: every method ──────────────────────────────────────────

    #[Test]
    public function transaction_put(): void
    {
        $this->freshTestTable();
        $txn = $this->db->beginTransaction();
        $txn->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0]);
        $this->assertSame(1, $txn->count());
        $txn->commit();
        $this->assertSame(1, $this->db->count($this->table));
    }

    #[Test]
    public function transaction_put_with_returning(): void
    {
        $this->freshTestTable();
        $txn = $this->db->beginTransaction();
        $txn->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0], returning: true);
        $results = $txn->commit();
        $this->assertCount(1, $results);
    }

    #[Test]
    public function transaction_upsert(): void
    {
        $this->freshTestTable();
        $txn = $this->db->beginTransaction();
        $txn->upsert($this->table, [1 => 1, 2 => 'A', 3 => 1.0], updateCells: [3 => 1.0]);
        $results = $txn->commit();
        $this->assertCount(1, $results);
        $this->assertSame(1, $this->db->count($this->table));
    }

    #[Test]
    public function transaction_delete_by_pk(): void
    {
        $this->freshTestTable();
        $this->db->put($this->table, [1 => 5, 2 => 'X', 3 => 1.0]);
        $txn = $this->db->beginTransaction();
        $txn->deleteByPk($this->table, 5);
        $txn->commit();
        $this->assertSame(0, $this->db->count($this->table));
    }

    #[Test]
    public function transaction_count(): void
    {
        $this->freshTestTable();
        $txn = $this->db->beginTransaction();
        $txn->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0]);
        $txn->put($this->table, [1 => 2, 2 => 'B', 3 => 2.0]);
        $this->assertSame(2, $txn->count());
        $txn->rollback();
    }

    #[Test]
    public function transaction_rollback(): void
    {
        $this->freshTestTable();
        $txn = $this->db->beginTransaction();
        $txn->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0]);
        $this->assertSame(1, $txn->count());
        $txn->rollback();
        $this->assertSame(0, $this->db->count($this->table));
    }

    #[Test]
    public function transaction_commit_with_idempotency_key(): void
    {
        $this->freshTestTable();
        $key = 'txn-idem-' . uniqid();
        $txn1 = $this->db->beginTransaction();
        $txn1->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0]);
        $txn1->commit(idempotencyKey: $key);
        $this->assertSame(1, $this->db->count($this->table));

        // Duplicate commit with same key should be deduped
        $txn2 = $this->db->beginTransaction();
        $txn2->put($this->table, [1 => 2, 2 => 'B', 3 => 2.0]);
        $txn2->commit(idempotencyKey: $key);
        $this->assertSame(1, $this->db->count($this->table));
    }

    #[Test]
    public function transaction_mixed_ops(): void
    {
        $this->freshTestTable();
        $this->db->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0]);
        $txn = $this->db->beginTransaction();
        $txn->put($this->table, [1 => 2, 2 => 'B', 3 => 2.0]);
        $txn->upsert($this->table, [1 => 1, 2 => 'A', 3 => 10.0], updateCells: [3 => 10.0]);
        $txn->deleteByPk($this->table, 1);
        $this->assertSame(3, $txn->count());
        $results = $txn->commit();
        $this->assertCount(3, $results);
        // Row 1 deleted, row 2 inserted
        $this->assertSame(1, $this->db->count($this->table));
    }

    #[Test]
    public function transaction_double_commit_throws(): void
    {
        $this->freshTestTable();
        $txn = $this->db->beginTransaction();
        $txn->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0]);
        $txn->commit();

        $this->expectException(\LogicException::class);
        $txn->commit();
    }

    #[Test]
    public function transaction_rollback_after_commit_throws(): void
    {
        $this->freshTestTable();
        $txn = $this->db->beginTransaction();
        $txn->put($this->table, [1 => 1, 2 => 'A', 3 => 1.0]);
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

    // ── Error handling: every exception type ───────────────────────────────

    #[Test]
    public function error_not_found_exception(): void
    {
        $this->expectException(\Visorcraft\MongrelDB\Exceptions\NotFoundException::class);
        $this->db->count('table_that_does_not_exist_' . uniqid());
    }

    #[Test]
    public function error_constraint_exception(): void
    {
        // Create a table with an explicit unique constraint
        $tbl = 'php_uq_' . uniqid();
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
        $tbl = 'php_uq2_' . uniqid();
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

    // ── SQL advanced features ──────────────────────────────────────────────

    #[Test]
    public function sql_recursive_cte(): void
    {
        $this->expectNotToPerformAssertions();
        $this->db->sql("WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n<5) SELECT n FROM r");
    }

    #[Test]
    public function sql_window_function(): void
    {
        $this->freshTestTable();
        $this->seed();
        $this->expectNotToPerformAssertions();
        $this->db->sql("SELECT id, ROW_NUMBER() OVER (ORDER BY amount DESC) FROM {$this->table}");
    }

    #[Test]
    public function sql_update(): void
    {
        $this->freshTestTable();
        $this->seed();
        $this->db->sql("UPDATE {$this->table} SET amount = 999.0 WHERE name = 'Alice'");
        $rows = $this->db->query($this->table)->where('pk', ['value' => 1])->execute();
        // Verify the update took effect (cells[3] = amount = 999.0)
        $this->assertSame([1, 1, 2, 'Alice', 3, 999.0], $rows[0]['cells']);
    }

    #[Test]
    public function sql_delete(): void
    {
        $this->freshTestTable();
        $this->seed();
        $this->db->sql("DELETE FROM {$this->table} WHERE name = 'Bob'");
        $this->assertSame(2, $this->db->count($this->table));
    }

    #[Test]
    public function sql_drop_if_exists(): void
    {
        $tbl = 'php_die_' . uniqid();
        $this->db->sql("CREATE TABLE {$tbl} (id BIGINT PRIMARY KEY)");
        $this->db->sql("DROP TABLE IF EXISTS {$tbl}");
        $this->assertNotContains($tbl, $this->db->tables());
    }

    // ── createTable with constraints ───────────────────────────────────────

    #[Test]
    public function create_table_with_foreign_key(): void
    {
        $parent = 'php_fk_parent_' . uniqid();
        $child = 'php_fk_child_' . uniqid();
        try {
            $this->db->getClient()->post('/kit/create_table', [
                'name' => $parent,
                'columns' => [['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false]],
            ]);
            $this->db->getClient()->post('/kit/create_table', [
                'name' => $child,
                'columns' => [['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
                              ['id' => 2, 'name' => 'parent_id', 'ty' => 'int64', 'primary_key' => false, 'nullable' => false]],
                'constraints' => ['foreign_keys' => [[
                    'name' => 'fk_child_parent',
                    'columns' => [2],
                    'ref_table' => $parent,
                    'ref_columns' => [1],
                    'on_delete' => 'cascade',
                ]]],
            ]);
            // Insert into parent, then child referencing it
            $this->db->put($parent, [1 => 1]);
            $this->db->put($child, [1 => 10, 2 => 1]);

            // FK violation: child referencing nonexistent parent
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

    // ── Transport: persistent sharing (PHP 8.5) ────────────────────────────

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
