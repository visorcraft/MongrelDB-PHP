<?php

declare(strict_types=1);

/**
 * Live integration tests against a real mongreldb-server daemon.
 *
 * Unlike the offline mock-transport tests, these exercise the full HTTP
 * round-trip and assert the daemon actually ACCEPTS the client's payloads and
 * returns correct data. They are the layer that catches contract drift between
 * the PHP client and the server (e.g. the column/column_id bug fixed in 0.1.1).
 *
 * They skip automatically when no daemon is reachable at MONGRELDB_URL (default
 * http://127.0.0.1:8453), so the offline suite is unaffected unless the env var
 * is set or a daemon is running locally.
 *
 * In CI, a dedicated job (see .github/workflows/ci.yml, the `live` job) installs
 * mongreldb-server, starts it on a temp dir, and runs this suite with
 * MONGRELDB_URL set.
 *
 * Run locally: MONGRELDB_URL=http://127.0.0.1:8453 vendor/bin/phpunit tests/live
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\Exceptions\MongrelDBException;

final class LiveIntegrationTest extends TestCase
{
    private Database $db;

    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('MONGRELDB_URL') ?: 'http://127.0.0.1:8453';
        $this->db = new Database($this->baseUrl);

        // Skip the whole suite if the daemon isn't reachable. This keeps the
        // offline CI matrix green while enabling the dedicated live job.
        if (!$this->daemonReachable()) {
            $this->markTestSkipped(
                "No mongreldb-server reachable at {$this->baseUrl} "
                . '(set MONGRELDB_URL or start a daemon to run live tests)'
            );
        }
    }

    /**
     * Reachability check that doesn't go through the typed client (so a
     * misconfigured client doesn't manifest as "daemon down").
     */
    private function daemonReachable(): bool
    {
        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_TIMEOUT, 2);
        curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5; the
        // handle is freed when $ch goes out of scope.
        unset($ch);

        return $code >= 200 && $code < 300;
    }

    /**
     * Drop and recreate a fresh table for a test, isolating each test's data.
     *
     * @param array<int,array<string,mixed>> $columns
     */
    private function withFreshTable(string $name, array $columns): void
    {
        try {
            $this->db->dropTable($name);
        } catch (MongrelDBException) {
            // Table didn't exist - that's fine.
        }
        $this->db->createTable($name, $columns);
    }

    // ── Health ──────────────────────────────────────────────────────────────

    #[Test]
    public function health_returns_true_against_real_daemon(): void
    {
        $this->assertTrue($this->db->health());
    }

    // ── CRUD round-trip ─────────────────────────────────────────────────────

    #[Test]
    public function put_and_count_round_trip(): void
    {
        $this->withFreshTable('php_live_orders', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
        ]);

        $this->assertSame(0, $this->db->count('php_live_orders'));

        $this->db->put('php_live_orders', [1 => 1, 2 => 99.5]);
        $this->db->put('php_live_orders', [1 => 2, 2 => 150.0]);

        $this->assertSame(2, $this->db->count('php_live_orders'));
    }

    #[Test]
    public function upsert_inserts_then_updates_on_conflict(): void
    {
        $this->withFreshTable('php_live_upsert', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
        ]);

        // First call inserts.
        $r1 = $this->db->upsert('php_live_upsert', [1 => 1, 2 => 10.0], updateCells: [2 => 10.0]);
        $this->assertSame(1, $this->db->count('php_live_upsert'));

        // Second call with the same PK hits the update path.
        $this->db->upsert('php_live_upsert', [1 => 1, 2 => 20.0], updateCells: [2 => 20.0]);
        $this->assertSame(1, $this->db->count('php_live_upsert'));
    }

    #[Test]
    public function delete_by_pk_removes_the_row(): void
    {
        $this->withFreshTable('php_live_del', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->db->put('php_live_del', [1 => 5]);
        $this->assertSame(1, $this->db->count('php_live_del'));

        $this->db->deleteByPk('php_live_del', 5);
        $this->assertSame(0, $this->db->count('php_live_del'));
    }

    // ── The endpoint that motivated this suite: /kit/query ──────────────────

    #[Test]
    public function native_range_query_is_accepted_and_returns_rows(): void
    {
        // This is the regression test for the column_id/lo/hi conformance bug.
        // Before the fix, the server would 400 on this condition.
        $this->withFreshTable('php_live_range', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'int64', 'primary_key' => false, 'nullable' => false],
        ]);
        $this->db->put('php_live_range', [1 => 1, 2 => 50]);
        $this->db->put('php_live_range', [1 => 2, 2 => 120]);
        $this->db->put('php_live_range', [1 => 3, 2 => 200]);

        // Friendly aliases - must be translated to column_id/lo/hi on the wire.
        $query = $this->db->query('php_live_range')
            ->where('range', ['column' => 2, 'min' => 100, 'max' => 150]);
        $rows = $query->execute();

        $this->assertCount(1, $rows);
        $this->assertFalse($query->truncated());
    }

    #[Test]
    public function native_bitmap_eq_query_returns_matching_row(): void
    {
        $this->withFreshTable('php_live_bitmap', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'category', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
        ]);
        $this->db->put('php_live_bitmap', [1 => 1, 2 => 'electronics']);
        $this->db->put('php_live_bitmap', [1 => 2, 2 => 'books']);

        $rows = $this->db->query('php_live_bitmap')
            ->where('bitmap_eq', ['column' => 2, 'value' => 'books'])
            ->execute();

        $this->assertCount(1, $rows);
    }

    #[Test]
    public function native_pk_query_returns_exactly_one_row(): void
    {
        $this->withFreshTable('php_live_pk', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->db->put('php_live_pk', [1 => 42]);
        $this->db->put('php_live_pk', [1 => 43]);

        $rows = $this->db->query('php_live_pk')
            ->where('pk', ['value' => 42])
            ->execute();

        $this->assertCount(1, $rows);
    }

    #[Test]
    public function projection_limits_returned_columns(): void
    {
        $this->withFreshTable('php_live_proj', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'int64', 'primary_key' => false, 'nullable' => false],
        ]);
        $this->db->put('php_live_proj', [1 => 1, 2 => 500]);

        $rows = $this->db->query('php_live_proj')
            ->where('pk', ['value' => 1])
            ->projection([1])   // only the id column
            ->execute();

        $this->assertCount(1, $rows);
        // Flat cells: projected to [col_id(1), value(1)] only.
        $this->assertSame([1, 1], $rows[0]['cells']);
    }

    #[Test]
    public function limit_truncates_result_and_sets_flag(): void
    {
        $this->withFreshTable('php_live_trunc', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        for ($i = 1; $i <= 10; $i++) {
            $this->db->put('php_live_trunc', [1 => $i]);
        }

        $query = $this->db->query('php_live_trunc')->limit(5);
        $rows = $query->execute();

        $this->assertCount(5, $rows);
        $this->assertTrue($query->truncated());
    }

    // ── Transactions ────────────────────────────────────────────────────────

    #[Test]
    public function batch_transaction_commits_atomically(): void
    {
        $this->withFreshTable('php_live_txn', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);

        $txn = $this->db->beginTransaction();
        $txn->put('php_live_txn', [1 => 1]);
        $txn->put('php_live_txn', [1 => 2]);
        $txn->put('php_live_txn', [1 => 3]);
        $results = $txn->commit();

        $this->assertSame(3, $this->db->count('php_live_txn'));
        $this->assertCount(3, $results);
    }

    #[Test]
    public function duplicate_pk_aborts_the_whole_batch(): void
    {
        $this->withFreshTable('php_live_txn_abort', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->db->put('php_live_txn_abort', [1 => 1]);

        $txn = $this->db->beginTransaction();
        $txn->put('php_live_txn_abort', [1 => 2]);
        $txn->put('php_live_txn_abort', [1 => 1]); // conflict - aborts the batch
        $txn->put('php_live_txn_abort', [1 => 3]);

        try {
            $txn->commit();
            $this->fail('Expected a ConstraintException for the duplicate PK');
        } catch (\Visorcraft\MongrelDB\Exceptions\ConstraintException $e) {
            $this->assertNotEmpty($e->errorCode);
        }

        // The whole batch must be rolled back: only the pre-existing row remains.
        $this->assertSame(1, $this->db->count('php_live_txn_abort'));
    }

    // ── SQL ─────────────────────────────────────────────────────────────────

    #[Test]
    public function sql_select_runs_without_error(): void
    {
        // /sql returns Arrow IPC; Database::sql() returns [] for non-JSON bodies.
        // We only assert it doesn't throw - the endpoint accepts the request.
        $this->expectNotToPerformAssertions();
        $this->db->sql('SELECT 1');
    }

    // ── Schema ──────────────────────────────────────────────────────────────

    #[Test]
    public function schema_lists_created_table(): void
    {
        $this->withFreshTable('php_live_schema', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);

        $schema = $this->db->schema();
        $this->assertArrayHasKey('php_live_schema', $schema);
    }

    #[Test]
    public function schema_for_returns_column_metadata(): void
    {
        $this->withFreshTable('php_live_schema_one', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
        ]);

        $desc = $this->db->schemaFor('php_live_schema_one');
        $this->assertSame('php_live_schema_one', $desc['name'] ?? null);
        $this->assertCount(2, $desc['columns'] ?? []);
    }
}
