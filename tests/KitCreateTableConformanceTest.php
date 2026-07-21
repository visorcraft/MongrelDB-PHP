<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\MongrelDB;
use Visorcraft\MongrelDB\Transport\Response;
use Visorcraft\MongrelDB\Transport\TransportInterface;

final class KitCreateTableConformanceTest extends TestCase
{
    private function recordingDatabase(?array &$captured): Database
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
                    'headers' => $headers,
                    'body' => $body,
                ];

                return new Response(200, '{"table_id":7}', ['content-type' => 'application/json']);
            }
        };

        return new Database(client: new MongrelDB('http://127.0.0.1:8453', transport: $transport));
    }

    #[Test]
    public function create_table_preserves_columns_and_check_constraints(): void
    {
        $db = $this->recordingDatabase($captured);

        $tableId = $db->createTable('orders', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            [
                'id' => 2,
                'name' => 'status',
                'ty' => 'enum',
                'primary_key' => false,
                'nullable' => false,
                'enum_variants' => ['new', 'paid', 'cancelled'],
            ],
            [
                'id' => 3,
                'name' => 'created_at',
                'ty' => 'varchar',
                'primary_key' => false,
                'nullable' => false,
                'default_value' => 'now',
            ],
            ['id' => 4, 'name' => 'retries', 'ty' => 'int64', 'default_value' => 3],
            ['id' => 5, 'name' => 'enabled', 'ty' => 'bool', 'default_value' => true],
            ['id' => 6, 'name' => 'optional', 'ty' => 'varchar', 'default_value' => null],
            ['id' => 7, 'name' => 'tag', 'ty' => 'varchar', 'default_value' => 'uuid'],
            ['id' => 8, 'name' => 'updated_at', 'ty' => 'timestamp', 'default_expr' => 'now'],
            ['id' => 9, 'name' => 'uuid_col', 'ty' => 'varchar', 'default_expr' => 'uuid'],
        ], [
            'checks' => [[
                'id' => 1,
                'name' => 'ck_status',
                'expr' => ['IsNotNull' => 2],
            ]],
        ]);

        $this->assertSame(7, $tableId);
        $this->assertSame('POST', $captured['method']);
        $this->assertStringEndsWith('/kit/create_table', $captured['url']);
        $this->assertIsString($captured['body']);
        $this->assertStringContainsString('"enum_variants":["new","paid","cancelled"]', $captured['body']);
        $this->assertStringContainsString('"default_value":"now"', $captured['body']);

        $body = json_decode($captured['body'], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['new', 'paid', 'cancelled'], $body['columns'][1]['enum_variants']);
        $this->assertSame('now', $body['columns'][2]['default_value']);
        $this->assertSame(3, $body['columns'][3]['default_value']);
        $this->assertTrue($body['columns'][4]['default_value']);
        $this->assertNull($body['columns'][5]['default_value']);
        $this->assertSame('uuid', $body['columns'][6]['default_value']);
        $this->assertSame('now', $body['columns'][7]['default_expr']);
        $this->assertSame('uuid', $body['columns'][8]['default_expr']);
        $this->assertSame('ck_status', $body['constraints']['checks'][0]['name']);
        $this->assertSame(['IsNotNull' => 2], $body['constraints']['checks'][0]['expr']);
    }

    #[Test]
    public function create_table_does_not_emit_unset_optional_column_keys(): void
    {
        $db = $this->recordingDatabase($captured);

        $db->createTable('plain_orders', [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true, 'nullable' => false],
            ['id' => 2, 'name' => 'amount', 'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
        ]);

        $this->assertIsString($captured['body']);
        $this->assertStringNotContainsString('"enum_variants"', $captured['body']);
        $this->assertStringNotContainsString('"default_value"', $captured['body']);

        $body = json_decode($captured['body'], true, 512, \JSON_THROW_ON_ERROR);
        foreach ($body['columns'] as $column) {
            $this->assertArrayNotHasKey('enum_variants', $column);
            $this->assertArrayNotHasKey('default_value', $column);
        }
    }

    #[Test]
    public function create_table_preserves_all_indexes_and_embedding_source(): void
    {
        $db = $this->recordingDatabase($captured);
        $columns = [
            ['id' => 1, 'name' => 'id', 'ty' => 'int64', 'primary_key' => true],
            ['id' => 2, 'name' => 'embedding', 'ty' => 'embedding(384)',
                'embedding_source' => ['kind' => 'configured_model', 'provider_id' => 'docs',
                    'model_id' => 'model', 'model_version' => '1']],
        ];
        $indexes = [
            ['name' => 'bm', 'column_id' => 1, 'kind' => 'bitmap'],
            ['name' => 'fm', 'column_id' => 1, 'kind' => 'fm_index'],
            ['name' => 'ann', 'column_id' => 2, 'kind' => 'ann',
                'predicate' => 'embedding IS NOT NULL',
                'options' => ['ann' => ['m' => 24, 'ef_construction' => 96,
                    'ef_search' => 48, 'quantization' => 'dense']]],
            ['name' => 'range', 'column_id' => 1, 'kind' => 'learned_range'],
            ['name' => 'minhash', 'column_id' => 1, 'kind' => 'minhash'],
            ['name' => 'sparse', 'column_id' => 1, 'kind' => 'sparse'],
        ];

        $this->assertSame(7, $db->createTable('search_docs', $columns, [], $indexes));
        $body = json_decode($captured['body'], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('configured_model', $body['columns'][1]['embedding_source']['kind']);
        $this->assertSame(
            ['bitmap', 'fm_index', 'ann', 'learned_range', 'minhash', 'sparse'],
            array_column($body['indexes'], 'kind'),
        );
        $this->assertSame('dense', $body['indexes'][2]['options']['ann']['quantization']);
        $this->assertSame('embedding IS NOT NULL', $body['indexes'][2]['predicate']);
    }
}
