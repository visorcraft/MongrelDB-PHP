<?php

declare(strict_types=1);

/**
 * Wire-conformance tests for the QueryBuilder against the mongreldb-server
 * /kit/query contract.
 *
 * These tests assert the EXACT on-wire JSON keys the server expects (per the
 * server's JsonCondition enum in crates/mongreldb-server/src/kit.rs), not just
 * that the client serializes "something". They exist precisely because the
 * mock-transport tests could not catch a column/column_id naming mismatch.
 *
 * Every condition is built via the builder, the payload is captured from the
 * mock transport, and the exact condition object is compared.
 *
 * Run: phpunit tests/QueryBuilderConformanceTest.php
 */

namespace Visorcraft\MongrelDB\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\MongrelDB;
use Visorcraft\MongrelDB\Transport\Response;

/** @phan-suppress-next-line PhanUnreferencedUseNormal */
final class QueryBuilderConformanceTest extends TestCase
{
    private function firstCondition(array $payload): array
    {
        return $payload['conditions'][0];
    }

    // ── column reference key ────────────────────────────────────────────────

    #[Test]
    public function pk_condition_shape(): void
    {
        $payload = $this->buildPayload(fn ($q) => $q->where('pk', ['value' => 42]));
        $this->assertSame(
            ['pk' => ['value' => 42]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function bitmap_eq_translates_column_alias_to_column_id(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('bitmap_eq', ['column' => 2, 'value' => 'Alice']),
        );
        $this->assertSame(
            ['bitmap_eq' => ['column_id' => 2, 'value' => 'Alice']],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function bitmap_eq_accepts_canonical_column_id_directly(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('bitmap_eq', ['column_id' => 2, 'value' => 'Alice']),
        );
        $this->assertSame(
            ['bitmap_eq' => ['column_id' => 2, 'value' => 'Alice']],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function bitmap_in_translates_column_alias(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('bitmap_in', ['column' => 2, 'values' => ['a', 'b']]),
        );
        $this->assertSame(
            ['bitmap_in' => ['column_id' => 2, 'values' => ['a', 'b']]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function range_translates_min_max_to_lo_hi(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('range', ['column' => 3, 'min' => 0, 'max' => 100]),
        );
        // Server's Range variant: {column_id, lo, hi} (i64, inclusive)
        $this->assertSame(
            ['range' => ['column_id' => 3, 'lo' => 0, 'hi' => 100]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function range_accepts_canonical_lo_hi_directly(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('range', ['column_id' => 3, 'lo' => 0, 'hi' => 100]),
        );
        $this->assertSame(
            ['range' => ['column_id' => 3, 'lo' => 0, 'hi' => 100]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function range_f64_translates_bounds_and_inclusivity(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('range_f64', [
                'column' => 3,
                'min' => 0.5,
                'min_inclusive' => false,
                'max' => 100.5,
                'max_inclusive' => true,
            ]),
        );
        // Server's RangeF64: {column_id, lo, lo_inclusive, hi, hi_inclusive}
        $this->assertSame(
            ['range_f64' => [
                'column_id' => 3,
                'lo' => 0.5,
                'lo_inclusive' => false,
                'hi' => 100.5,
                'hi_inclusive' => true,
            ]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function is_null_translates_column_alias(): void
    {
        $payload = $this->buildPayload(fn ($q) => $q->where('is_null', ['column' => 4]));
        $this->assertSame(
            ['is_null' => ['column_id' => 4]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function is_not_null_uses_column_id(): void
    {
        $payload = $this->buildPayload(fn ($q) => $q->where('is_not_null', ['column' => 4]));
        $this->assertSame(
            ['is_not_null' => ['column_id' => 4]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function fm_contains_translates_column_alias(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('fm_contains', ['column' => 2, 'pattern' => 'hello']),
        );
        // Server: {column_id, pattern: String}
        $this->assertSame(
            ['fm_contains' => ['column_id' => 2, 'pattern' => 'hello']],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function fm_contains_accepts_value_as_pattern_alias(): void
    {
        // The docs historically used 'value' for the FTS pattern; accept it as
        // an alias for the server's 'pattern' key.
        $payload = $this->buildPayload(
            fn ($q) => $q->where('fm_contains', ['column' => 2, 'value' => 'hello']),
        );
        $this->assertSame(
            ['fm_contains' => ['column_id' => 2, 'pattern' => 'hello']],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function fm_contains_all_translates_column_alias(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('fm_contains_all', ['column' => 2, 'patterns' => ['a', 'b']]),
        );
        $this->assertSame(
            ['fm_contains_all' => ['column_id' => 2, 'patterns' => ['a', 'b']]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function ann_translates_column_alias(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('ann', ['column' => 5, 'query' => [0.1, 0.2], 'k' => 10]),
        );
        // Server: {column_id, query: Vec<f32>, k: usize}
        $this->assertSame(
            ['ann' => ['column_id' => 5, 'query' => [0.1, 0.2], 'k' => 10]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function sparse_match_translates_column_alias(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('sparse_match', [
                'column' => 5,
                'query' => [[1, 0.5], [3, 0.9]],
                'k' => 10,
            ]),
        );
        $this->assertSame(
            ['sparse_match' => ['column_id' => 5, 'query' => [[1, 0.5], [3, 0.9]], 'k' => 10]],
            $this->firstCondition($payload),
        );
    }

    #[Test]
    public function min_hash_similar_translates_column_alias(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('min_hash_similar', ['column' => 5, 'query' => [1, 2, 3], 'k' => 10]),
        );
        $this->assertSame(
            ['min_hash_similar' => ['column_id' => 5, 'query' => [1, 2, 3], 'k' => 10]],
            $this->firstCondition($payload),
        );
    }

    // ── Request envelope shape ──────────────────────────────────────────────

    #[Test]
    public function projection_and_limit_use_canonical_keys(): void
    {
        $payload = $this->buildPayload(
            fn ($q) => $q->where('pk', ['value' => 1])->projection([1, 2])->limit(50),
        );
        $this->assertSame('orders', $payload['table']);
        $this->assertSame([['pk' => ['value' => 1]]], $payload['conditions']);
        $this->assertSame([1, 2], $payload['projection']);
        $this->assertSame(50, $payload['limit']);
    }

    #[Test]
    public function conditions_omitted_when_empty(): void
    {
        $payload = $this->buildPayload(fn ($q) => $q);
        $this->assertArrayNotHasKey('conditions', $payload);
        $this->assertArrayNotHasKey('projection', $payload);
        $this->assertArrayNotHasKey('limit', $payload);
        $this->assertSame('orders', $payload['table']);
    }

    // ── truncated flag ──────────────────────────────────────────────────────

    #[Test]
    public function execute_surfaces_truncated_flag(): void
    {
        $transport = new class implements \Visorcraft\MongrelDB\Transport\TransportInterface {
            public function request(string $m, string $u, array $h = [], ?string $b = null): Response
            {
                return new Response(200, '{"rows":[],"truncated":true}');
            }
        };
        $db = new Database(client: new MongrelDB('http://x', transport: $transport));
        $q = $db->query('orders')->where('pk', ['value' => 1])->limit(1);
        $rows = $q->execute();

        $this->assertSame([], $rows);
        $this->assertTrue($q->truncated());
    }

    #[Test]
    public function truncated_defaults_false_when_absent_in_response(): void
    {
        $transport = new class implements \Visorcraft\MongrelDB\Transport\TransportInterface {
            public function request(string $m, string $u, array $h = [], ?string $b = null): Response
            {
                return new Response(200, '{"rows":[]}');  // no truncated key
            }
        };
        $db = new Database(client: new MongrelDB('http://x', transport: $transport));
        $q = $db->query('orders');
        $q->execute();

        $this->assertFalse($q->truncated());
    }

    // ── Helper: build payload via a direct builder + mock transport ─────────

    /**
     * Configure a builder through the callback and capture the decoded request
     * body that execute() would send.
     *
     * @param callable(\Visorcraft\MongrelDB\QueryBuilder): void $configure
     *
     * @return array<string,mixed>
     */
    private function buildPayload(callable $configure): array
    {
        $transport = new class implements \Visorcraft\MongrelDB\Transport\TransportInterface {
            public ?string $body = null;

            public function request(string $m, string $u, array $h = [], ?string $b = null): Response
            {
                $this->body = $b;

                return new Response(200, '{"rows":[],"truncated":false}');
            }
        };
        $client = new MongrelDB('http://127.0.0.1:8453', transport: $transport);
        $db = new Database(client: $client);

        // build() produces the payload without sending; use it to avoid needing
        // execute() to complete for a pure shape assertion.
        $reflection = new \ReflectionMethod($db, 'query');
        $query = $reflection->invoke($db, 'orders');
        $configure($query);

        $build = new \ReflectionMethod($query, 'build');

        return $build->invoke($query);
    }
}
