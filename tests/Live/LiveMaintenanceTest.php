<?php

declare(strict_types=1);

/**
 * Live tests: maintenance (compaction), idempotency, and error mapping.
 *
 * Verifies the client and daemon agree on:
 *   - /compact and /tables/{t}/compact succeed
 *   - idempotency keys dedupe duplicate commits
 *   - HTTP error codes map to the right typed exceptions (404, 409)
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use Visorcraft\MongrelDB\Exceptions\ConstraintException;
use Visorcraft\MongrelDB\Exceptions\NotFoundException;

final class LiveMaintenanceTest extends LiveTestCase
{
    #[Test]
    public function compact_all_tables_succeeds(): void
    {
        $result = $this->db->compact();
        // The daemon returns compaction stats; just assert it didn't error.
        $this->assertIsArray($result);
    }

    #[Test]
    public function compact_single_table_succeeds(): void
    {
        $this->withFreshTable('php_live_compact', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->db->put('php_live_compact', [1 => 1]);

        $result = $this->db->compactTable('php_live_compact');
        $this->assertIsArray($result);
    }

    #[Test]
    public function idempotency_key_dedupes_duplicate_commit(): void
    {
        $this->withFreshTable('php_live_idem', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);

        $key = 'php-live-idem-' . uniqid();

        // First commit with the key.
        $txn1 = $this->db->beginTransaction();
        $txn1->put('php_live_idem', [1 => 1]);
        $txn1->commit(idempotencyKey: $key);

        $this->assertSame(1, $this->db->count('php_live_idem'));

        // Replay with the SAME key and SAME payload is deduped by the server
        // (0.59+ rejects same key + different payload).
        $txn2 = $this->db->beginTransaction();
        $txn2->put('php_live_idem', [1 => 1]);
        $txn2->commit(idempotencyKey: $key);

        // Still only one row; the second commit was a no-op replay.
        $this->assertSame(1, $this->db->count('php_live_idem'));
    }

    #[Test]
    public function count_on_missing_table_throws_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $this->db->count('php_live_does_not_exist_' . uniqid());
    }

    #[Test]
    public function duplicate_insert_with_unique_constraint_throws(): void
    {
        // kit_create_table doesn't auto-register a PK uniqueness constraint,
        // so declare one explicitly to exercise the 409 path.
        $this->db->getClient()->post('/kit/create_table', [
            'name' => 'php_live_uq',
            'columns' => [
                ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ],
            'constraints' => [
                'uniques' => [
                    ['id' => 1, 'name' => 'php_live_uq_id', 'columns' => [1]],
                ],
            ],
        ]);
        try {
            $this->db->put('php_live_uq', [1 => 1]);

            $this->expectException(ConstraintException::class);
            $this->db->put('php_live_uq', [1 => 1]); // duplicate -> conflict
        } finally {
            try { $this->db->dropTable('php_live_uq'); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
        }
    }
}
