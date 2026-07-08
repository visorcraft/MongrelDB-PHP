<?php

declare(strict_types=1);

/**
 * Live tests: index-backed native query conditions.
 *
 * The native query conditions (bitmap_eq, fm_contains, ann) require
 * specialized indexes that aren't auto-built by /kit/create_table. These
 * tests create indexes via SQL `CREATE INDEX`, then query via /kit/query to
 * verify the column_id/lo/hi translation AND the index actually returns rows.
 *
 * Requires mongreldb-server >= 0.42.0 for the embedding(dim) type and
 * embedding-array input (bitmap and FM work on older versions).
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class LiveIndexQueryTest extends LiveTestCase
{
    #[Test]
    public function bitmap_eq_returns_matching_rows(): void
    {
        // Create the table via SQL so we can add a bitmap index.
        $this->db->sql("DROP TABLE IF EXISTS php_live_idx_bm");
        $this->db->sql('CREATE TABLE php_live_idx_bm (id BIGINT PRIMARY KEY, status VARCHAR(20))');
        $this->db->sql('CREATE INDEX bm_status ON php_live_idx_bm (status)');
        try {
            $this->db->sql("INSERT INTO php_live_idx_bm (id, status) VALUES (1,'active'),(2,'inactive'),(3,'active')");

            $rows = $this->db->query('php_live_idx_bm')
                ->where('bitmap_eq', ['column' => 2, 'value' => 'active'])
                ->execute();

            $this->assertCount(2, $rows);
        } finally {
            try { $this->db->sql('DROP TABLE php_live_idx_bm'); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
        }
    }

    #[Test]
    public function fm_contains_index_creation_succeeds(): void
    {
        // FM indexes can be built via SQL CREATE INDEX. The index builds
        // without error over existing data. (Kit /kit/query against an FM
        // index built via SQL CREATE INDEX returns empty today — a known
        // engine limitation; this test verifies the index creation path works.
        // The SQL LIKE path uses the FM index directly.)
        $this->db->sql("DROP TABLE IF EXISTS php_live_idx_fm");
        $this->db->sql('CREATE TABLE php_live_idx_fm (id BIGINT PRIMARY KEY, body TEXT)');
        try {
            $this->db->sql("INSERT INTO php_live_idx_fm (id, body) VALUES (1,'the quick brown fox'),(2,'a lazy dog'),(3,'quick red fox')");
            // CREATE INDEX ... USING fm_index should execute without error.
            $this->db->sql('CREATE INDEX fm_body ON php_live_idx_fm (body) USING fm_index');
            $this->expectNotToPerformAssertions();
        } finally {
            try { $this->db->sql('DROP TABLE php_live_idx_fm'); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
        }
    }

    #[Test]
    public function ann_embedding_table_and_index_creation(): void
    {
        // Requires >= 0.42.0 for embedding(dim) type + array input.
        // Verifies the embedding column can be declared via Kit with a real
        // dimension, populated via /kit/txn, and an ANN index built via SQL.
        // (Kit /kit/query against an ANN index built via SQL returns empty
        // today — a known engine limitation; this test verifies the creation
        // and data path works.)
        $this->withFreshTable('php_live_idx_ann', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'label', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
            ['id' => 3, 'name' => 'vec', 'ty' => 'embedding(8)', 'primary_key' => false, 'nullable' => false],
        ]);
        try {
            $this->db->put('php_live_idx_ann', [1 => 1, 2 => 'a', 3 => [1.0, 1.0, 1.0, 1.0, -1.0, -1.0, -1.0, -1.0]]);
            $this->db->put('php_live_idx_ann', [1 => 2, 2 => 'b', 3 => [-1.0, -1.0, -1.0, -1.0, 1.0, 1.0, 1.0, 1.0]]);
            // CREATE INDEX ... USING ann should execute without error.
            $this->db->sql('CREATE INDEX ann_vec ON php_live_idx_ann (vec) USING ann');
            $this->expectNotToPerformAssertions();
        } finally {
            try { $this->db->dropTable('php_live_idx_ann'); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
        }
    }
}
