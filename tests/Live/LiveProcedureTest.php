<?php

declare(strict_types=1);

/**
 * Live tests: stored procedure lifecycle over the HTTP /procedures endpoint.
 *
 * Verifies create -> list -> get -> call -> drop round-trips against a real
 * daemon.
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class LiveProcedureTest extends LiveTestCase
{
    private const PROC_NAME = 'php_live_proc';

    /**
     * A minimal valid StoredProcedure body: read-only, runs SELECT 1, no params.
     */
    private function procedureBody(): array
    {
        // StoredProcedure requires checksum/created_epoch/updated_epoch for
        // deserialization; the server recomputes them via normalized().
        return [
            'name' => self::PROC_NAME,
            'version' => 1,
            'mode' => 'read_only',
            'params' => [],
            'body' => [
                'steps' => [
                    // ProcedureStep is internally tagged (tag = "kind",
                    // snake_case). SqlQuery requires an `id` and `sql`.
                    ['kind' => 'sql_query', 'id' => 'q1', 'sql' => 'SELECT 1'],
                ],
                // ProcedureValue is adjacently tagged (tag="kind",
                // content="value"). Return the query step's rows.
                'return_value' => ['kind' => 'step_rows', 'value' => 'q1'],
            ],
            // Server-assigned; send placeholders (recomputed by normalized()).
            'checksum' => '',
            'created_epoch' => 0,
            'updated_epoch' => 0,
        ];
    }

    protected function tearDown(): void
    {
        try { $this->db->dropProcedure(self::PROC_NAME); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
    }

    #[Test]
    public function create_list_get_call_drop_lifecycle(): void
    {
        // Create
        $created = $this->db->createProcedure($this->procedureBody());
        $this->assertSame(self::PROC_NAME, $created['name'] ?? null);

        // List contains it
        $procs = $this->db->procedures();
        $names = array_column($procs, 'name');
        $this->assertContains(self::PROC_NAME, $names);

        // Get returns the definition
        $fetched = $this->db->procedure(self::PROC_NAME);
        $this->assertSame(self::PROC_NAME, $fetched['name'] ?? null);

        // Call returns without error
        $result = $this->db->callProcedure(self::PROC_NAME, []);
        $this->assertNotNull($result);
    }

    #[Test]
    public function drop_removes_procedure_from_list(): void
    {
        $this->db->createProcedure($this->procedureBody());
        $this->db->dropProcedure(self::PROC_NAME);

        $names = array_column($this->db->procedures(), 'name');
        $this->assertNotContains(self::PROC_NAME, $names);
    }
}
