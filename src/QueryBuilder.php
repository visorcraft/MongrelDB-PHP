<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB;

/**
 * Fluent query builder for the Kit /kit/query endpoint.
 *
 * Provides a chainable API for building native conditions (bitmap, range,
 * vector similarity, etc.) that push down to the engine's indexes.
 *
 * @example
 * ```php
 * $results = $db->query('orders')
 *     ->where('bitmap_eq', ['column' => 2, 'value' => 'electronics'])
 *     ->where('range', ['column' => 3, 'min' => 100.0])
 *     ->projection([1, 2, 3])
 *     ->limit(50)
 *     ->execute();
 * ```
 */
final class QueryBuilder
{
    /** @var array<int, array<string,mixed>> Conditions (AND-ed) */
    private array $conditions = [];

    /** @var ?array<int,int> Column IDs to project (null = all) */
    private ?array $projection = null;

    private ?int $limit = null;

    /**
     * @param MongrelDB $client The HTTP client
     * @param string    $table  Table name
     */
    public function __construct(
        private readonly MongrelDB $client,
        private readonly string $table,
    ) {}

    /**
     * Add a native condition. Available condition types:
     * - pk: exact primary key match
     * - bitmap_eq: equality on a bitmap-indexed column
     * - bitmap_in: IN predicate on a bitmap-indexed column
     * - range: range predicate (min/max on a learned-range index)
     * - is_null / is_not_null: null checks
     * - fm_contains: full-text substring search (FM-index)
     * - ann: vector similarity search (HNSW)
     * - sparse_match: sparse vector match
     *
     * @param string               $type  Condition type
     * @param array<string,mixed>  $params Condition parameters
     */
    public function where(string $type, array $params): static
    {
        $this->conditions[] = [$type => $params];

        return $this;
    }

    /**
     * Set the column projection (column IDs to return).
     *
     * @param array<int,int> $columnIds Column IDs to project
     */
    public function projection(array $columnIds): static
    {
        $this->projection = $columnIds;

        return $this;
    }

    /**
     * Set the row limit.
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Build the request payload.
     *
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $payload = ['table' => $this->table];

        if ($this->conditions !== []) {
            // The daemon expects externally-tagged conditions: [{type: {...}}, ...]
            // Our where() already stores them in that format
            $payload['conditions'] = $this->conditions;
        }

        if ($this->projection !== null) {
            $payload['projection'] = $this->projection;
        }

        if ($this->limit !== null) {
            $payload['limit'] = $this->limit;
        }

        return $payload;
    }

    /**
     * Execute the query and return rows.
     *
     * @return array<int,array<string,mixed>> Query result rows
     */
    public function execute(): array
    {
        $response = $this->client->post('/kit/query', $this->build());
        $data = $response->json();

        return $data['rows'] ?? [];
    }
}
