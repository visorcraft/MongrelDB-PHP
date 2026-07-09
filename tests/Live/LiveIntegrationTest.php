<?php

declare(strict_types=1);

/**
 * Original live integration tests against a real mongreldb-server daemon.
 *
 * These were the first live tests, now supplemented by LiveFullCoverageTest
 * which covers the complete API surface. This file retains key regression
 * tests (the range-query conformance bug that motivated the live suite).
 *
 * Uses unique table names per test to avoid cross-test contamination.
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class LiveIntegrationTest extends LiveTestCase
{
    private function ut(string $prefix = 'php_li'): string
    {
        return $prefix . '_' . substr(md5(uniqid('', true)), 0, 8);
    }

    /**
     * Extract a column value from a flat Kit row `cells` array
     * (shape: [col_id, value, col_id, value, ...]).
     */
    private function cellValue(array $cells, int $colId): mixed
    {
        for ($i = 0, $n = count($cells); $i < $n; $i += 2) {
            if ($cells[$i] === $colId) {
                return $cells[$i + 1] ?? null;
            }
        }

        return null;
    }

    #[Test]
    public function health_returns_true_against_real_daemon(): void
    {
        $this->assertTrue($this->db->health());
    }

    #[Test]
    public function put_and_count_round_trip(): void
    {
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
        ]);
        $this->assertSame(0, $this->db->count($tbl));
        $this->db->put($tbl, [1 => 1, 2 => 99.5]);
        $this->db->put($tbl, [1 => 2, 2 => 150.0]);
        $this->assertSame(2, $this->db->count($tbl));
    }

    #[Test]
    public function delete_by_pk_removes_the_row(): void
    {
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->db->put($tbl, [1 => 5]);
        $this->assertSame(1, $this->db->count($tbl));
        $this->db->deleteByPk($tbl, 5);
        $this->assertSame(0, $this->db->count($tbl));
    }

    #[Test]
    public function native_range_query_is_accepted_and_returns_rows(): void
    {
        // Regression test for the column_id/lo/hi conformance bug (v0.1.1).
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'int64', 'primary_key' => false, 'nullable' => false],
        ]);
        $this->db->put($tbl, [1 => 1, 2 => 50]);
        $this->db->put($tbl, [1 => 2, 2 => 120]);
        $this->db->put($tbl, [1 => 3, 2 => 200]);

        $query = $this->db->query($tbl)
            ->where('range', ['column' => 2, 'min' => 100, 'max' => 150]);
        $rows = $query->execute();

        // Only the row with amount=120 (pk=2) falls in [100, 150].
        $this->assertCount(1, $rows, 'range query should return exactly the matching row');
        $this->assertFalse($query->truncated());
        // Verify the PK values of returned rows match the filter range.
        foreach ($rows as $row) {
            $cells = $row['cells'] ?? [];
            // cells is a flat [col_id, value, ...] array; pk is column 1.
            $this->assertSame(2, $this->cellValue($cells, 1), 'returned row pk must be 2');
            $amount = $this->cellValue($cells, 2);
            $this->assertGreaterThanOrEqual(100, $amount);
            $this->assertLessThanOrEqual(150, $amount);
        }
    }

    #[Test]
    public function upsert_updates_cell_value_visible_on_pk_query(): void
    {
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
        ]);
        // Initial insert.
        $this->db->upsert($tbl, [1 => 7, 2 => 10.0], updateCells: [2 => 10.0]);
        // Update the amount cell on conflict.
        $this->db->upsert($tbl, [1 => 7, 2 => 99.5], updateCells: [2 => 99.5]);

        $rows = $this->db->query($tbl)->where('pk', ['value' => 7])->execute();
        $this->assertCount(1, $rows);
        $this->assertSame(7, $this->cellValue($rows[0]['cells'] ?? [], 1));
        $this->assertSame(99.5, $this->cellValue($rows[0]['cells'] ?? [], 2));
    }

    #[Test]
    public function native_pk_query_returns_exactly_one_row(): void
    {
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->db->put($tbl, [1 => 42]);
        $this->db->put($tbl, [1 => 43]);

        $rows = $this->db->query($tbl)->where('pk', ['value' => 42])->execute();
        $this->assertCount(1, $rows);
        // The returned row must carry the queried PK value.
        $this->assertSame(42, $this->cellValue($rows[0]['cells'] ?? [], 1));
    }

    #[Test]
    public function limit_truncates_result_and_sets_flag(): void
    {
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        for ($i = 1; $i <= 10; $i++) {
            $this->db->put($tbl, [1 => $i]);
        }
        $query = $this->db->query($tbl)->limit(5);
        $rows = $query->execute();
        $this->assertCount(5, $rows);
        $this->assertTrue($query->truncated());
    }

    #[Test]
    public function batch_transaction_commits_atomically(): void
    {
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $txn = $this->db->beginTransaction();
        $txn->put($tbl, [1 => 1]);
        $txn->put($tbl, [1 => 2]);
        $txn->put($tbl, [1 => 3]);
        $results = $txn->commit();
        $this->assertSame(3, $this->db->count($tbl));
        $this->assertCount(3, $results);
    }

    #[Test]
    public function sql_insert_increases_count_and_select_returns_rows(): void
    {
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'name', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
        ]);

        $this->assertSame(0, $this->db->count($tbl));
        $this->db->sql("INSERT INTO {$tbl} (id, name) VALUES (1,'Alice')");
        $this->assertSame(1, $this->db->count($tbl), 'count must increase after INSERT');

        // JSON SQL mode returns row objects keyed by column name.
        $rows = $this->db->sql("SELECT id, name FROM {$tbl}");
        $this->assertCount(1, $rows, 'SELECT via JSON mode should return the inserted row');
        $this->assertSame(1, $rows[0]['id'] ?? null);
        $this->assertSame('Alice', $rows[0]['name'] ?? null);
    }

    #[Test]
    public function schema_lists_created_table(): void
    {
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $schema = $this->db->schema();
        $this->assertArrayHasKey($tbl, $schema);
    }

    #[Test]
    public function schema_for_returns_column_metadata(): void
    {
        $tbl = $this->ut();
        $this->withFreshTable($tbl, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
        ]);
        $desc = $this->db->schemaFor($tbl);
        $this->assertArrayHasKey('schema_id', $desc);
        $this->assertCount(2, $desc['columns'] ?? []);
        $this->assertTrue($desc['columns'][0]['primary_key'] ?? false);
    }
}
