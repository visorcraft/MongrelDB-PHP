<?php

declare(strict_types=1);

/**
 * HTTP transport conformance tests for the mongreldb-server contract.
 *
 * Asserts on-wire requirements the server enforces that mock-transport tests
 * alone cannot catch (Content-Type headers, method, path). These are offline
 * (mock transport) but assert the exact request the client emits.
 */

namespace Visorcraft\MongrelDB\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\MongrelDB;
use Visorcraft\MongrelDB\Transport\Response;

final class HttpConformanceTest extends TestCase
{
    /**
     * Build a client whose transport records the last request and returns a
     * configurable canned response body.
     */
    private function recordingClient(
        ?array &$captured,
        int $status = 200,
        string $body = '{}',
    ): MongrelDB {
        $captured = null;
        $transport = new class ($captured, $status, $body) implements \Visorcraft\MongrelDB\Transport\TransportInterface {
            public function __construct(
                public ?array &$cap,
                public int $responseStatus,
                public string $responseBody,
            ) {}

            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null,
            ): Response {
                $this->cap = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                    'body' => $body,
                ];

                return new Response($this->responseStatus, $this->responseBody);
            }
        };

        return new MongrelDB('http://127.0.0.1:8453', transport: $transport);
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === strtolower($name)) {
                return $v;
            }
        }

        return null;
    }

    #[Test]
    public function post_with_body_sends_content_type_json(): void
    {
        $client = $this->recordingClient($cap);
        $client->post('/sql', ['sql' => 'SELECT 1']);

        $this->assertSame(
            'application/json',
            $this->headerValue($cap['headers'], 'Content-Type'),
        );
    }

    #[Test]
    public function put_with_body_sends_content_type_json(): void
    {
        $client = $this->recordingClient($cap);
        $client->put('/procedures/p', ['procedure' => ['name' => 'p']]);

        $this->assertSame(
            'application/json',
            $this->headerValue($cap['headers'], 'Content-Type'),
        );
    }

    #[Test]
    public function get_does_not_send_content_type(): void
    {
        $client = $this->recordingClient($cap);
        $client->get('/health');

        $this->assertNull($this->headerValue($cap['headers'], 'Content-Type'));
    }

    #[Test]
    public function delete_does_not_send_content_type(): void
    {
        $client = $this->recordingClient($cap);
        $client->delete('/tables/foo');

        $this->assertNull($this->headerValue($cap['headers'], 'Content-Type'));
    }

    #[Test]
    public function user_supplied_content_type_is_respected(): void
    {
        $client = $this->recordingClient($cap);
        $client->post('/sql', ['sql' => 'SELECT 1'], ['Content-Type' => 'application/vnd.custom']);

        $this->assertSame(
            'application/vnd.custom',
            $this->headerValue($cap['headers'], 'Content-Type'),
        );
    }

    #[Test]
    public function sql_uses_post_to_sql_path(): void
    {
        $client = $this->recordingClient($cap);
        $db = new Database(client: $client);
        $db->sql('SELECT 1');

        $this->assertSame('POST', $cap['method']);
        $this->assertStringEndsWith('/sql', $cap['url']);
    }

    #[Test]
    public function history_retention_get_uses_correct_path_and_parses_both_keys(): void
    {
        $retentionResponse = '{"history_retention_epochs":100,"earliest_retained_epoch":7}';

        $client = $this->recordingClient($cap, 200, $retentionResponse);
        $db = new Database(client: $client);

        $this->assertSame(100, $db->historyRetentionEpochs());
        $this->assertSame('GET', $cap['method']);
        $this->assertStringEndsWith('/history/retention', $cap['url']);

        $client = $this->recordingClient($cap, 200, $retentionResponse);
        $db = new Database(client: $client);
        $this->assertSame(7, $db->earliestRetainedEpoch());
        $this->assertSame('GET', $cap['method']);
        $this->assertStringEndsWith('/history/retention', $cap['url']);
    }

    #[Test]
    public function set_history_retention_uses_put_with_single_key(): void
    {
        $client = $this->recordingClient($cap, 200, '{"history_retention_epochs":200}');
        $db = new Database(client: $client);

        $result = $db->setHistoryRetentionEpochs(200);
        $this->assertSame('PUT', $cap['method']);
        $this->assertStringEndsWith('/history/retention', $cap['url']);

        $body = json_decode($cap['body'] ?? '', true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(200, $body['history_retention_epochs']);
        $this->assertArrayNotHasKey('earliest_retained_epoch', $body);
        $this->assertSame(['history_retention_epochs' => 200, 'earliest_retained_epoch' => 0], $result);
    }
}
