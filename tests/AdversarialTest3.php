<?php

declare(strict_types=1);

/**
 * Round 3 adversarial tests - 50 tests.
 *
 * Focus: SQL DDL escaping across ALL auth methods, URL path injection,
 * JSON encoding edge cases, Stream transport, CurlTransport parsing,
 * concurrent instances, empty method args, SQL return shape handling,
 * transaction lifecycle independence.
 */

namespace Visorcraft\MongrelDB\Tests;

use PHPUnit\Framework\Attributes\Test;
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

require_once __DIR__ . '/AdversarialTest.php';
use Visorcraft\MongrelDB\Tests\MockTransport;

final class AdversarialTest3 extends TestCase
{
    private function makeClient(MockTransport $transport): MongrelDB
    {
        return new MongrelDB(url: 'http://127.0.0.1:8453', username: 'admin', password: 'pw', transport: $transport);
    }

    private function makeDatabase(MockTransport $transport): Database
    {
        return new Database(client: $this->makeClient($transport));
    }

    // ── SQL DDL escaping across ALL auth methods ────────────────────────────

    #[Test]
    public function alter_password_escapes_single_quotes(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->alterPassword("O'Brien", "new'pass'word");

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // Username in double quotes: "O'Brien"
        $this->assertStringContainsString('"O\'Brien"', $sql);
        // Password single quotes doubled: 'new''pass''word'
        $this->assertStringContainsString("'new''pass''word'", $sql);
    }

    #[Test]
    public function drop_user_escapes_identifier(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->dropUser('user"with"quotes');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertStringContainsString('"user""with""quotes"', $sql);
    }

    #[Test]
    public function set_user_admin_escapes_identifier(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->setUserAdmin('a"b', true);

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertStringContainsString('"a""b"', $sql);
        $this->assertStringContainsString('ADMIN', $sql);
    }

    #[Test]
    public function create_role_escapes_identifier(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createRole('role\'with; semicolon');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        // Single quotes are inside double-quoted identifier - safe
        $this->assertStringContainsString('"role\'with; semicolon"', $sql);
    }

    #[Test]
    public function drop_role_escapes_identifier(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->dropRole('r"--');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertStringContainsString('"r""--"', $sql);
    }

    #[Test]
    public function grant_role_escapes_both_identifiers(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->grantRole('user"name', 'role"name');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertStringContainsString('"role""name" TO "user""name"', $sql);
    }

    #[Test]
    public function revoke_role_escapes_both_identifiers(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->revokeRole('a"b', 'c"d');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertStringContainsString('"c""d" FROM "a""b"', $sql);
    }

    #[Test]
    public function create_user_with_both_quotes_in_username(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser('user\'"both', "pass'\"both");

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // Username in double quotes → double quotes escaped
        $this->assertStringContainsString('"user\'""both"', $sql);
        // Password in single quotes → single quotes escaped
        $this->assertStringContainsString("'pass''\"both'", $sql);
    }

    // ── URL path injection ──────────────────────────────────────────────────

    #[Test]
    public function table_name_with_slash_in_url(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        // Table name with slash - path injection attempt
        $client->get('/tables/../admin/users');

        $lastRequest = $transport->getLastRequest();
        // The URL should be constructed as-is (client doesn't sanitize paths)
        $this->assertSame('http://127.0.0.1:8453/tables/../admin/users', $lastRequest['url']);
    }

    #[Test]
    public function table_name_with_url_encoding_in_path(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->get('/tables/%2e%2e/admin');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('http://127.0.0.1:8453/tables/%2e%2e/admin', $lastRequest['url']);
    }

    #[Test]
    public function count_with_slash_in_table_name(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"count":0}'));
        $db = $this->makeDatabase($transport);

        $db->count('orders/evil');

        $lastRequest = $transport->getLastRequest();
        // The slash is now percent-encoded so it cannot inject an extra path segment.
        $this->assertSame('http://127.0.0.1:8453/tables/orders%2Fevil/count', $lastRequest['url']);
    }

    #[Test]
    public function drop_table_with_encoded_chars(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"ok"}'));
        $db = $this->makeDatabase($transport);

        $db->dropTable('table%20name');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('DELETE', $lastRequest['method']);
        // rawurlencode encodes the % so the literal name is preserved.
        $this->assertStringEndsWith('/tables/table%2520name', $lastRequest['url']);
    }

    #[Test]
    public function compact_table_with_special_chars(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"ok"}'));
        $db = $this->makeDatabase($transport);

        $db->compactTable('table?query=injection');

        $lastRequest = $transport->getLastRequest();
        // The ? is now percent-encoded so it cannot start a query string.
        $this->assertStringContainsString('table%3Fquery%3Dinjection', $lastRequest['url']);
        $this->assertSame(1, $transport->requestCount);
    }

    // ── JSON encoding edge cases ────────────────────────────────────────────

    #[Test]
    public function cells_with_invalid_utf8_value(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        // Invalid UTF-8 byte sequence
        $db->put('orders', [1 => 1, 2 => "\xff\xfe"]);

        $lastRequest = $transport->getLastRequest();
        // json_encode should have handled it (possibly as null or with substitution)
        $this->assertSame(1, $transport->requestCount);
    }

    #[Test]
    public function cells_with_nan_value(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $nan = acos(1.01); // produces NAN
        // NAN has no JSON representation and no meaningful database value. The
        // client must surface a clear QueryException rather than silently
        // coercing it to 0 (which would corrupt data and hide bugs).
        try {
            $db->put('orders', [1 => 1, 3 => $nan]);
            $this->fail('Expected QueryException for NAN cell value');
        } catch (QueryException $e) {
            $this->assertStringContainsString('JSON', $e->getMessage());
        }

        // No request should have been sent for an un-encodable payload.
        $this->assertSame(0, $transport->requestCount);
    }

    #[Test]
    public function post_with_recursive_array_throws(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $recursive = [];
        $recursive['self'] = &$recursive;

        // Recursive structures have no JSON representation; the client wraps the
        // underlying JsonException in a typed QueryException so callers get a
        // consistent, actionable error at the client boundary.
        $this->expectException(QueryException::class);
        $client->post('/sql', $recursive);
    }

    #[Test]
    public function cells_with_object_value(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        // PHP stdClass object as a cell value
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->value = 42;

        $db->put('orders', [1 => 1, 2 => $obj]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        // Flat cells are [col_id, val, ...]; column 2's object is at index 3.
        $this->assertSame(['name' => 'test', 'value' => 42], $body['ops'][0]['put']['cells'][3]);
    }

    #[Test]
    public function cells_with_binary_string(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $binary = pack('C*', ...range(0, 255));
        $db->put('orders', [1 => 1, 2 => $binary]);

        // Should not crash - json_encode handles binary (may produce null for invalid UTF-8)
        $this->assertSame(1, $transport->requestCount);
    }

    // ── Empty/null method arguments ─────────────────────────────────────────

    #[Test]
    public function create_table_empty_name(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"table_id":1}'));
        $db = $this->makeDatabase($transport);

        $db->createTable('', [['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true]]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('', $body['name']);
    }

    #[Test]
    public function create_table_empty_columns(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"table_id":1}'));
        $db = $this->makeDatabase($transport);

        $db->createTable('empty', []);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame([], $body['columns']);
    }

    #[Test]
    public function put_empty_table_name(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('', [1 => 1]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('', $body['ops'][0]['put']['table']);
    }

    #[Test]
    public function drop_table_empty_name(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"ok"}'));
        $db = $this->makeDatabase($transport);

        $db->dropTable('');

        $lastRequest = $transport->getLastRequest();
        // URL would be /tables/ (trailing slash with empty name)
        $this->assertSame('DELETE', $lastRequest['method']);
    }

    #[Test]
    public function count_empty_table_name(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"count":0}'));
        $db = $this->makeDatabase($transport);

        $db->count('');

        $lastRequest = $transport->getLastRequest();
        // URL would be /tables//count (double slash)
        $this->assertStringContainsString('/tables//count', $lastRequest['url']);
    }

    // ── Transaction lifecycle independence ──────────────────────────────────

    #[Test]
    public function transaction_works_after_database_unset(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $client = $this->makeClient($transport);

        $txn = new Transaction($client);
        $txn->put('orders', [1 => 1]);

        // Unset the client - Transaction holds its own reference
        unset($client);

        $results = $txn->commit();
        $this->assertSame(1, $transport->requestCount);
    }

    #[Test]
    public function two_transactions_same_client(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[{"kind":"put"}]}'));
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":2,"results":[{"kind":"put"}]}'));
        $client = $this->makeClient($transport);

        $txn1 = new Transaction($client);
        $txn1->put('a', [1 => 1]);

        $txn2 = new Transaction($client);
        $txn2->put('b', [1 => 2]);

        // Commit in reverse order - independent state
        $r2 = $txn2->commit();
        $r1 = $txn1->commit();

        $this->assertCount(1, $r1);
        $this->assertCount(1, $r2);
        $this->assertSame(2, $transport->requestCount);
    }

    #[Test]
    public function transaction_count_after_staging(): void
    {
        $client = $this->makeClient(new MockTransport());
        $txn = new Transaction($client);

        $this->assertSame(0, $txn->count());
        $txn->put('a', [1 => 1]);
        $this->assertSame(1, $txn->count());
        $txn->put('a', [1 => 2]);
        $this->assertSame(2, $txn->count());
        $txn->upsert('a', [1 => 3]);
        $this->assertSame(3, $txn->count());
        $txn->delete('a', 1);
        $this->assertSame(4, $txn->count());
        $txn->deleteByPk('a', 99);
        $this->assertSame(5, $txn->count());
    }

    #[Test]
    public function transaction_rollback_then_count(): void
    {
        $client = $this->makeClient(new MockTransport());
        $txn = new Transaction($client);

        $txn->put('a', [1 => 1]);
        $txn->put('a', [1 => 2]);
        $this->assertSame(2, $txn->count());
        $txn->rollback();
        $this->assertSame(0, $txn->count());
    }

    // ── SQL return shape handling ───────────────────────────────────────────

    #[Test]
    public function sql_returns_json_array(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([['id' => 1], ['id' => 2]])));
        $db = $this->makeDatabase($transport);

        $result = $db->sql('SELECT id FROM orders');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function sql_returns_json_object(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode(['status' => 'ok', 'count' => 5])));
        $db = $this->makeDatabase($transport);

        $result = $db->sql('INSERT INTO orders VALUES (1)');
        $this->assertSame('ok', $result['status']);
    }

    #[Test]
    public function sql_returns_binary_arrow_ipc(): void
    {
        $transport = new MockTransport();
        // Arrow IPC starts with "ARROW1" magic
        $transport->addResponse(new Response(200, "ARROW1\x00\x00rest_of_binary"));
        $db = $this->makeDatabase($transport);

        $result = $db->sql('SELECT * FROM orders');
        // Non-JSON body → returns [] (graceful degradation)
        $this->assertSame([], $result);
    }

    #[Test]
    public function sql_returns_json_null(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, 'null'));
        $db = $this->makeDatabase($transport);

        $result = $db->sql('SELECT 1');
        // json_decode('null') returns PHP null, is_array(null) is false → []
        $this->assertSame([], $result);
    }

    #[Test]
    public function sql_returns_json_boolean(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, 'true'));
        $db = $this->makeDatabase($transport);

        $result = $db->sql('SELECT 1');
        // json_decode('true') returns PHP true, is_array(true) is false → []
        $this->assertSame([], $result);
    }

    #[Test]
    public function sql_returns_json_number(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '42'));
        $db = $this->makeDatabase($transport);

        $result = $db->sql('SELECT count(*)');
        // json_decode('42') returns int, is_array(42) is false → []
        $this->assertSame([], $result);
    }

    // ── Stream transport ────────────────────────────────────────────────────

    #[Test]
    public function stream_transport_construct(): void
    {
        $st = new \Visorcraft\MongrelDB\Transport\StreamTransport(timeout: 60);
        // Just verify construction doesn't crash
        $this->assertInstanceOf(\Visorcraft\MongrelDB\Transport\StreamTransport::class, $st);
    }

    // ── CurlTransport header parsing ────────────────────────────────────────

    #[Test]
    public function curl_transport_construct_with_defaults(): void
    {
        $ct = new \Visorcraft\MongrelDB\Transport\CurlTransport();
        $this->assertInstanceOf(\Visorcraft\MongrelDB\Transport\CurlTransport::class, $ct);
    }

    #[Test]
    public function curl_transport_construct_with_custom_timeouts(): void
    {
        $ct = new \Visorcraft\MongrelDB\Transport\CurlTransport(timeout: 120, connectTimeout: 30);
        $this->assertInstanceOf(\Visorcraft\MongrelDB\Transport\CurlTransport::class, $ct);
    }

    // ── Concurrent Database instances ───────────────────────────────────────

    #[Test]
    public function two_databases_different_auth_same_transport(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $transport->addResponse(new Response(200, '{}'));

        $client1 = new MongrelDB('http://localhost:8453', token: 'token1', transport: $transport);
        $client2 = new MongrelDB('http://localhost:8453', token: 'token2', transport: $transport);

        $client1->get('/health');
        $client2->get('/health');

        $requests = $transport->getRequests();
        $this->assertSame('Bearer token1', $requests[0]['headers']['Authorization']);
        $this->assertSame('Bearer token2', $requests[1]['headers']['Authorization']);
    }

    #[Test]
    public function database_with_preconfigured_client(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB('http://localhost:9999', token: 'preconfigured', transport: $transport);

        $db = new Database(client: $client);
        $db->health();

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('http://localhost:9999/health', $lastRequest['url']);
        $this->assertSame('Bearer preconfigured', $lastRequest['headers']['Authorization']);
    }

    // ── Auth method combinations ────────────────────────────────────────────

    #[Test]
    public function token_takes_precedence_over_username(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));

        // When both token and username are provided, token wins
        $client = new MongrelDB(
            url: 'http://localhost:8453',
            token: 'my-token',
            username: 'my-user',
            password: 'my-pass',
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('Bearer my-token', $lastRequest['headers']['Authorization']);
    }

    #[Test]
    public function basic_auth_without_password(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));

        $client = new MongrelDB(
            url: 'http://localhost:8453',
            username: 'user-only',
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $expected = 'Basic ' . base64_encode('user-only:');
        $this->assertSame($expected, $lastRequest['headers']['Authorization']);
    }

    // ── Procedure error paths ───────────────────────────────────────────────

    #[Test]
    public function call_procedure_on_nonexistent_throws_404(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(404, json_encode([
            'error' => ['code' => 'PROCEDURE_NOT_FOUND', 'message' => 'no such procedure'],
        ])));
        $db = $this->makeDatabase($transport);

        $this->expectException(NotFoundException::class);
        $db->callProcedure('ghost');
    }

    #[Test]
    public function procedure_on_nonexistent_throws_404(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(404, json_encode([
            'error' => ['code' => 'PROCEDURE_NOT_FOUND'],
        ])));
        $db = $this->makeDatabase($transport);

        $this->expectException(NotFoundException::class);
        $db->procedure('ghost');
    }

    #[Test]
    public function create_procedure_validation_error(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(400, json_encode([
            'status' => 'aborted',
            'error' => ['code' => 'PROCEDURE_VALIDATION', 'message' => 'bad params'],
        ])));
        $db = $this->makeDatabase($transport);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('bad params');
        $db->createProcedure(['name' => 'bad']);
    }

    // ── Trigger/error response variations ───────────────────────────────────

    #[Test]
    public function error_400_with_trigger_validation_code(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(400, json_encode([
            'error' => ['code' => 'TRIGGER_VALIDATION', 'message' => 'trigger raised an error'],
        ])));
        $client = $this->makeClient($transport);

        try {
            $client->post('/kit/txn', ['ops' => []]);
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('trigger raised an error', $e->getMessage());
        }
    }

    #[Test]
    public function error_409_with_conflict_code(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, json_encode([
            'error' => ['code' => 'CONFLICT', 'message' => 'write-write conflict'],
        ])));
        $client = $this->makeClient($transport);

        try {
            $client->post('/kit/txn', ['ops' => []]);
            $this->fail();
        } catch (ConstraintException $e) {
            $this->assertSame('CONFLICT', $e->errorCode);
            $this->assertNull($e->opIndex);
        }
    }

    #[Test]
    public function error_409_with_internal_code(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, json_encode([
            'error' => ['code' => 'INTERNAL', 'message' => 'unexpected error'],
        ])));
        $client = $this->makeClient($transport);

        $this->expectException(ConstraintException::class);
        $client->post('/kit/txn', []);
    }

    // ── Response object edge cases ──────────────────────────────────────────

    #[Test]
    public function response_with_empty_headers_array(): void
    {
        $response = new Response(200, '{"ok":true}', []);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame([], $response->headers);
    }

    #[Test]
    public function response_json_with_deeply_nested_structure(): void
    {
        $deep = json_encode(['a' => ['b' => ['c' => ['d' => ['e' => 'deep']]]]]);
        $response = new Response(200, $deep);
        $this->assertSame('deep', $response->json()['a']['b']['c']['d']['e']);
    }

    #[Test]
    public function response_status_201_is_successful(): void
    {
        $response = new Response(201, '');
        $this->assertTrue($response->isSuccessful());
    }

    #[Test]
    public function response_status_204_empty_body(): void
    {
        $response = new Response(204, '');
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('', $response->body);
    }

    // ── QueryBuilder immutability ───────────────────────────────────────────

    #[Test]
    public function query_builder_chain_does_not_mutate_previous_state(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"rows":[]}'));
        $transport->addResponse(new Response(200, '{"rows":[]}'));
        $db = $this->makeDatabase($transport);

        $qb = $db->query('orders')->where('bitmap_eq', ['column' => 1, 'value' => 'A']);

        // Execute with limit
        $qb->limit(10)->execute();

        // Execute again without limit - should NOT have limit from previous call
        $payload = $db->query('orders')->where('bitmap_eq', ['column' => 1, 'value' => 'A'])->build();
        $this->assertArrayNotHasKey('limit', $payload);
    }

    #[Test]
    public function query_builder_fluent_returns_same_instance(): void
    {
        $transport = new MockTransport();
        $db = $this->makeDatabase($transport);

        $qb = $db->query('orders');
        $this->assertSame($qb, $qb->where('pk', ['key' => 1]));
        $this->assertSame($qb, $qb->projection([1]));
        $this->assertSame($qb, $qb->limit(10));
    }

    // ── Database default constructor ────────────────────────────────────────

    #[Test]
    public function database_default_url(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = new Database(client: new MongrelDB(url: 'http://127.0.0.1:8453', transport: $transport));

        $db->health();

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('http://127.0.0.1:8453/health', $lastRequest['url']);
    }

    #[Test]
    public function database_constructor_with_url_and_token(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = new Database(
            url: 'http://example.com:9999',
            token: 'my-token',
            // Can't inject transport via this constructor path
        );

        // The Database will create a MongrelDB internally without our mock transport
        // We can't test the request - but we can verify the object constructs
        $this->assertInstanceOf(Database::class, $db);
    }

    // ── MongrelDB getUrl ────────────────────────────────────────────────────

    #[Test]
    public function get_url_returns_original_url(): void
    {
        $client = new MongrelDB('https://mongreldb.example.com:8453');
        $this->assertSame('https://mongreldb.example.com:8453', $client->getUrl());
    }

    #[Test]
    public function get_url_with_trailing_slash(): void
    {
        $client = new MongrelDB('http://localhost:8453/');
        $this->assertSame('http://localhost:8453/', $client->getUrl());
    }

    // ── Error message extraction variations ────────────────────────────────

    #[Test]
    public function error_message_from_plain_text_with_whitespace(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(400, "  lots of spaces  "));
        $client = $this->makeClient($transport);

        try {
            $client->get('/tables');
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('  lots of spaces  ', $e->getMessage());
        }
    }

    #[Test]
    public function error_message_from_json_without_error_key(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(500, json_encode(['unrelated' => 'data'])));
        $client = $this->makeClient($transport);

        try {
            $client->get('/tables');
            $this->fail();
        } catch (QueryException $e) {
            // Falls back to the raw body
            $this->assertStringContainsString('unrelated', $e->getMessage());
        }
    }

    #[Test]
    public function error_message_from_json_with_error_string_not_object(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(400, json_encode(['error' => 'string message'])));
        $client = $this->makeClient($transport);

        try {
            $client->get('/tables');
            $this->fail();
        } catch (QueryException $e) {
            // error is a string, not an object with code/message
            // $json['error']['message'] would be null → falls back to raw body
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // ── Constraint exception op_index extraction ────────────────────────────

    #[Test]
    public function constraint_exception_without_op_index(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, json_encode([
            'error' => ['code' => 'UNIQUE_VIOLATION', 'message' => 'dup'],
        ])));
        $client = $this->makeClient($transport);

        try {
            $client->post('/kit/txn', []);
            $this->fail();
        } catch (ConstraintException $e) {
            $this->assertNull($e->opIndex);
            $this->assertSame('UNIQUE_VIOLATION', $e->errorCode);
        }
    }

    #[Test]
    public function constraint_exception_with_op_index(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, json_encode([
            'error' => ['code' => 'BAD_REQUEST', 'message' => 'bad op', 'op_index' => 5],
        ])));
        $client = $this->makeClient($transport);

        try {
            $client->post('/kit/txn', []);
            $this->fail();
        } catch (ConstraintException $e) {
            $this->assertSame(5, $e->opIndex);
        }
    }

    // ── Mixed type cell values ─────────────────────────────────────────────

    #[Test]
    public function cells_mixed_types_int_float_string_null_bool(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [
            1 => 42,           // int
            2 => 3.14,         // float
            3 => 'hello',      // string
            4 => null,         // null
            5 => true,         // bool
            6 => false,        // bool
        ]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $cells = $body['ops'][0]['put']['cells'];

        $this->assertSame(42, $cells[1]);
        $this->assertSame(3.14, $cells[3]);
        $this->assertSame('hello', $cells[5]);
        $this->assertNull($cells[7]);
        $this->assertTrue($cells[9]);
        $this->assertFalse($cells[11]);
    }

    #[Test]
    public function cells_with_non_sequential_column_ids(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 'a', 100 => 'b', 255 => 'c']);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $cells = $body['ops'][0]['put']['cells'];

        // Should be flat: [1, 'a', 100, 'b', 255, 'c']
        $this->assertSame([1, 'a', 100, 'b', 255, 'c'], $cells);
    }

    #[Test]
    public function upsert_cells_with_many_columns(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $cells = [];
        for ($i = 1; $i <= 100; $i++) {
            $cells[$i] = "val{$i}";
        }

        $db->upsert('orders', $cells);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $flatCells = $body['ops'][0]['upsert']['cells'];

        // 100 columns × 2 (id + value) = 200 elements
        $this->assertCount(200, $flatCells);
    }

    // ── Upsert edge: update_cells with different column IDs than insert ─────

    #[Test]
    public function upsert_update_cells_different_ids_than_insert(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->upsert('orders', [1 => 1, 2 => 'name'], updateCells: [3 => 99.0, 4 => 'updated']);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $op = $body['ops'][0]['upsert'];

        // Insert cells: [1, 1, 2, 'name']
        $this->assertSame([1, 1, 2, 'name'], $op['cells']);
        // Update cells: [3, 99.0, 4, 'updated']. JSON round-trips 99.0 as 99,
        // so compare loosely to allow the type relaxation.
        $this->assertEquals([3, 99.0, 4, 'updated'], $op['update_cells']);
    }

    // ── Health check multiple calls ─────────────────────────────────────────

    #[Test]
    public function health_check_multiple_calls_makes_multiple_requests(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $transport->addResponse(new Response(200, ''));
        $transport->addResponse(new Response(500, 'error'));

        $client = $this->makeClient($transport);

        $this->assertTrue($client->health());
        $this->assertTrue($client->health());
        $this->assertFalse($client->health());

        $this->assertSame(3, $transport->requestCount);
    }

    // ── Post with various data types ────────────────────────────────────────

    #[Test]
    public function post_with_string_data(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->post('/sql', ['sql' => 'SELECT 1']);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('SELECT 1', $body['sql']);
    }

    #[Test]
    public function post_with_integer_data(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->post('/sql', ['value' => 42]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame(42, $body['value']);
    }

    #[Test]
    public function put_request_with_null_body(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->put('/procedures/test', null);

        $lastRequest = $transport->getLastRequest();
        $this->assertNull($lastRequest['body']);
    }

    #[Test]
    public function put_request_with_data(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"ok"}'));
        $client = $this->makeClient($transport);

        $client->put('/procedures/test', ['procedure' => ['name' => 'test']]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('test', $body['procedure']['name']);
    }

    // ─- Auth SQL DDL: users() and roles() response parsing ─────────────────

    #[Test]
    public function users_sends_show_users_sql(): void
    {
        // users() now routes through sql(), requesting the JSON result format
        // and parsing the response. An empty body (e.g. DDL) yields [].
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '')); // empty body
        $db = $this->makeDatabase($transport);

        $users = $db->users();
        $this->assertSame([], $users);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('SHOW USERS', $body['sql']);
        $this->assertSame('json', $body['format']);
    }

    #[Test]
    public function roles_sends_show_roles_sql(): void
    {
        // Same as users(): roles() routes through sql() and parses JSON.
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $db = $this->makeDatabase($transport);

        $roles = $db->roles();
        $this->assertSame([], $roles);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('SHOW ROLES', $body['sql']);
        $this->assertSame('json', $body['format']);
    }

    #[Test]
    public function users_empty_response(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([])));
        $db = $this->makeDatabase($transport);

        $users = $db->users();
        $this->assertSame([], $users);
    }

    #[Test]
    public function users_returns_parsed_rows(): void
    {
        // users() now returns the decoded JSON rows verbatim instead of [].
        $transport = new MockTransport();
        $rows = [
            ['username' => 'admin'],
            ['username' => 'alice'],
        ];
        $transport->addResponse(new Response(200, json_encode($rows)));
        $db = $this->makeDatabase($transport);

        $users = $db->users();
        $this->assertSame($rows, $users);
    }

    #[Test]
    public function users_missing_username_key(): void
    {
        // Rows are returned verbatim; the raw decoded rows come back even when
        // individual row objects use unexpected keys.
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            ['name' => 'wrong_key'],
        ])));
        $db = $this->makeDatabase($transport);

        $users = $db->users();
        $this->assertSame([['name' => 'wrong_key']], $users);
    }

    // ── Error status codes at boundaries ───────────────────────────────────

    #[Test]
    public function status_199_not_successful(): void
    {
        $response = new Response(199, '');
        $this->assertFalse($response->isSuccessful());
    }

    #[Test]
    public function status_200_successful(): void
    {
        $response = new Response(200, '');
        $this->assertTrue($response->isSuccessful());
    }

    #[Test]
    public function status_299_successful(): void
    {
        $response = new Response(299, '');
        $this->assertTrue($response->isSuccessful());
    }

    #[Test]
    public function status_300_not_successful(): void
    {
        $response = new Response(300, '');
        $this->assertFalse($response->isSuccessful());
    }
}
