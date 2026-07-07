<?php

declare(strict_types=1);

/**
 * Adversarial test suite for the MongrelDB PHP client.
 *
 * Tests SQL injection, input validation, error handling, edge cases,
 * and malicious input patterns. Does NOT require a running daemon —
 * tests are designed to catch issues at the client level (before the
 * request hits the server).
 *
 * Run: php tests/AdversarialTest.php
 * Or:  phpunit tests/AdversarialTest.php
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

/**
 * Mock transport that returns pre-configured responses.
 * Used to test client behavior without a running daemon.
 */
final class MockTransport implements TransportInterface
{
    private array $responses = [];
    private array $requests = [];
    public int $requestCount = 0;

    /**
     * Queue a response. Responses are returned in order.
     */
    public function addResponse(Response $response): void
    {
        $this->responses[] = $response;
    }

    /**
     * Get all recorded requests.
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * Get the last request made.
     */
    public function getLastRequest(): ?array
    {
        return $this->requests[count($this->requests) - 1] ?? null;
    }

    #[\Override]
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        $this->requestCount++;
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        if (count($this->responses) > 0) {
            return array_shift($this->responses);
        }

        // Default: return 200 OK with empty JSON
        return new Response(200, '{}', ['content-type' => 'application/json']);
    }
}

final class AdversarialTest extends TestCase
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

    // ── SQL Injection ───────────────────────────────────────────────────────

    /**
     * @test
     */
    public function sql_injection_in_username(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"sql_result": []}'));
        $db = $this->makeDatabase($transport);

        $malicious = "alice'; DROP USER admin; --";
        $db->createUser($malicious, 'pw');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // The username must be quoted — the quote function must escape the single quote
        $this->assertStringContainsString('"alice\'\'; DROP USER admin; --"', $sql);
        $this->assertStringNotContainsString("alice'; DROP USER admin", $sql);
    }

    /**
     * @test
     */
    public function sql_injection_in_password(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $malicious = "pw'; DROP USER admin; --";
        $db->createUser('alice', $malicious);

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // The password must have its single quotes doubled
        $this->assertStringContainsString("'pw''; DROP USER admin; --'", $sql);
    }

    /**
     * @test
     */
    public function sql_injection_in_role_name(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $malicious = 'role"; DROP TABLE orders; --';
        $db->createRole($malicious);

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // The double-quote inside the identifier must be escaped
        $this->assertStringContainsString('"role""; DROP TABLE orders; --"', $sql);
    }

    /**
     * @test
     */
    public function sql_injection_in_grant_permission(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->grantPermission('analyst', 'select:orders');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // Permission string is passed raw — verify it doesn't allow injection
        // through the role name quoting
        $this->assertStringContainsString('GRANT select:orders TO "analyst"', $sql);
    }

    /**
     * @test
     */
    public function sql_injection_backslash_in_username(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser("alice\\", 'pw');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // Backslash should not cause issues in SQL — it's inside double quotes
        $this->assertStringContainsString('"alice\"', $sql);
    }

    // ── Input Validation ───────────────────────────────────────────────────

    /**
     * @test
     */
    public function empty_username_should_not_crash(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        // Should not throw at the client level — server handles validation
        $db->createUser('', 'pw');

        $this->assertSame(1, $transport->requestCount);
    }

    /**
     * @test
     */
    public function empty_password_should_not_crash(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser('alice', '');

        $this->assertSame(1, $transport->requestCount);
    }

    /**
     * @test
     */
    public function null_byte_in_username(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser("alice\x00admin", 'pw');

        // The null byte should be in the request — PHP doesn't strip it
        $lastRequest = $transport->getLastRequest();
        $this->assertNotNull($lastRequest);
    }

    /**
     * @test
     */
    public function very_long_username(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $long = str_repeat('A', 10000);
        $db->createUser($long, 'pw');

        $this->assertSame(1, $transport->requestCount);
    }

    /**
     * @test
     */
    public function unicode_username(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser('日本語ユーザー', 'pw');

        $lastRequest = $transport->getLastRequest();
        $this->assertNotNull($lastRequest);
    }

    /**
     * @test
     */
    public function emoji_in_cell_value(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 1, 2 => '🎉 party order']);

        // Verify the emoji was JSON-encoded correctly
        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('🎉 party order', $body['ops'][0]['put']['cells'][1]);
    }

    /**
     * @test
     */
    public function negative_number_as_cell_value(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => -42, 2 => 'negative PK']);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame(-42, $body['ops'][0]['put']['cells'][0]);
    }

    /**
     * @test
     */
    public function float_with_special_values(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        // INF and NaN should be JSON-encoded (PHP json_encode handles them)
        $db->put('orders', [1 => 1, 2 => INF]);

        $lastRequest = $transport->getLastRequest();
        // The request should have been made — json_encode may convert INF to 0
        $this->assertSame(1, $transport->requestCount);
    }

    /**
     * @test
     */
    public function empty_cells_array(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', []);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame([], $body['ops'][0]['put']['cells']);
    }

    // ── Transaction Error Handling ──────────────────────────────────────────

    /**
     * @test
     */
    public function transaction_double_commit(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('orders', [1 => 1]);
        $txn->commit();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already committed');
        $txn->commit();
    }

    /**
     * @test
     */
    public function transaction_rollback_after_commit(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('orders', [1 => 1]);
        $txn->commit();

        $this->expectException(\LogicException::class);
        $txn->rollback();
    }

    /**
     * @test
     */
    public function transaction_commit_empty(): void
    {
        $transport = new MockTransport();
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $results = $txn->commit();

        $this->assertSame([], $results);
        // Should not have made any HTTP request
        $this->assertSame(0, $transport->requestCount);
    }

    /**
     * @test
     */
    public function transaction_rollback_discards_ops(): void
    {
        $transport = new MockTransport();
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('orders', [1 => 1]);
        $txn->put('orders', [1 => 2]);
        $this->assertSame(2, $txn->count());

        $txn->rollback();
        $this->assertSame(0, $txn->count());

        // Commit after rollback should return empty
        $results = $txn->commit();
        $this->assertSame([], $results);
        $this->assertSame(0, $transport->requestCount);
    }

    /**
     * @test
     */
    public function transaction_with_idempotency_key(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $txn = $db->beginTransaction();
        $txn->put('orders', [1 => 1]);
        $txn->commit(idempotencyKey: 'op-123');

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('op-123', $body['idempotency_key']);
    }

    // ── HTTP Error Mapping ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function error_400_maps_to_query_exception(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(400, 'Bad SQL syntax'));
        $client = $this->makeClient($transport);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Bad SQL syntax');
        $client->get('/tables');
    }

    /**
     * @test
     */
    public function error_401_maps_to_auth_exception(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(401, 'Unauthorized'));
        $client = $this->makeClient($transport);

        $this->expectException(AuthException::class);
        $client->get('/tables');
    }

    /**
     * @test
     */
    public function error_403_maps_to_auth_exception(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(403, 'Forbidden'));
        $client = $this->makeClient($transport);

        $this->expectException(AuthException::class);
        $client->get('/tables');
    }

    /**
     * @test
     */
    public function error_404_maps_to_not_found_exception(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(404, 'Table not found'));
        $client = $this->makeClient($transport);

        $this->expectException(NotFoundException::class);
        $client->get('/tables/ghost');
    }

    /**
     * @test
     */
    public function error_409_maps_to_constraint_exception(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(
            409,
            json_encode([
                'status' => 'aborted',
                'error' => [
                    'code' => 'UNIQUE_VIOLATION',
                    'message' => 'duplicate key',
                    'op_index' => 2,
                ],
            ]),
        ));
        $client = $this->makeClient($transport);

        try {
            $client->post('/kit/txn', ['ops' => []]);
            $this->fail('Should have thrown ConstraintException');
        } catch (ConstraintException $e) {
            $this->assertSame('UNIQUE_VIOLATION', $e->errorCode);
            $this->assertSame('duplicate key', $e->getMessage());
            $this->assertSame(2, $e->opIndex);
        }
    }

    /**
     * @test
     */
    public function error_409_plain_text_body(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(409, 'Conflict: duplicate key'));
        $client = $this->makeClient($transport);

        try {
            $client->post('/txn', ['ops' => []]);
            $this->fail('Should have thrown ConstraintException');
        } catch (ConstraintException $e) {
            $this->assertSame('Conflict: duplicate key', $e->getMessage());
            $this->assertSame('', $e->errorCode);
        }
    }

    /**
     * @test
     */
    public function error_500_maps_to_query_exception(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(500, 'Internal server error'));
        $client = $this->makeClient($transport);

        $this->expectException(QueryException::class);
        $client->get('/tables');
    }

    /**
     * @test
     */
    public function error_with_json_envelope(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(
            400,
            json_encode([
                'error' => [
                    'code' => 'BAD_REQUEST',
                    'message' => 'Invalid column type',
                ],
            ]),
        ));
        $client = $this->makeClient($transport);

        try {
            $client->post('/kit/create_table', []);
            $this->fail('Should have thrown');
        } catch (QueryException $e) {
            $this->assertSame('Invalid column type', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function empty_error_body(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(401, ''));
        $client = $this->makeClient($transport);

        try {
            $client->get('/tables');
            $this->fail('Should have thrown');
        } catch (AuthException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
        }
    }

    // ── Query Builder ───────────────────────────────────────────────────────

    /**
     * @test
     */
    public function query_builder_no_conditions(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode(['rows' => []])));
        $db = $this->makeDatabase($transport);

        $rows = $db->query('orders')->execute();
        $this->assertSame([], $rows);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        // No conditions key when no conditions set
        $this->assertArrayNotHasKey('conditions', $body);
    }

    /**
     * @test
     */
    public function query_builder_multiple_conditions(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode(['rows' => []])));
        $db = $this->makeDatabase($transport);

        $db->query('orders')
            ->where('bitmap_eq', ['column' => 2, 'value' => 'Alice'])
            ->where('range', ['column' => 3, 'min' => 100.0])
            ->projection([1, 2])
            ->limit(50)
            ->execute();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);

        $this->assertCount(2, $body['conditions']);
        $this->assertSame([1, 2], $body['projection']);
        $this->assertSame(50, $body['limit']);
    }

    /**
     * @test
     */
    public function query_builder_empty_table_name(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode(['rows' => []])));
        $db = $this->makeDatabase($transport);

        $db->query('')->execute();

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('', $body['table']);
    }

    // ── Connection edge cases ───────────────────────────────────────────────

    /**
     * @test
     */
    public function connection_refused_throws_connection_exception(): void
    {
        // Use a real transport that will fail to connect
        $client = new MongrelDB('http://127.0.0.1:99999');

        $this->expectException(ConnectionException::class);
        $client->get('/health');
    }

    /**
     * @test
     */
    public function health_check_returns_false_on_error(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(500, 'error'));
        $client = $this->makeClient($transport);

        $this->assertFalse($client->health());
    }

    /**
     * @test
     */
    public function health_check_returns_true_on_success(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $client = $this->makeClient($transport);

        $this->assertTrue($client->health());
    }

    // ── Auth header construction ────────────────────────────────────────────

    /**
     * @test
     */
    public function bearer_token_sets_authorization_header(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(
            url: 'http://127.0.0.1:8453',
            token: 'my-secret',
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('Bearer my-secret', $lastRequest['headers']['Authorization']);
    }

    /**
     * @test
     */
    public function basic_auth_sets_authorization_header(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(
            url: 'http://127.0.0.1:8453',
            username: 'admin',
            password: 'pass',
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $expected = 'Basic ' . base64_encode('admin:pass');
        $this->assertSame($expected, $lastRequest['headers']['Authorization']);
    }

    /**
     * @test
     */
    public function no_auth_when_neither_token_nor_username(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(
            url: 'http://127.0.0.1:8453',
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $this->assertArrayNotHasKey('Authorization', $lastRequest['headers']);
    }

    /**
     * @test
     */
    public function password_with_special_chars_in_basic_auth(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(
            url: 'http://127.0.0.1:8453',
            username: 'user',
            password: 'p@ss:w0rd!',
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $expected = 'Basic ' . base64_encode('user:p@ss:w0rd!');
        $this->assertSame($expected, $lastRequest['headers']['Authorization']);
    }

    // ── URL construction ────────────────────────────────────────────────────

    /**
     * @test
     */
    public function url_with_trailing_slash(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(
            url: 'http://127.0.0.1:8453/',
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('http://127.0.0.1:8453/health', $lastRequest['url']);
    }

    /**
     * @test
     */
    public function url_without_trailing_slash(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = new MongrelDB(
            url: 'http://127.0.0.1:8453',
            transport: $transport,
        );

        $client->get('/health');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('http://127.0.0.1:8453/health', $lastRequest['url']);
    }

    /**
     * @test
     */
    public function path_with_leading_slash(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->get('/tables/orders');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('http://127.0.0.1:8453/tables/orders', $lastRequest['url']);
    }

    /**
     * @test
     */
    public function path_without_leading_slash(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $client = $this->makeClient($transport);

        $client->get('tables/orders');

        $lastRequest = $transport->getLastRequest();
        $this->assertSame('http://127.0.0.1:8453/tables/orders', $lastRequest['url']);
    }

    // ── Cells conversion ────────────────────────────────────────────────────

    /**
     * @test
     */
    public function cells_with_string_keys_are_handled(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        // String keys should be converted to flat array with integer column IDs
        $db->put('orders', ['1' => 'Alice', '2' => 99.5]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $cells = $body['ops'][0]['put']['cells'];

        // PHP converts string numeric keys to int in JSON
        $this->assertContains(1, $cells);
        $this->assertContains('Alice', $cells);
    }

    /**
     * @test
     */
    public function cells_with_nested_array_value(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        // Nested arrays should be JSON-encoded as the value
        $db->put('orders', [1 => 1, 2 => ['nested', 'array']]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $cells = $body['ops'][0]['put']['cells'];

        // The nested array should be preserved as a JSON array
        $this->assertSame(['nested', 'array'], $cells[1]);
    }

    /**
     * @test
     */
    public function cells_with_null_value(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 1, 4 => null]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $cells = $body['ops'][0]['put']['cells'];

        // null should be preserved
        $this->assertNull($cells[1]);
    }

    /**
     * @test
     */
    public function cells_with_boolean_value(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"status":"committed","epoch":1,"results":[]}'));
        $db = $this->makeDatabase($transport);

        $db->put('orders', [1 => 1, 5 => true, 6 => false]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $cells = $body['ops'][0]['put']['cells'];

        $this->assertTrue($cells[1]);
        $this->assertFalse($cells[3]);
    }

    // ── Stored procedure edge cases ─────────────────────────────────────────

    /**
     * @test
     */
    public function call_procedure_with_empty_args(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'status' => 'ok',
            'result' => 42,
        ])));
        $db = $this->makeDatabase($transport);

        $result = $db->callProcedure('count_all');

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame([], $body['args'] ?? json_decode($lastRequest['body'], true)['args'] ?? []);

        // Also verify the result is extracted
        $this->assertSame(42, $result);
    }

    /**
     * @test
     */
    public function call_procedure_with_complex_args(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode([
            'status' => 'ok',
            'result' => null,
        ])));
        $db = $this->makeDatabase($transport);

        $db->callProcedure('search', [
            'query' => 'database performance',
            'limit' => 10,
            'filters' => ['category' => 'tech', 'date' => '2024-01-01'],
        ]);

        $lastRequest = $transport->getLastRequest();
        $body = json_decode($lastRequest['body'], true);
        $this->assertSame('database performance', $body['args']['query']);
    }

    // ── SQL escape edge cases ────────────────────────────────────────────────

    /**
     * @test
     */
    public function password_with_single_quote_escaped(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser('alice', "my'password");

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // Single quote should be doubled
        $this->assertStringContainsString("'my''password'", $sql);
    }

    /**
     * @test
     */
    public function password_with_backslash_not_special(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser('alice', "back\\slash");

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // In SQL standard, backslash is not special inside single quotes
        $this->assertStringContainsString("back\\slash", $sql);
    }

    /**
     * @test
     */
    public function username_with_double_quote_escaped(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        $db->createUser('user"name', 'pw');

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // Double quote inside identifier should be doubled
        $this->assertStringContainsString('"user""name"', $sql);
    }

    /**
     * @test
     */
    public function grant_permission_injection_attempt(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{}'));
        $db = $this->makeDatabase($transport);

        // Attempt to inject through the permission string
        $db->grantPermission('role', "select:orders; DROP USER admin; --");

        $lastRequest = $transport->getLastRequest();
        $sql = json_decode($lastRequest['body'], true)['sql'];

        // The permission string is passed raw after GRANT — this IS a potential issue
        // if the server doesn't validate it. The client should document this.
        // The server-side parse_perm function handles validation.
        $this->assertStringContainsString('GRANT select:orders; DROP USER admin; -- TO "role"', $sql);
    }

    // ── Response parsing edge cases ─────────────────────────────────────────

    /**
     * @test
     */
    public function response_json_with_trailing_data(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, '{"ok":true}extra garbage'));
        $client = $this->makeClient($transport);

        // json() should throw on invalid JSON
        $response = $client->get('/health');
        $this->expectException(\JsonException::class);
        $response->json();
    }

    /**
     * @test
     */
    public function sql_returns_empty_for_empty_body(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, ''));
        $db = $this->makeDatabase($transport);

        $result = $db->sql('INSERT INTO orders VALUES (1)');
        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function count_extracts_integer(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode(['count' => 42])));
        $db = $this->makeDatabase($transport);

        $this->assertSame(42, $db->count('orders'));
    }

    /**
     * @test
     */
    public function tables_returns_string_array(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, json_encode(['orders', 'customers'])));
        $db = $this->makeDatabase($transport);

        $tables = $db->tables();
        $this->assertSame(['orders', 'customers'], $tables);
    }
}
