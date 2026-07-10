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
    public function create_table_preserves_enum_variants_and_default_value_keys(): void
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
}
