<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB;

/**
 * Fluent builder for POST /kit/search — multi-retriever hybrid search with
 * reciprocal-rank fusion and optional exact-vector rerank.
 *
 * Wire format matches the daemon KitSearchRequest (flattened retrievers).
 */
final class SearchBuilder
{
    /** @var list<array<string,mixed>> */
    private array $must = [];
    /** @var list<array<string,mixed>> */
    private array $retrievers = [];
    /** @var array<string,mixed> */
    private array $fusion;
    /** @var array<string,mixed>|null */
    private ?array $rerank = null;
    private int $limit = 10;
    /** @var list<int>|null */
    private ?array $projection = null;
    private bool $explain = false;
    private ?string $cursor = null;

    public function __construct(
        private readonly MongrelDB $client,
        private readonly string $table,
    ) {
        $this->fusion = ['reciprocal_rank' => ['constant' => 60]];
    }

    /**
     * Hard filter (same condition shapes as QueryBuilder).
     *
     * @param array<string,mixed> $params
     */
    public function must(string $type, array $params = []): self
    {
        $this->must[] = [$type => $params];
        return $this;
    }

    /**
     * @param list<float> $query
     */
    public function annRetriever(
        string $name,
        int $columnId,
        array $query,
        int $k = 64,
        float $weight = 1.0,
    ): self {
        $this->retrievers[] = [
            'name' => $name,
            'weight' => $weight,
            'ann' => [
                'column_id' => $columnId,
                'query' => array_values(array_map('floatval', $query)),
                'k' => $k,
            ],
        ];
        return $this;
    }

    /**
     * @param list<array{0:int,1:float}|array{token:int,weight:float}> $terms
     */
    public function sparseRetriever(
        string $name,
        int $columnId,
        array $terms,
        int $k = 64,
        float $weight = 1.0,
    ): self {
        $pairs = [];
        foreach ($terms as $term) {
            if (isset($term['token'])) {
                $pairs[] = [(int) $term['token'], (float) $term['weight']];
            } else {
                $pairs[] = [(int) $term[0], (float) $term[1]];
            }
        }
        $this->retrievers[] = [
            'name' => $name,
            'weight' => $weight,
            'sparse' => [
                'column_id' => $columnId,
                'query' => $pairs,
                'k' => $k,
            ],
        ];
        return $this;
    }

    /**
     * @param list<string> $members
     */
    public function minHashRetriever(
        string $name,
        int $columnId,
        array $members,
        int $k = 64,
        float $weight = 1.0,
    ): self {
        $this->retrievers[] = [
            'name' => $name,
            'weight' => $weight,
            'min_hash' => [
                'column_id' => $columnId,
                'members' => array_values($members),
                'k' => $k,
            ],
        ];
        return $this;
    }

    public function fusion(int $constant = 60): self
    {
        $this->fusion = ['reciprocal_rank' => ['constant' => max(1, $constant)]];
        return $this;
    }

    /**
     * @param list<float> $query
     * @param 'cosine'|'dot_product'|'euclidean' $metric
     */
    public function exactRerank(
        int $embeddingColumn,
        array $query,
        string $metric = 'cosine',
        int $candidateLimit = 64,
        float $weight = 1.0,
    ): self {
        $this->rerank = [
            'exact_vector' => [
                'embedding_column' => $embeddingColumn,
                'query' => array_values(array_map('floatval', $query)),
                'metric' => $metric,
                'candidate_limit' => $candidateLimit,
                'weight' => $weight,
            ],
        ];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * @param list<int> $columnIds
     */
    public function projection(array $columnIds): self
    {
        $this->projection = array_values(array_map('intval', $columnIds));
        return $this;
    }

    public function explain(bool $on = true): self
    {
        $this->explain = $on;
        return $this;
    }

    public function cursor(?string $cursor): self
    {
        $this->cursor = $cursor;
        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        if ($this->retrievers === []) {
            throw new \InvalidArgumentException('search requires at least one retriever');
        }
        if ($this->limit <= 0) {
            throw new \InvalidArgumentException('search limit must be positive');
        }
        $payload = [
            'table' => $this->table,
            'retrievers' => $this->retrievers,
            'fusion' => $this->fusion,
            'limit' => $this->limit,
        ];
        if ($this->must !== []) {
            $payload['must'] = $this->must;
        }
        if ($this->rerank !== null) {
            $payload['rerank'] = $this->rerank;
        }
        if ($this->projection !== null) {
            $payload['projection'] = $this->projection;
        }
        if ($this->explain) {
            $payload['explain'] = true;
        }
        if ($this->cursor !== null && $this->cursor !== '') {
            $payload['cursor'] = $this->cursor;
        }
        return $payload;
    }

    /**
     * @return array{hits: list<array<string,mixed>>, next_cursor?: string, trace?: mixed}
     */
    public function execute(): array
    {
        $response = $this->client->post('/kit/search', $this->build());
        $json = json_decode($response->body, true);
        if (!is_array($json)) {
            return ['hits' => []];
        }
        return $json;
    }
}
