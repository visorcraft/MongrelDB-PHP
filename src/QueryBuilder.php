<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB;

/**
 * Fluent query builder for the Kit /kit/query endpoint.
 *
 * Provides a chainable API for building native conditions (bitmap, range,
 * vector similarity, etc.) that push down to the engine's indexes.
 *
 * Condition parameters use friendly aliases that are translated to the server's
 * exact on-wire keys before sending (see {@see normalizeCondition()}):
 *   - `column`        -> `column_id`
 *   - `min`/`max`     -> `lo`/`hi`
 *   - `min_inclusive` -> `lo_inclusive`, `max_inclusive` -> `hi_inclusive`
 *
 * The server's canonical keys (`column_id`, `lo`, `hi`, `lo_inclusive`, ...)
 * are also accepted directly, so callers can pass the exact wire shape.
 *
 * @example
 * ```php
 * $query = $db->query('orders')
 *     ->where('bitmap_eq', ['column' => 2, 'value' => 'electronics'])
 *     ->where('range', ['column' => 3, 'min' => 100.0])
 *     ->projection([1, 2, 3])
 *     ->limit(50);
 *
 * $rows = $query->execute();           // array of rows
 * if ($query->truncated()) {           // true if the result hit the limit
 *     // result set was capped
 * }
 * ```
 */
final class QueryBuilder
{
    /** @var array<int, array<string,mixed>> Conditions (AND-ed) */
    private array $conditions = [];

    /** @var ?array<int,int> Column IDs to project (null = all) */
    private ?array $projection = null;

    private ?int $limit = null;

    /** Whether the last execute() result was truncated by the limit. */
    private bool $lastTruncated = false;

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
     * - pk: exact primary key match (`['value' => $pk]`)
     * - bitmap_eq: equality on a bitmap-indexed column
     * - bitmap_in: IN predicate on a bitmap-indexed column
     * - range: integer range predicate (`lo`/`hi`, inclusive)
     * - range_f64: float range predicate (`lo`/`hi` + `lo_inclusive`/`hi_inclusive`)
     * - is_null / is_not_null: null checks
     * - fm_contains: full-text substring search (FM-index)
     * - fm_contains_all: multiple substring patterns (all must match)
     * - ann: dense vector similarity search (HNSW)
     * - sparse_match: sparse vector match
     * - min_hash_similar: MinHash similarity search
     *
     * Friendly aliases `column` (-> `column_id`) and `min`/`max` (-> `lo`/`hi`)
     * are accepted; the server's canonical keys are also accepted as-is.
     *
     * @param string               $type   Condition type
     * @param array<string,mixed>  $params Condition parameters
     */
    public function where(string $type, array $params): static
    {
        $this->conditions[] = [$type => $this->normalizeCondition($type, $params)];

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
        $data = is_array($data) ? $data : [];

        $this->lastTruncated = (bool) ($data['truncated'] ?? false);

        return $data['rows'] ?? [];
    }

    /**
     * Whether the most recent {@see execute()} result was truncated by the
     * query limit. Returns false until execute() has been called.
     */
    public function truncated(): bool
    {
        return $this->lastTruncated;
    }

    /**
     * Translate friendly parameter aliases to the server's canonical on-wire
     * keys. Both spellings are accepted so callers may use whichever is clearer.
     *
     * Generic aliases (applied to all condition types):
     *   column         -> column_id
     *   min            -> lo
     *   max            -> hi
     *   min_inclusive  -> lo_inclusive
     *   max_inclusive  -> hi_inclusive
     *
     * Type-specific aliases:
     *   fm_contains / fm_contains_all: `value` -> `pattern`
     *   (other types like pk/bitmap_eq use `value` as their canonical key, so
     *   the value->pattern alias must NOT apply globally)
     *
     * @param string               $type   Condition type
     * @param array<string,mixed>  $params
     *
     * @return array<string,mixed>
     */
    private function normalizeCondition(string $type, array $params): array
    {
        $aliases = [
            'column' => 'column_id',
            'min' => 'lo',
            'max' => 'hi',
            'min_inclusive' => 'lo_inclusive',
            'max_inclusive' => 'hi_inclusive',
        ];

        // The docs historically used 'value' for the FTS pattern; the server's
        // JsonCondition::FmContains key is 'pattern'. Only apply this for FTS
        // conditions, since pk/bitmap_eq use 'value' canonically.
        if ($type === 'fm_contains' || $type === 'fm_contains_all') {
            $aliases['value'] = 'pattern';
        }

        $normalized = [];
        foreach ($params as $key => $value) {
            $normalized[$aliases[$key] ?? $key] = $value;
        }

        return $normalized;
    }
}
