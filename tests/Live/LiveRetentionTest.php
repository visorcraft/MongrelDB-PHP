<?php

declare(strict_types=1);

/**
 * Live history-retention integration test.
 *
 * Exercises the history retention getters/setter and proves that a table
 * written after a retention bump remains readable at the current earliest
 * retained epoch via AS OF EPOCH.
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class LiveRetentionTest extends LiveTestCase
{
    #[Test]
    public function retention_getters_round_trip_and_as_of_epoch_query_succeeds(): void
    {
        $original = $this->db->historyRetentionEpochs();
        $this->assertGreaterThan(0, $original);

        try {
            // Use a tight window so the earliest retained epoch advances past
            // zero after only a few commits, making the AS OF EPOCH query valid.
            $set = $this->db->setHistoryRetentionEpochs(1);
            $this->assertSame(1, $set['history_retention_epochs'] ?? null);

            $tbl = 'php_retention_' . substr(md5(uniqid('', true)), 0, 8);
            $this->withFreshTable($tbl, [
                ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
                ['id' => 2, 'name' => 'name', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
            ]);
            $this->db->put($tbl, [1 => 1, 2 => 'Alice']);
            $this->db->put($tbl, [1 => 2, 2 => 'Bob']);

            // Read the floor after the writes. With retention=1 this is one
            // epoch before the latest commit, so the table exists and at least
            // the first inserted row is visible.
            $earliest = (int) $this->db->earliestRetainedEpoch();
            $this->assertGreaterThan(0, $earliest);

            $rows = $this->db->sql("SELECT id FROM {$tbl} AS OF EPOCH {$earliest}");
            $this->assertIsArray($rows);
            $this->assertGreaterThanOrEqual(1, count($rows));
        } finally {
            // Restore the original window so later tests start clean.
            $this->db->setHistoryRetentionEpochs($original);

            try {
                $this->db->dropTable($tbl);
            } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {
                // Table may already be gone; ignore.
            }
        }
    }
}
