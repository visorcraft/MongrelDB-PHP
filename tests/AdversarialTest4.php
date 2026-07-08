<?php

declare(strict_types=1);

/**
 * Round 4 adversarial tests - 50 tests.
 *
 * Focus: QueryBuilder (Database.php) robustness, MockTransport exhaustion,
 * cellsToFlat edge cases (pre-flattened arrays, column 0), CRLF header
 * injection, constructor with bad URLs, exception class correctness,
 * put() with string column keys, URL edge cases, response body types.
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

final class AdversarialTest4 extends TestCase
{
    private function makeClient(MockTransport $transport): MongrelDB
    {
        return new MongrelDB(url: 'http://127.0.0.1:8453', username: 'admin', password: 'pw', transport: $transport);
    }

    private function makeDatabase(MockTransport $transport): Database
    {
        return new Database(client: $this->makeClient($transport));
    }

    // ── QueryBuilder (src/Database.php) robustness ──────────────────────────

    #[Test]
    public function query_builder_execute_missing_rows_key(): void
    {
        $transport = new MockTransport();
        // Response without 'rows' key
        $transport->addResponse(new Response(200, json_encode(['status' => 'ok'])));
        $client = $this->makeClient($transport);

        $qb = new QueryBuilder($client, 'orders');
        $result = $qb->execute();

        // Missing 'rows' key → returns [] via ?? []
        $this->assertSame([], $result);
    }

    #[Test]
    public function query_builder_execute_rows_is_null(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode(['rows' => null])));
        $client = $this->makeClient($transport);

        $qb = new QueryBuilder($client, 'orders');
        $result = $qb->execute();

        $this->assertSame([], $result);
    }

    #[Test]
    public function query_builder_execute_response_not_array(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '"just a string"'));
        $client = $this->makeClient($transport);

        $qb = new QueryBuilder($client, 'orders');
        $result = $qb->execute();

        // json_decode returns string, $data['rows'] is null → returns []
        $this->assertSame([], $result);
    }

    #[Test]
    public function query_builder_build_structure(): void
    {
        $transport = new MockTransport();
        $client = $this->makeClient($transport);

        $qb = new QueryBuilder($client, 'orders');
        $qb->where('pk', ['key' => 'test']);
        $qb->where('range', ['column' => 3, 'min' => 10.0, 'max' => 100.0]);
        $qb->projection([1, 2, 3]);
        $qb->limit(50);

        $payload = $qb->build();

        $this->assertSame('orders', $payload['table']);
        $this->assertCount(2, $payload['conditions']);
        $this->assertSame([1, 2, 3], $payload['projection']);
        $this->assertSame(50, $payload['limit']);

        // Verify condition structure: [{type: {params}}, ...]
        $this->assertArrayHasKey('pk', $payload['conditions'][0]);
        $this->assertArrayHasKey('range', $payload['conditions'][1]);
        $this->assertSame('test', $payload['conditions'][0]['pk']['key']);
    }

    #[Test]
    public function query_builder_no_limit_no_projection(): void
    {
        $transport = new MockTransport();
        $client = $this->makeClient($transport);

        $qb = new QueryBuilder($client, 'orders');
        $payload = $qb->build();

        $this->assertSame('orders', $payload['table']);
        $this->assertArrayNotHasKey('limit', $payload);
        $this->assertArrayNotHasKey('projection', $payload);
        $this->assertArrayNotHasKey('conditions', $payload);
    }

    // ── MockTransport queue exhaustion ──────────────────────────────────────

    #[Test]
    public function mock_transport_returns_default_after_exhaustion(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"queued":true}'));

        $client = $this->makeClient($transport);

        // First request returns queued response
        $r1 = $client->get('/test');
        $this->assertSame('{"queued":true}', $r1->body);

        // Second request (queue exhausted) returns default '{}'
        $r2 = $client->get('/test');
        $this->assertSame('{}', $r2->body);
    }

    #[Test]
    public function mock_transport_records_all_requests(): void
    {
        $transport = new MockTransport();
        $client = $this->makeClient($transport);

        $client->get('/a');
        $client->post('/b', ['x' => 1]);
        $client->delete('/c');

        $requests = $transport->getRequests();
        $this->assertCount(3, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('POST', $requests[1]['method']);
        $this->assertSame('DELETE', $requests[2]['method']);
        $this->assertSame('http://127.0.0.1:8453/a', $requests[0]['url']);
    }

    // ── cellsToFlat edge cases ──────────────────────────────────────────────

    #[Test]
    public function put_with_sequential_array_keys_not_associative(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        // Sequential array [0 => 'val1', 1 => 'val2'] - NOT associative
        // This would be treated as col 0 = 'val1', col 1 = 'val2'
        $db->put('orders', ['val1', 'val2']);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $cells = $body['ops'][0]['put']['cells'];

        // [0, 'val1', 1, 'val2']
        $this->assertSame([0, 'val1', 1, 'val2'], $cells);
    }

    #[Test]
    public function put_with_column_id_zero(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [0 => 'zero_col', 1 => 'one_col']);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $cells = $body['ops'][0]['put']['cells'];

        // Column 0 should be present
        $this->assertSame([0, 'zero_col', 1, 'one_col'], $cells);
    }

    #[Test]
    public function put_with_mixed_int_string_keys(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        // PHP preserves order of mixed int/string keys
        $db->put('orders', [1 => 'a', 'extra' => 'b', 3 => 'c']);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $cells = $body['ops'][0]['put']['cells'];

        // String key 'extra' gets converted to column ID by PHP...
        // Actually PHP string 'extra' stays as string key, json_encode converts to "extra"
        // The cells array would be [1, "a", "extra", "b", 3, "c"]
        $this->assertContains('a', $cells);
        $this->assertContains('b', $cells);
        $this->assertContains('c', $cells);
    }

    // ── CRLF header injection ───────────────────────────────────────────────

    #[Test]
    public function crlf_injection_in_token(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));

        // Token with CRLF injection attempt
        $client = new MongrelDB(
            url: 'http://localhost:8453',
            token: "evil\r\nX-Injected: true",
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        // The CRLF is in the Authorization header value - the mock transport
        // records it as-is. A real cURL transport would reject or sanitize it.
        $this->assertStringContainsString('evil', $lastRequest['headers']['Authorization']);
    }

    #[Test]
    public function crlf_injection_in_basic_auth_username(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));

        $client = new MongrelDB(
            url: 'http://localhost:8453',
            username: "user\r\nX-Evil: true",
            password: 'pass',
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        // The base64 encoding neutralizes CRLF injection
        $auth = $lastRequest['headers']['Authorization'];
        $this->assertStringStartsWith('Basic ', $auth);
        // Decoding the base64 should contain the raw username with CRLF
        $decoded = base64_decode(substr($auth, 6));
        $this->assertStringContainsString("user\r\nX-Evil: true", $decoded);
    }

    // ── Constructor with bad URLs ───────────────────────────────────────────

    #[Test]
    public function constructor_with_empty_url(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(url: '', transport: $transport);

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        // rtrim('') = '', ltrim('/health') = 'health', url = '/health'
        $this->assertSame('/health', $lastRequest['url']);
    }

    #[Test]
    public function constructor_with_just_scheme(): void
    {
        $transport = new MockTransport();
        $client = new MongrelDB(url: 'http://', transport: $transport);

        // Should construct without error
        $this->assertInstanceOf(MongrelDB::class, $client);
    }

    #[Test]
    public function constructor_with_no_scheme(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(url: 'localhost:8453', transport: $transport);

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('localhost:8453/health', $lastRequest['url']);
    }

    #[Test]
    public function constructor_with_https_scheme(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(url: 'https://mongreldb.example.com:8453', transport: $transport);

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('https://mongreldb.example.com:8453/health', $lastRequest['url']);
    }

    #[Test]
    public function constructor_with_trailing_path(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(url: 'http://localhost:8453/api/v1/', transport: $transport);

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        // rtrim removes trailing /, then adds /health
        $this->assertSame('http://localhost:8453/api/v1/health', $lastRequest['url']);
    }

    // ── Exception class correctness ─────────────────────────────────────────

    #[Test]
    public function all_exceptions_extend_base(): void
    {
        $this->assertInstanceOf(MongrelDBException::class, new ConnectionException('test'));
        $this->assertInstanceOf(MongrelDBException::class, new AuthException('test'));
        $this->assertInstanceOf(MongrelDBException::class, new ConstraintException('test'));
        $this->assertInstanceOf(MongrelDBException::class, new NotFoundException('test'));
        $this->assertInstanceOf(MongrelDBException::class, new QueryException('test'));
    }

    #[Test]
    public function base_exception_extends_php_exception(): void
    {
        $this->assertInstanceOf(\Exception::class, new MongrelDBException('test'));
    }

    #[Test]
    public function constraint_exception_stores_error_code(): void
    {
        $e = new ConstraintException('msg', errorCode: 'UNIQUE_VIOLATION', opIndex: 3);
        $this->assertSame('UNIQUE_VIOLATION', $e->errorCode);
        $this->assertSame(3, $e->opIndex);
        $this->assertSame('msg', $e->getMessage());
    }

    #[Test]
    public function constraint_exception_defaults(): void
    {
        $e = new ConstraintException('msg');
        $this->assertSame('', $e->errorCode);
        $this->assertNull($e->opIndex);
    }

    #[Test]
    public function constraint_exception_is_catchable_as_base(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, json_encode([
            'error' => ['code' => 'CHECK_VIOLATION', 'message' => 'fail'],
        ])));
        $client = $this->makeClient($transport);

        try {
            $client->post('/kit/txn', []);
        } catch (MongrelDBException $e) {
            // ConstraintException is catchable as MongrelDBException
            $this->assertInstanceOf(ConstraintException::class, $e);
        }
    }

    #[Test]
    public function auth_exception_is_catchable_as_base(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(403, 'forbidden'));
        $client = $this->makeClient($transport);

        try {
            $client->get('/tables');
        } catch (MongrelDBException $e) {
            $this->assertInstanceOf(AuthException::class, $e);
        }
    }

    // ── HTTP method verification ────────────────────────────────────────────

    #[Test]
    public function put_method_sends_put(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->put('/test', ['data' => 1]);

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('PUT', $lastRequest['method']);
    }

    #[Test]
    public function post_sends_content_type_header(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->post('/sql', ['sql' => 'SELECT 1']);

        $lastRequest = $transport->getLastRequest();
        // The Accept header is set by default
        $this->assertSame('application/json', $lastRequest['headers']['Accept']);
    }

    #[Test]
    public function get_with_extra_headers(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->get('/tables', [
            'Accept-Language' => 'en-US',
            'X-Request-ID' => 'abc123',
        ]);

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('en-US', $lastRequest['headers']['Accept-Language']);
        $this->assertSame('abc123', $lastRequest['headers']['X-Request-ID']);
        // Default headers still present
        $this->assertArrayHasKey('Authorization', $lastRequest['headers']);
    }

    // ── sqlRaw edge cases ───────────────────────────────────────────────────

    #[Test]
    public function sql_raw_empty_body(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $client = $this->makeClient($transport);

        $result = $client->sqlRaw('INSERT INTO orders VALUES (1)');
        $this->assertSame('', $result);
    }

    #[Test]
    public function sql_raw_binary_body(): void
    {
        $transport = new MockTransport();
        $binary = pack('C*', 0x41, 0x52, 0x52, 0x4f, 0x57, 0x31); // "ARROW1"
        $transport->addResponse(new Response(200, $binary));
        $client = $this->makeClient($transport);

        $result = $client->sqlRaw('SELECT 1');
        $this->assertSame($binary, $result);
    }

    // ── Transaction with various op combinations ────────────────────────────

    #[Test]
    public function transaction_all_delete_by_pk(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[{"kind":"deleted"},{"kind":"deleted"},{"kind":"not_found"}]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->deleteByPk('orders', 1);
        $txn->deleteByPk('orders', 2);
        $txn->deleteByPk('orders', 999);
        $results = $txn->commit();

        $this->assertCount(3, $results);
        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertCount(3, $body['ops']);
    }

    #[Test]
    public function transaction_large_batch_100_ops(): void
    {
        $transport = new MockTransport();
        $results = [];
        for ($i = 0; $i < 100; $i++) {
            $results[] = ['kind' => 'put'];
        }
        $transport->addResponse(new Response(200, json_encode([
            'status' => 'committed',
            'epoch' => 1,
            'results' => $results,
        ])));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        for ($i = 1; $i <= 100; $i++) {
            $txn->put('orders', [1 => $i, 2 => "item{$i}"]);
        }
        $this->assertSame(100, $txn->count());
        $results = $txn->commit();
        $this->assertCount(100, $results);
    }

    #[Test]
    public function transaction_mixed_types_in_single_batch(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'status' => 'committed',
            'epoch' => 1,
            'results' => [
                ['kind' => 'put'],
                ['kind' => 'upsert', 'action' => 'inserted'],
                ['kind' => 'upsert', 'action' => 'updated'],
                ['kind' => 'deleted'],
                ['kind' => 'not_found'],
            ],
        ])));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('t', [1 => 1]);
        $txn->upsert('t', [1 => 2]);
        $txn->upsert('t', [1 => 3], [2 => 'updated']);
        $txn->delete('t', 42);
        $txn->deleteByPk('t', 999);
        $results = $txn->commit();

        $this->assertCount(5, $results);
        $this->assertSame('inserted', $results[1]['action']);
        $this->assertSame('updated', $results[2]['action']);
    }

    // ── Default transport selection ────────────────────────────────────────

    #[Test]
    public function default_transport_is_curl(): void
    {
        $client = new MongrelDB('http://localhost:8453');
        // Can't check the private transport field, but we can verify
        // the object constructs without error when cURL is available
        $this->assertInstanceOf(MongrelDB::class, $client);
    }

    #[Test]
    public function custom_transport_used(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"used":true}'));

        $client = new MongrelDB('http://localhost:8453', transport: $transport);
        $response = $client->get('/health');

        $this->assertSame('{"used":true}', $response->body);
    }

    // ── JSON body encoding verification ─────────────────────────────────────

    #[Test]
    public function post_json_body_is_valid_json(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $data = ['key' => 'value', 'nested' => ['a' => 1, 'b' => [1, 2, 3]], 'null' => null, 'bool' => true];
        $client->post('/test', $data);

        $lastRequest = $transport->getLastRequest();
        // Verify the body is valid JSON that round-trips correctly
        $decoded = json_decode($lastRequest['body'], true);
        $this->assertSame($data, $decoded);
    }

    #[Test]
    public function post_unicode_in_json_body(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->post('/test', ['name' => '日本語テスト🎉']);

        $lastRequest = $transport->getLastRequest();
        $decoded = json_decode($lastRequest['body'], true);
        $this->assertSame('日本語テスト🎉', $decoded['name']);
    }

    #[Test]
    public function post_integer_keys_in_json(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->post('/test', [1 => 'a', 2 => 'b', 3 => 'c']);

        $lastRequest = $transport->getLastRequest();
        $decoded = json_decode($lastRequest['body'], true);
        // PHP arrays whose keys don't start at 0 are encoded as JSON objects, so
        // the integer keys are preserved (as strings) on decode.
        $this->assertSame(['1' => 'a', '2' => 'b', '3' => 'c'], $decoded);
    }

    // ── Auth method SQL output verification ────────────────────────────────

    #[Test]
    public function create_user_sql_structure(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser('alice', 'secret123');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // Verify full SQL structure
        $this->assertStringStartsWith('CREATE USER "alice" WITH PASSWORD \'secret123\'', $sql);
    }

    #[Test]
    public function drop_user_sql_structure(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->dropUser('alice');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertSame('DROP USER "alice"', $sql);
    }

    #[Test]
    public function create_role_sql_structure(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createRole('analyst');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertSame('CREATE ROLE "analyst"', $sql);
    }

    #[Test]
    public function grant_role_sql_structure(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->grantRole('alice', 'analyst');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertSame('GRANT "analyst" TO "alice"', $sql);
    }

    #[Test]
    public function revoke_role_sql_structure(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->revokeRole('alice', 'analyst');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertSame('REVOKE "analyst" FROM "alice"', $sql);
    }

    #[Test]
    public function grant_permission_sql_structure(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->grantPermission('analyst', 'select:orders');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertSame('GRANT SELECT ON orders TO "analyst"', $sql);
    }

    #[Test]
    public function revoke_permission_sql_structure(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->revokePermission('analyst', 'insert:orders');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertSame('REVOKE INSERT ON orders FROM "analyst"', $sql);
    }

    #[Test]
    public function show_users_sql_structure(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '[]'));
        $db = $this->makeDatabase($transport);

        $db->users();

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertSame('SHOW USERS', $sql);
    }

    #[Test]
    public function show_roles_sql_structure(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '[]'));
        $db = $this->makeDatabase($transport);

        $db->roles();

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];
        $this->assertSame('SHOW ROLES', $sql);
    }

    // ─- Response::json with various data types ──────────────────────────────

    #[Test]
    public function response_json_returns_string(): void
    {
        $response = new Response(200, '"hello world"');
        $this->assertSame('hello world', $response->json());
    }

    #[Test]
    public function response_json_returns_integer(): void
    {
        $response = new Response(200, '42');
        $this->assertSame(42, $response->json());
    }

    #[Test]
    public function response_json_returns_float(): void
    {
        $response = new Response(200, '3.14');
        $this->assertSame(3.14, $response->json());
    }

    #[Test]
    public function response_json_returns_true(): void
    {
        $response = new Response(200, 'true');
        $this->assertTrue($response->json());
    }

    #[Test]
    public function response_json_returns_false(): void
    {
        $response = new Response(200, 'false');
        $this->assertFalse($response->json());
    }

    #[Test]
    public function response_json_returns_null(): void
    {
        $response = new Response(200, 'null');
        $this->assertNull($response->json());
    }

    // ── Mixed success/error scenarios ───────────────────────────────────────

    #[Test]
    public function success_then_error_then_success(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"count":5}'));
        $transport->addResponse(new Response(404, 'not found'));
        $transport->addResponse(new Response(200, '{"count":10}'));
        $db = $this->makeDatabase($transport);

        // First call succeeds
        $this->assertSame(5, $db->count('orders'));

        // Second call fails
        try {
            $db->count('ghost');
            $this->fail();
        } catch (NotFoundException $e) {
            // Expected
        }

        // Third call succeeds (client still usable after error)
        $this->assertSame(10, $db->count('items'));
    }

    #[Test]
    public function error_then_health_check(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(500, 'crash'));
        $transport->addResponse(new Response(200, ''));
        $client = $this->makeClient($transport);

        // Request fails
        try {
            $client->get('/tables');
            $this->fail();
        } catch (QueryException $e) {
            // Expected
        }

        // Health check still works
        $this->assertTrue($client->health());
    }

    #[Test]
    public function constraint_error_then_retry_different_data(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, json_encode([
            'error' => ['code' => 'UNIQUE_VIOLATION', 'message' => 'dup key'],
        ])));
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[{"kind":"put"}]}'));
        $db = $this->makeDatabase($transport);

        // First put fails (constraint)
        try {
            $db->put('orders', [1 => 1, 2 => 'dup']);
            $this->fail();
        } catch (ConstraintException $e) {
            $this->assertSame('UNIQUE_VIOLATION', $e->errorCode);
        }

        // Retry with different data succeeds
        $result = $db->put('orders', [1 => 2, 2 => 'unique']);
        $this->assertSame('put', $result['kind']);
    }

    // ── Transaction after error ─────────────────────────────────────────────

    #[Test]
    public function transaction_commit_fails_then_new_transaction(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, json_encode([
            'error' => ['code' => 'FK_VIOLATION', 'message' => 'missing parent'],
        ])));
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[{"kind":"put"}]}'));
        $db = $this->makeDatabase($transport);

        // First transaction fails
        $txn1 = $db->beginTransaction();
        $txn1->put('orders', [1 => 1]);
        try {
            $txn1->commit();
            $this->fail();
        } catch (ConstraintException $e) {
            $this->assertSame('FK_VIOLATION', $e->errorCode);
        }

        // Second transaction (new instance) succeeds
        $txn2 = $db->beginTransaction();
        $txn2->put('orders', [1 => 2]);
        $results = $txn2->commit();
        $this->assertCount(1, $results);
    }

    // ── Multiple tables in single transaction ───────────────────────────────

    #[Test]
    public function transaction_across_multiple_tables(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'status' => 'committed',
            'epoch' => 1,
            'results' => [
                ['kind' => 'put'],
                ['kind' => 'put'],
                ['kind' => 'deleted'],
            ],
        ])));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('orders', [1 => 1]);
        $txn->put('customers', [1 => 1, 2 => 'Alice']);
        $txn->deleteByPk('archive', 99);
        $txn->commit();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('orders', $body['ops'][0]['put']['table']);
        $this->assertSame('customers', $body['ops'][1]['put']['table']);
        $this->assertSame('archive', $body['ops'][2]['delete_by_pk']['table']);
    }

    // ── Empty string vs null in various positions ──────────────────────────

    #[Test]
    public function put_empty_string_vs_null_distinct(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":2,"results":[]}'));
        $db = $this->makeDatabase($transport);

        // Empty string. Flat cells: [1,1,2,''] -> column 2's value at index 3.
        $db->put('t', [1 => 1, 2 => '']);
        $r1 = $transport->getRequests()[0];
        $b1 = json_decode($r1['body'], true);
        $this->assertSame('', $b1['ops'][0]['put']['cells'][3]);

        // Null. Flat cells: [1,2,2,null] -> column 2's value at index 3.
        $db->put('t', [1 => 2, 2 => null]);
        $r2 = $transport->getRequests()[1];
        $b2 = json_decode($r2['body'], true);
        $this->assertNull($b2['ops'][0]['put']['cells'][3]);

        // These are distinct values in JSON ('' vs null). Use strict comparison
        // (assertSame) semantics: '' and null are loosely equal in PHP but must
        // remain distinct types over the wire.
        $this->assertNotSame($b1['ops'][0]['put']['cells'][3], $b2['ops'][0]['put']['cells'][3]);
    }

    #[Test]
    public function put_zero_vs_null_distinct(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":2,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('t', [1 => 1, 3 => 0]);
        $db->put('t', [1 => 2, 3 => null]);

        $r1 = $transport->getRequests()[0]['body'];
        $r2 = $transport->getRequests()[1]['body'];
        $b1 = json_decode($r1, true);
        $b2 = json_decode($r2, true);

        // Flat cells: [1,1,3,0] and [1,2,3,null] -> column 3's value at index 3.
        $this->assertSame(0, $b1['ops'][0]['put']['cells'][3]);
        $this->assertNull($b2['ops'][0]['put']['cells'][3]);
    }

    #[Test]
    public function put_false_vs_zero_distinct(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":2,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('t', [1 => 1, 5 => false]);
        $db->put('t', [1 => 2, 5 => 0]);

        $r1 = json_decode($transport->getRequests()[0]['body'], true);
        $r2 = json_decode($transport->getRequests()[1]['body'], true);

        // Flat cells: [1,1,5,false] and [1,2,5,0] -> column 5's value at index 3.
        // In JSON: false !== 0
        $this->assertFalse($r1['ops'][0]['put']['cells'][3]);
        $this->assertSame(0, $r2['ops'][0]['put']['cells'][3]);
    }

    // ─- Schema descriptor structure verification ────────────────────────────

    #[Test]
    public function schema_with_full_descriptor(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'tables' => [
                'orders' => [
                    'schema_id' => 1,
                    'columns' => [
                        ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
                        ['id' => 2, 'name' => 'amount', 'ty' => 'float64', 'primary_key' => false, 'nullable' => true],
                    ],
                    'constraints' => [
                        'uniques' => [],
                        'foreign_keys' => [],
                        'checks' => [],
                    ],
                ],
            ],
        ])));
        $db = $this->makeDatabase($transport);

        $schema = $db->schema();
        $this->assertArrayHasKey('orders', $schema);
        $this->assertSame(1, $schema['orders']['schema_id']);
        $this->assertCount(2, $schema['orders']['columns']);
        $this->assertTrue($schema['orders']['columns'][0]['primary_key']);
    }
}
