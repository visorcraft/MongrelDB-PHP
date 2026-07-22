<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\MongrelDB;
use Visorcraft\MongrelDB\QueryStatus;
use Visorcraft\MongrelDB\SearchBuilder;
use Visorcraft\MongrelDB\Transport\Response;
use Visorcraft\MongrelDB\Transport\TransportInterface;

final class DurableRetrieveTest extends TestCase
{
    #[Test]
    public function query_status_parses_structural_hlc_without_string_status_parsing(): void
    {
        $fixture = [
            'query_id' => 'abcdefabcdefabcdefabcdefabcdefab',
            'status' => 'committed',
            'state' => 'completed',
            'server_state' => 'completed',
            'terminal_state' => 'committed',
            'committed' => true,
            'committed_statements' => 1,
            'last_commit_epoch' => 17,
            'last_commit_epoch_text' => '17',
            'last_commit_hlc' => [
                'physical_micros' => 1700000000000000,
                'logical' => 3,
                'node_tiebreaker' => 7,
            ],
            'first_commit_statement_index' => 0,
            'last_commit_statement_index' => 0,
            'completed_statements' => 1,
            'statement_index' => 0,
            'cancellation_reason' => 'none',
            'retryable' => false,
            'outcome' => [
                'committed' => true,
                'committed_statements' => 1,
                'last_commit_epoch' => 17,
                'last_commit_epoch_text' => '17',
                'last_commit_hlc' => [
                    'physical_micros' => 1700000000000000,
                    'logical' => 3,
                    'node_tiebreaker' => 7,
                ],
                'first_commit_statement_index' => 0,
                'last_commit_statement_index' => 0,
                'completed_statements' => 1,
                'statement_index' => 0,
                'serialization' => 'succeeded',
                'serialization_state' => 'succeeded',
            ],
            'durable' => [
                'committed' => true,
                'committed_statements' => 1,
                'last_commit_epoch' => 17,
                'last_commit_epoch_text' => '17',
                'last_commit_hlc' => [
                    'physical_micros' => 1700000000000000,
                    'logical' => 3,
                    'node_tiebreaker' => 7,
                ],
                'first_commit_statement_index' => 0,
                'last_commit_statement_index' => 0,
                'completed_statements' => 1,
                'statement_index' => 0,
                'serialization' => 'succeeded',
                'serialization_state' => 'succeeded',
            ],
        ];

        $status = QueryStatus::fromArray($fixture);
        $this->assertTrue($status->committed);
        $hlc = $status->commitHlc();
        $this->assertNotNull($hlc);
        $this->assertSame(1700000000000000, $hlc->physicalMicros);
        $this->assertSame(3, $hlc->logical);
        $this->assertSame(7, $hlc->nodeTiebreaker);
        $this->assertSame('succeeded', $status->serializationState());
        $this->assertSame(17, $status->outcome->lastCommitEpoch);
    }

    #[Test]
    public function retrieve_text_posts_kit_body_through_real_client(): void
    {
        $captured = null;
        $transport = new class ($captured) implements TransportInterface {
            public function __construct(private ?array &$captured) {}

            #[\Override]
            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null,
            ): Response {
                $this->captured = [
                    'method' => $method,
                    'url' => $url,
                    'body' => $body,
                ];

                return new Response(200, json_encode([
                    'hits' => [['row_id' => '1', 'rank' => 1, 'score' => ['ann_cosine_distance' => 0.1]]],
                    'provenance' => [
                        'embedding_column' => 3,
                        'provider_registry_generation' => 1,
                        'query_source_fingerprint' => 'ab',
                        'semantic_identity' => ['provider_id' => 'fixed', 'dimension' => 2],
                    ],
                ], JSON_THROW_ON_ERROR), ['content-type' => 'application/json']);
            }
        };

        $db = new Database(client: new MongrelDB('http://127.0.0.1:8453', transport: $transport));
        $result = $db->retrieveText('docs', 3, 'cat', ['k' => 5]);
        $this->assertSame('POST', $captured['method']);
        $this->assertStringContainsString('/kit/retrieve_text', $captured['url']);
        $payload = json_decode((string) $captured['body'], true);
        $this->assertSame('docs', $payload['table']);
        $this->assertSame(3, $payload['embedding_column']);
        $this->assertSame('cat', $payload['text']);
        $this->assertSame(5, $payload['k']);
        $this->assertCount(1, $result['hits']);
        $this->assertSame(3, $result['provenance']['embedding_column']);
    }

    #[Test]
    public function multi_retriever_search_wire_includes_two_retrievers_and_fusion(): void
    {
        $captured = null;
        $transport = new class ($captured) implements TransportInterface {
            public function __construct(private ?array &$captured) {}

            #[\Override]
            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null,
            ): Response {
                $this->captured = ['method' => $method, 'url' => $url, 'body' => $body];

                return new Response(200, '{"hits":[]}', ['content-type' => 'application/json']);
            }
        };
        $db = new Database(client: new MongrelDB('http://127.0.0.1:8453', transport: $transport));
        $db->search('docs')
            ->annRetriever('ann', 3, [0.1, 0.2], 10, 1.0)
            ->sparseRetriever('sparse', 4, [[1, 0.5]], 10, 0.5)
            ->fusion(60)
            ->limit(5)
            ->execute();
        $this->assertStringContainsString('/kit/search', $captured['url']);
        $payload = json_decode((string) $captured['body'], true);
        $this->assertCount(2, $payload['retrievers']);
        $this->assertArrayHasKey('fusion', $payload);
    }
}
