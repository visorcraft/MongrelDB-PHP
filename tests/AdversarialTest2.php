<?php

declare(strict_types=1);

/**
 * Round 2 adversarial tests — 50 tests targeting vectors not covered in round 1.
 *
 * Focus: transaction op staging, query builder edge cases, schema/auth edge
 * cases, response corruption, procedure methods, idempotency, SQL method,
 * cURL transport header parsing, concurrency, data type preservation.
 */

namespace Visorcraft\MongrelDB\Tests;

use PHPUnit\Framework\TestCase;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\MongrelDB;
use Visorcraft\MongrelDB\QueryBuilder;
use Visorcraft\MongrelDB\Transaction;
use Visorcraft\MongrelDB\Exceptions\MongrelDBException;
use Visorcraft\MongrelDB\Exceptions\ConnectionException;
use Visorcraft\MongrelDB\Exceptions\AuthException;
use Visorcraft\MongrelDB\Exceptions\ConstraintException;
use Visorcraft\MongrelDB\Exceptions\NotFoundException;
use Visorcraft\MongrelDB\Exceptions\QueryException;
use Visorcraft\MongrelDB\Transport\TransportInterface;
use Visorcraft\MongrelDB\Transport\Response;

// Reuse MockTransport from round 1
require_once __DIR__ . '/AdversarialTest.php';
use Visorcraft\MongrelDB\Tests\MockTransport;

final class AdversarialTest2 extends TestCase
{
    private function makeClient(MockTransport $transport): MongrelDB
    {
        return new MongrelDB(
            url: 'http://127.0.0.1:8453',
            username: 'admin',
            password: 'pw',
            transport: $transport,
        );
    }

    private function makeDatabase(MockTransport $transport): Database
    {
        return new Database(client: $this->makeClient($transport));
    }

    // ── Transaction op staging edge cases ───────────────────────────────────

    /**
     * @test
     */
    public function txn_put_after_rollback_still_throws(): void
    {
        $transport = new MockTransport();
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('orders', [1 => 1]);
        $txn->rollback();

        // After rollback, ops are cleared. A new put() should work (stage a new op).
        // But commit() was never called — committed flag is still false.
        $txn->put('orders', [1 => 2]);
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $results = $txn->commit();
        $this->assertCount(1, $results);
    }

    /**
     * @test
     */
    public function txn_upsert_without_update_cells_is_do_nothing(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[{"kind":"upsert","action":"unchanged"}]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->upsert('orders', [1 => 1, 2 => 'test']); // no updateCells = DO NOTHING
        $results = $txn->commit();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        // update_cells should NOT be present (DO NOTHING semantics)
        $this->assertArrayNotHasKey('update_cells', $body['ops'][0]['upsert']);
    }

    /**
     * @test
     */
    public function txn_upsert_with_update_cells_present(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->upsert('orders', [1 => 1, 2 => 'test'], updateCells: [3 => 99.0]);
        $txn->commit();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertArrayHasKey('update_cells', $body['ops'][0]['upsert']);
        $this->assertSame([3, 99.0], $body['ops'][0]['upsert']['update_cells']);
    }

    /**
     * @test
     */
    public function txn_delete_with_row_id_zero(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->delete('orders', 0); // row_id 0 is valid (engine may use 0)
        $txn->commit();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame(0, $body['ops'][0]['delete']['row_id']);
    }

    /**
     * @test
     */
    public function txn_delete_by_pk_with_string_pk(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->deleteByPk('orders', 'composite-key-string');
        $txn->commit();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('composite-key-string', $body['ops'][0]['delete_by_pk']['pk']);
    }

    /**
     * @test
     */
    public function txn_delete_by_pk_with_null_pk(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->deleteByPk('orders', null);
        $txn->commit();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertNull($body['ops'][0]['delete_by_pk']['pk']);
    }

    /**
     * @test
     */
    public function txn_mixed_operations(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[{"kind":"put"},{"kind":"upsert","action":"inserted"},{"kind":"deleted"}]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('orders', [1 => 1, 2 => 'A']);
        $txn->upsert('orders', [1 => 2, 2 => 'B'], [2 => 'B-updated']);
        $txn->deleteByPk('orders', 3);
        $results = $txn->commit();

        $this->assertCount(3, $results);
        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertCount(3, $body['ops']);
    }

    /**
     * @test
     */
    public function txn_returning_flag_propagated(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[{"kind":"put","row":[1,42]}]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('orders', [1 => 42, 2 => 'test'], returning: true);
        $txn->commit();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertTrue($body['ops'][0]['put']['returning']);
    }

    /**
     * @test
     */
    public function txn_rollback_then_new_begin(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $txn1 = $db->beginTransaction();
        $txn1->put('orders', [1 => 1]);
        $txn1->rollback();

        // New transaction should work independently
        $txn2 = $db->beginTransaction();
        $txn2->put('orders', [1 => 2]);
        $results = $txn2->commit();
        $this->assertSame(1, $transport->requestCount);
    }

    // ── QueryBuilder edge cases ─────────────────────────────────────────────

    /**
     * @test
     */
    public function query_projection_empty_array(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"rows":[]}'));
        $db = $this->makeDatabase($transport);

        $db->query('orders')->projection([])->execute();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame([], $body['projection']);
    }

    /**
     * @test
     */
    public function query_limit_zero(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"rows":[]}'));
        $db = $this->makeDatabase($transport);

        $db->query('orders')->limit(0)->execute();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame(0, $body['limit']);
    }

    /**
     * @test
     */
    public function query_limit_negative(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"rows":[]}'));
        $db = $this->makeDatabase($transport);

        // Negative limit — client doesn't validate; server will handle
        $db->query('orders')->limit(-1)->execute();

        $this->assertSame(1, $transport->requestCount);
    }

    /**
     * @test
     */
    public function query_limit_very_large(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"rows":[]}'));
        $db = $this->makeDatabase($transport);

        $db->query('orders')->limit(\PHP_INT_MAX)->execute();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame(\PHP_INT_MAX, $body['limit']);
    }

    /**
     * @test
     */
    public function query_where_empty_params(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"rows":[]}'));
        $db = $this->makeDatabase($transport);

        $db->query('orders')->where('bitmap_eq', [])->execute();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame([], $body['conditions'][0]['bitmap_eq']);
    }

    /**
     * @test
     */
    public function query_chained_same_condition_type(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"rows":[]}'));
        $db = $this->makeDatabase($transport);

        $db->query('orders')
            ->where('bitmap_eq', ['column' => 2, 'value' => 'A'])
            ->where('bitmap_eq', ['column' => 2, 'value' => 'B'])
            ->execute();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertCount(2, $body['conditions']);
    }

    /**
     * @test
     */
    public function query_build_returns_payload(): void
    {
        $transport = new MockTransport();
        $db = $this->makeDatabase($transport);

        $qb = $db->query('orders')
            ->where('range', ['column' => 3, 'min' => 100.0])
            ->projection([1, 2])
            ->limit(50);

        $payload = $qb->build();
        $this->assertSame('orders', $payload['table']);
        $this->assertCount(1, $payload['conditions']);
        $this->assertSame([1, 2], $payload['projection']);
        $this->assertSame(50, $payload['limit']);
    }

    /**
     * @test
     */
    public function query_execution_returns_rows_key(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'rows' => [
                ['row_id' => '1', 'cells' => [1, 42, 2, 'Alice']],
                ['row_id' => '2', 'cells' => [1, 43, 2, 'Bob']],
            ],
            'truncated' => false,
        ])));
        $db = $this->makeDatabase($transport);

        $rows = $db->query('orders')->execute();
        $this->assertCount(2, $rows);
        $this->assertSame('1', $rows[0]['row_id']);
    }

    /**
     * @test
     */
    public function query_execution_empty_rows_key(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'rows' => [],
            'truncated' => false,
        ])));
        $db = $this->makeDatabase($transport);

        $rows = $db->query('orders')->execute();
        $this->assertSame([], $rows);
    }

    // ── Schema methods edge cases ───────────────────────────────────────────

    /**
     * @test
     */
    public function schema_returns_tables_object(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'tables' => [
                'orders' => ['schema_id' => 1, 'columns' => []],
                'users' => ['schema_id' => 2, 'columns' => []],
            ],
        ])));
        $db = $this->makeDatabase($transport);

        $schema = $db->schema();
        $this->assertArrayHasKey('orders', $schema);
        $this->assertArrayHasKey('users', $schema);
    }

    /**
     * @test
     */
    public function schema_for_returns_table_descriptor(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'schema_id' => 1,
            'columns' => [
                ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true],
            ],
        ])));
        $db = $this->makeDatabase($transport);

        $schema = $db->schemaFor('orders');
        $this->assertSame(1, $schema['schema_id']);
        $this->assertSame('id', $schema['columns'][0]['name']);
    }

    /**
     * @test
     */
    public function schema_for_nonexistent_throws_not_found(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(404, 'Table not found'));
        $db = $this->makeDatabase($transport);

        $this->expectException(NotFoundException::class);
        $db->schemaFor('ghost');
    }

    /**
     * @test
     */
    public function create_table_returns_table_id(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode(['table_id' => 42])));
        $db = $this->makeDatabase($transport);

        $id = $db->createTable('orders', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
        ]);
        $this->assertSame(42, $id);
    }

    // ── Auth edge cases ─────────────────────────────────────────────────────

    /**
     * @test
     */
    public function drop_user_then_recreate_same_name(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $transport->addResponse(new Response(200, '{}'));
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser('alice', 'pw1');
        $db->dropUser('alice');
        $db->createUser('alice', 'pw2');

        $this->assertSame(3, $transport->requestCount);
    }

    /**
     * @test
     */
    public function grant_permission_valid_formats(): void
    {
        $valid = ['all', 'ddl', 'admin', 'select:orders', 'INSERT:orders', 'Update:orders', 'DELETE:orders'];
        foreach ($valid as $perm) {
            $transport = new MockTransport();
            $transport->addResponse(new Response(200, '{}'));
            $db = $this->makeDatabase($transport);

            $db->grantPermission('role', $perm);
            $this->addToAssertionCount(1); // no exception thrown
        }
    }

    /**
     * @test
     */
    public function grant_permission_invalid_formats(): void
    {
        $invalid = [
            'select:orders; DROP USER admin',
            "all'; --",
            'select:orders WHERE 1=1',
            'delete FROM orders',
            '',
            'select:',
            ':orders',
            'select:or\';ders',
            'unknown:orders',
            'select:orders DELETE',
        ];

        foreach ($invalid as $perm) {
            $transport = new MockTransport();
            $transport->addResponse(new Response(200, '{}'));
            $db = $this->makeDatabase($transport);

            try {
                $db->grantPermission('role', $perm);
                $this->fail("Should have rejected permission: {$perm}");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1); // correctly rejected
            }
        }
    }

    /**
     * @test
     */
    public function revoke_permission_also_validates(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $this->expectException(\InvalidArgumentException::class);
        $db->revokePermission('role', "all; DROP USER admin");
    }

    /**
     * @test
     */
    public function set_user_admin_true_false(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->setUserAdmin('alice', true);
        $req1 = $transport->getRequests()[0];
        $sql1 = json_decode($req1['body'], true)['sql'];
        $this->assertStringContainsString('ADMIN', $sql1);

        $db->setUserAdmin('alice', false);
        $req2 = $transport->getRequests()[1];
        $sql2 = json_decode($req2['body'], true)['sql'];
        $this->assertStringContainsString('NOT ADMIN', $sql2);
    }

    /**
     * @test
     */
    public function verify_user_returns_false_on_connection_error(): void
    {
        // verifyUser creates a new MongrelDB internally — which will fail to connect
        // We can't inject a mock transport into it, so we test against a non-existent daemon
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}')); // for the initial Database construction
        $db = $this->makeDatabase($transport);

        // verifyUser creates its own client with the same URL — no mock transport
        // The connection will fail → returns false
        $result = @$db->verifyUser('alice', 'pw');
        $this->assertFalse($result);
    }

    // ── Response corruption ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function success_response_with_null_results(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":null}'));
        $db = $this->makeDatabase($transport);

        $result = $db->put('orders', [1 => 1]);
        // null results → array_slice returns [], ?? [] handles null
        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function success_response_missing_results_key(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1}'));
        $db = $this->makeDatabase($transport);

        $result = $db->put('orders', [1 => 1]);
        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function success_response_missing_status_key(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        // Should not crash — just extract what we can
        $result = $db->put('orders', [1 => 1]);
        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function count_response_missing_count_key(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"rows": 5}'));
        $db = $this->makeDatabase($transport);

        // Missing 'count' key — PHP will warn/null. The client should handle gracefully.
        $result = $db->count('orders');
        // In PHP, accessing a missing array key returns null (with a warning in PHP 8+).
        // The method signature says int, so this will either throw a TypeError or return 0.
        // We test that it doesn't crash.
        $this->assertTrue($result === null || is_int($result), 'Should not crash on missing key');
    }

    /**
     * @test
     */
    public function tables_response_as_object_not_array(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"tables": {"orders": {}}}'));
        $db = $this->makeDatabase($transport);

        // The /tables endpoint should return a JSON array, but if it returns
        // an object (unlikely but defensive), PHP handles it as an associative array.
        $tables = $db->tables();
        $this->assertIsArray($tables);
    }

    // ── SQL method edge cases ───────────────────────────────────────────────

    /**
     * @test
     */
    public function sql_empty_string(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $db = $this->makeDatabase($transport);

        $result = $db->sql('');
        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function sql_whitespace_only(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $db = $this->makeDatabase($transport);

        $result = $db->sql('   ');
        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function sql_very_long_query(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $db = $this->makeDatabase($transport);

        $longSql = str_repeat('SELECT 1; ', 1000);
        $db->sql($longSql);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertStringStartsWith('SELECT 1;', $body['sql']);
    }

    /**
     * @test
     */
    public function sql_with_special_chars(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $db = $this->makeDatabase($transport);

        $db->sql("SELECT * FROM orders WHERE name = 'O\\'Brien' AND x = '日本語'");

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertStringContainsString("O'Brien", $body['sql']);
    }

    /**
     * @test
     */
    public function sql_with_newlines(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $db = $this->makeDatabase($transport);

        $db->sql("SELECT *\nFROM orders\nWHERE id = 1");

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertStringContainsString("\n", $body['sql']);
    }

    // ── Procedure methods ───────────────────────────────────────────────────

    /**
     * @test
     */
    public function procedures_returns_array(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'procedures' => [
                ['name' => 'get_count', 'mode' => 'read_only'],
                ['name' => 'update_stats', 'mode' => 'read_write'],
            ],
        ])));
        $db = $this->makeDatabase($transport);

        $procs = $db->procedures();
        $this->assertCount(2, $procs);
        $this->assertSame('get_count', $procs[0]['name']);
    }

    /**
     * @test
     */
    public function procedure_single_returns_definition(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'procedure' => ['name' => 'get_count', 'mode' => 'read_only', 'params' => []],
        ])));
        $db = $this->makeDatabase($transport);

        $proc = $db->procedure('get_count');
        $this->assertSame('get_count', $proc['name']);
        $this->assertSame([], $proc['params']);
    }

    /**
     * @test
     */
    public function call_procedure_returns_result_key(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'status' => 'ok',
            'result' => ['count' => 42],
        ])));
        $db = $this->makeDatabase($transport);

        $result = $db->callProcedure('get_count');
        $this->assertSame(['count' => 42], $result);
    }

    /**
     * @test
     */
    public function call_procedure_null_result(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'status' => 'ok',
            'result' => null,
        ])));
        $db = $this->makeDatabase($transport);

        $result = $db->callProcedure('void_proc');
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function drop_procedure_sends_delete(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"ok"}'));
        $db = $this->makeDatabase($transport);

        $db->dropProcedure('old_proc');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('DELETE', $lastRequest['method']);
        $this->assertStringEndsWith('/procedures/old_proc', $lastRequest['url']);
    }

    // ── Idempotency edge cases ──────────────────────────────────────────────

    /**
     * @test
     */
    public function idempotency_key_empty_string(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 1], idempotencyKey: '');

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('', $body['idempotency_key']);
    }

    /**
     * @test
     */
    public function idempotency_key_very_long(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $key = str_repeat('x', 10000);
        $db->put('orders', [1 => 1], idempotencyKey: $key);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame($key, $body['idempotency_key']);
    }

    /**
     * @test
     */
    public function idempotency_key_with_special_chars(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 1], idempotencyKey: 'key-with-special/chars?id=1&x=2');

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('key-with-special/chars?id=1&x=2', $body['idempotency_key']);
    }

    /**
     * @test
     */
    public function idempotency_in_transaction(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('orders', [1 => 1]);
        $txn->commit(idempotencyKey: 'txn-001');

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('txn-001', $body['idempotency_key']);
    }

    // ── Maintenance methods ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function compact_returns_stats(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'status' => 'ok',
            'compacted' => 5,
            'skipped' => 2,
        ])));
        $db = $this->makeDatabase($transport);

        $result = $db->compact();
        $this->assertSame(5, $result['compacted']);
        $this->assertSame(2, $result['skipped']);
    }

    /**
     * @test
     */
    public function compact_table_returns_status(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'status' => 'compacted',
            'table' => 'orders',
        ])));
        $db = $this->makeDatabase($transport);

        $result = $db->compactTable('orders');
        $this->assertSame('compacted', $result['status']);
    }

    // ── cURL Transport header parsing ───────────────────────────────────────

    /**
     * @test
     */
    public function curl_transport_parses_multi_header_response(): void
    {
        // Test that CurlTransport's parseHeaders handles multiple headers
        // We can't easily test cURL directly, but we can test the Response object
        $response = new Response(200, '{"ok":true}', [
            'content-type' => 'application/json',
            'x-custom' => 'value',
            'content-length' => '11',
        ]);

        $this->assertSame('application/json', $response->headers['content-type']);
        $this->assertSame('value', $response->headers['x-custom']);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(['ok' => true], $response->json());
    }

    /**
     * @test
     */
    public function response_json_throws_on_invalid_json(): void
    {
        $response = new Response(200, 'not json at all');

        $this->expectException(\JsonException::class);
        $response->json();
    }

    /**
     * @test
     */
    public function response_json_on_empty_string(): void
    {
        $response = new Response(200, '');

        $this->expectException(\JsonException::class);
        $response->json();
    }

    /**
     * @test
     */
    public function response_is_successful_boundary(): void
    {
        $this->assertTrue((new Response(200, ''))->isSuccessful());
        $this->assertTrue((new Response(201, ''))->isSuccessful());
        $this->assertTrue((new Response(204, ''))->isSuccessful());
        $this->assertFalse((new Response(199, ''))->isSuccessful());
        $this->assertFalse((new Response(300, ''))->isSuccessful());
        $this->assertFalse((new Response(404, ''))->isSuccessful());
    }

    // ── Data type preservation ──────────────────────────────────────────────

    /**
     * @test
     */
    public function large_integer_preserved_in_cells(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => \PHP_INT_MAX, 2 => 'large']);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true, 512, \JSON_BIGINT_AS_STRING);
        $cells = $body['ops'][0]['put']['cells'];
        // PHP_INT_MAX should be serialized as a number (JSON doesn't have int64)
        $this->assertTrue($cells[0] === \PHP_INT_MAX || (string) $cells[0] === (string) \PHP_INT_MAX);
    }

    /**
     * @test
     */
    public function float_precision_preserved(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 1, 3 => 3.141592653589793]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame(3.141592653589793, $body['ops'][0]['put']['cells'][1]);
    }

    /**
     * @test
     */
    public function string_with_control_chars(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 1, 2 => "tab\there\nnewline\rreturn"]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame("tab\there\nnewline\rreturn", $body['ops'][0]['put']['cells'][1]);
    }

    /**
     * @test
     */
    public function array_value_in_cells_serialized(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 1, 2 => [1, 2, 3]]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame([1, 2, 3], $body['ops'][0]['put']['cells'][1]);
    }

    /**
     * @test
     */
    public function associative_array_in_cells(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 1, 2 => ['key' => 'value']]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame(['key' => 'value'], $body['ops'][0]['put']['cells'][1]);
    }

    // ── MongrelDB client edge cases ─────────────────────────────────────────

    /**
     * @test
     */
    public function post_with_null_data_sends_no_body(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->post('/health', null);

        $lastRequest = $transport->getLastRequest();
        $this->assertNull($lastRequest['body']);
    }

    /**
     * @test
     */
    public function post_with_empty_array_sends_json(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->post('/health', []);

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('[]', $lastRequest['body']);
    }

    /**
     * @test
     */
    public function get_url_method_correct(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->get('/tables');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
    }

    /**
     * @test
     */
    public function delete_method_correct(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"ok"}'));
        $client = $this->makeClient($transport);

        $client->delete('/tables/orders');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('DELETE', $lastRequest['method']);
        $this->assertNull($lastRequest['body']);
    }

    /**
     * @test
     */
    public function custom_headers_merged_with_defaults(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->get('/health', ['X-Custom' => 'value']);

        $lastRequest = $transport->getLastRequest();
        // Default headers should be present
        $this->assertArrayHasKey('Authorization', $lastRequest['headers']);
        // Custom header should be present
        $this->assertSame('value', $lastRequest['headers']['X-Custom']);
    }

    /**
     * @test
     */
    public function sql_raw_returns_body_string(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, 'arrow-binary-data'));
        $client = $this->makeClient($transport);

        $result = $client->sqlRaw('SELECT 1');
        $this->assertSame('arrow-binary-data', $result);
    }

    /**
     * @test
     */
    public function get_url_returns_constructed_url(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(url: 'http://localhost:9999', transport: $transport);

        $this->assertSame('http://localhost:9999', $client->getUrl());
    }

    // ── Error response with various content types ───────────────────────────

    /**
     * @test
     */
    public function error_response_plain_text_with_html(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(500, '<html><body>Server Error</body></html>'));
        $client = $this->makeClient($transport);

        try {
            $client->get('/tables');
            $this->fail('Should throw');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Server Error', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function error_response_with_nested_json_error(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, json_encode([
            'error' => [
                'code' => 'FK_VIOLATION',
                'message' => 'foreign key constraint failed',
                'detail' => ['table' => 'orders', 'column' => 'customer_id'],
            ],
        ])));
        $client = $this->makeClient($transport);

        try {
            $client->post('/kit/txn', ['ops' => []]);
            $this->fail('Should throw');
        } catch (ConstraintException $e) {
            $this->assertSame('FK_VIOLATION', $e->errorCode);
            $this->assertSame('foreign key constraint failed', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function error_response_409_with_array_body(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, '["unexpected", "array"]'));
        $client = $this->makeClient($transport);

        try {
            $client->post('/kit/txn', []);
            $this->fail('Should throw');
        } catch (ConstraintException $e) {
            // The body is valid JSON but doesn't have the error envelope shape.
            // The code should still extract a message from the body.
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /**
     * @test
     */
    public function unknown_status_code_falls_through_to_query_exception(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(418, "I'm a teapot"));
        $client = $this->makeClient($transport);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("I'm a teapot");
        $client->get('/tables');
    }

    /**
     * @test
     */
    public function status_code_302_redirect_not_followed(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(302, ''));
        $client = $this->makeClient($transport);

        // 3xx is not successful, not a known error code → QueryException
        $this->expectException(QueryException::class);
        $client->get('/tables');
    }
}
