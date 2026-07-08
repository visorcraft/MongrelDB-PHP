<?php

declare(strict_types=1);

/**
 * Live tests: stored procedure lifecycle over the HTTP /procedures endpoint.
 *
 * Verifies create -> list -> get -> call -> drop round-trips against a real
 * daemon. Uses a NativeQuery step (a PK lookup) because the HTTP call endpoint
 * runs via the core engine, which cannot execute SqlQuery steps.
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\Attributes\Test;

final class LiveProcedureTest extends LiveTestCase
{
    private const PROC_NAME = 'php_live_proc';
    private const TABLE_NAME = 'php_live_proc_tbl';

    /**
     * A minimal valid StoredProcedure body: read-only, native PK lookup.
     */
    private function procedureBody(): array
    {
        return [
            'name' => self::PROC_NAME,
            'version' => 1,
            'mode' => 'read_only',
            'params' => [],
            'body' => [
                'steps' => [
                    // NativeQuery: PK lookup (the core engine supports this
                    // via /procedures/{name}/call; SqlQuery requires the query
                    // layer and is rejected). ProcedureCondition is internally
                    // tagged (tag="kind"); the value is a ProcedureValue
                    // (adjacently tagged: tag="kind", content="value").
                    [
                        'kind' => 'native_query',
                        'id' => 'q1',
                        'table' => self::TABLE_NAME,
                        'conditions' => [
                            ['kind' => 'pk', 'value' => ['kind' => 'literal', 'value' => 1]],
                        ],
                    ],
                ],
                'return_value' => ['kind' => 'step_rows', 'value' => 'q1'],
            ],
            // Server-assigned; send placeholders (recomputed by normalized()).
            'checksum' => '',
            'created_epoch' => 0,
            'updated_epoch' => 0,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Create a table with one row for the procedure to query.
        $this->withFreshTable(self::TABLE_NAME, [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'label', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
        ]);
        $this->db->put(self::TABLE_NAME, [1 => 1, 2 => 'hello']);
    }

    protected function tearDown(): void
    {
        try { $this->db->dropProcedure(self::PROC_NAME); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
        try { $this->db->dropTable(self::TABLE_NAME); } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {}
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

        // Call returns a result (the matching row)
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
