<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB;

/**
 * Batch transaction builder for atomic multi-operation commits.
 *
 * All operations are staged locally and sent to the daemon in a single
 * /kit/txn request. The engine enforces constraints (unique, FK, check,
 * triggers) atomically at commit time.
 *
 * @example
 * ```php
 * $txn = $db->beginTransaction();
 * $txn->put('orders', [1 => 1, 2 => 'Alice']);
 * $txn->put('orders', [1 => 2, 2 => 'Bob']);
 * $txn->deleteByPk('orders', 99);
 * $results = $txn->commit(); // atomic — all or nothing
 * ```
 */
final class Transaction
{
    /** @var array<int,array<string,mixed>> Staged operations */
    private array $ops = [];

    private bool $committed = false;

    public function __construct(
        private readonly MongrelDB $client,
    ) {}

    /**
     * Stage a put (insert) operation.
     *
     * @param string           $table  Table name
     * @param array<int,mixed> $cells  Column ID → value pairs
     * @param bool             $returning Whether to return the row in the result
     */
    public function put(string $table, array $cells, bool $returning = false): static
    {
        $this->ops[] = [
            'put' => [
                'table' => $table,
                'cells' => $this->cellsToFlat($cells),
                'returning' => $returning,
            ],
        ];

        return $this;
    }

    /**
     * Stage an upsert (insert-or-update) operation.
     *
     * @param string           $table        Table name
     * @param array<int,mixed> $cells        Column ID → value pairs (insert values)
     * @param ?array<int,mixed> $updateCells Update values on PK conflict (null = DO NOTHING)
     * @param bool             $returning    Whether to return the row
     */
    public function upsert(string $table, array $cells, ?array $updateCells = null, bool $returning = false): static
    {
        $op = [
            'table' => $table,
            'cells' => $this->cellsToFlat($cells),
            'returning' => $returning,
        ];

        if ($updateCells !== null) {
            $op['update_cells'] = $this->cellsToFlat($updateCells);
        }

        $this->ops[] = ['upsert' => $op];

        return $this;
    }

    /**
     * Stage a delete by internal row ID.
     *
     * @param string $table Table name
     * @param int    $rowId Internal row ID
     */
    public function delete(string $table, int $rowId): static
    {
        $this->ops[] = [
            'delete' => [
                'table' => $table,
                'row_id' => $rowId,
            ],
        ];

        return $this;
    }

    /**
     * Stage a delete by primary key value.
     *
     * @param string $table Table name
     * @param mixed  $pk    Primary key value
     */
    public function deleteByPk(string $table, mixed $pk): static
    {
        $this->ops[] = [
            'delete_by_pk' => [
                'table' => $table,
                'pk' => $pk,
            ],
        ];

        return $this;
    }

    /**
     * Get the number of staged operations.
     */
    public function count(): int
    {
        return count($this->ops);
    }

    /**
     * Commit all staged operations atomically.
     *
     * @param ?string $idempotencyKey Optional idempotency key for safe retries
     *
     * @return array<int,array<string,mixed>> Per-operation results
     *
     * @throws \Visorcraft\MongrelDB\Exceptions\ConstraintException On constraint violation (all ops rolled back)
     * @throws \Visorcraft\MongrelDB\Exceptions\MongrelDBException  On other errors
     */
    public function commit(?string $idempotencyKey = null): array
    {
        if ($this->committed) {
            throw new \LogicException('Transaction already committed');
        }

        if ($this->ops === []) {
            $this->committed = true;

            return [];
        }

        $payload = ['ops' => $this->ops];

        if ($idempotencyKey !== null) {
            $payload['idempotency_key'] = $idempotencyKey;
        }

        $response = $this->client->post('/kit/txn', $payload);
        $this->committed = true;
        $data = $response->json();

        return is_array($data) ? ($data['results'] ?? []) : [];
    }

    /**
     * Rollback (discard all staged operations).
     */
    public function rollback(): void
    {
        if ($this->committed) {
            throw new \LogicException('Cannot rollback a committed transaction');
        }

        $this->ops = [];
    }

    /**
     * Convert associative [col_id => val] to flat [col_id, val, ...].
     *
     * @param array<int,mixed> $cells
     *
     * @return array<int,mixed>
     */
    private function cellsToFlat(array $cells): array
    {
        $flat = [];
        foreach ($cells as $colId => $value) {
            $flat[] = $colId;
            $flat[] = $value;
        }

        return $flat;
    }
}
