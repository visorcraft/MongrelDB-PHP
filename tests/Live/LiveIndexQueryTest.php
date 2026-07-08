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
    public function fm_contains_returns_matching_rows(): void
    {
        $this->db->sql("DROP TABLE IF EXISTS php_live_idx_fm");
        $this->db->sql('CREATE TABLE php_live_idx_fm (id BIGINT PRIMARY KEY, body TEXT)');
        try {
            // Insert data FIRST, then build the index. FM indexes are built
            // at CREATE INDEX time over existing rows; inserts after index
            // creation may not be reflected.
            $this->db->sql("INSERT INTO php_live_idx_fm (id, body) VALUES (1,'the quick brown fox'),(2,'a lazy dog'),(3,'quick red fox')");
            $this->db->sql('CREATE INDEX fm_body ON php_live_idx_fm (body) USING fm_index');

            $rows = $this->db->query('php_live_idx_fm')
                ->where('fm_contains', ['column' => 2, 'pattern' => 'quick'])
                ->execute();

            $this->assertCount(2, $rows);
        } finally {
            try { $this->db->sql('DROP TABLE php_live_idx_fm'); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
        }
    }

    #[Test]
    public function ann_query_returns_nearest_neighbors(): void
    {
        // Requires >= 0.42.0 for embedding(dim) type + array input.
        // Create an embedding table via Kit with the dimension.
        $this->withFreshTable('php_live_idx_ann', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'label', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
            ['id' => 3, 'name' => 'vec', 'ty' => 'embedding(8)', 'primary_key' => false, 'nullable' => false],
        ]);
        try {
            // Insert rows FIRST (clearly-signed ±1.0 vectors for meaningful
            // binary quantization), then build the ANN index over the data.
            $this->db->put('php_live_idx_ann', [1 => 1, 2 => 'a', 3 => [1.0, 1.0, 1.0, 1.0, -1.0, -1.0, -1.0, -1.0]]);
            $this->db->put('php_live_idx_ann', [1 => 2, 2 => 'b', 3 => [-1.0, -1.0, -1.0, -1.0, 1.0, 1.0, 1.0, 1.0]]);
            $this->db->put('php_live_idx_ann', [1 => 3, 2 => 'c', 3 => [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0]]);
            $this->db->sql('CREATE INDEX ann_vec ON php_live_idx_ann (vec) USING ann');

            // Query nearest to [1,1,1,1,-1,-1,-1,-1] -> row 1 is the exact match.
            $rows = $this->db->query('php_live_idx_ann')
                ->where('ann', [
                    'column' => 3,
                    'query' => [1.0, 1.0, 1.0, 1.0, -1.0, -1.0, -1.0, -1.0],
                    'k' => 1,
                ])
                ->execute();

            $this->assertNotEmpty($rows, 'ANN query should return at least one row');
        } finally {
            try { $this->db->dropTable('php_live_idx_ann'); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
        }
    }
}
