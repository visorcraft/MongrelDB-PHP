<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB;

/** GET /queries/{query_id} decoded status. */
final class QueryStatus
{
    public function __construct(
        public readonly string $queryId,
        public readonly string $status,
        public readonly string $state,
        public readonly string $serverState,
        public readonly ?string $terminalState,
        public readonly ?bool $committed,
        public readonly DurableOutcome $outcome,
        public readonly ?DurableOutcome $durable,
        public readonly ?CommitHlc $lastCommitHlc,
        /** @var array<string,mixed> */
        public readonly array $raw,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $outcome = DurableOutcome::fromArray(is_array($raw['outcome'] ?? null) ? $raw['outcome'] : []);
        $durable = isset($raw['durable']) && is_array($raw['durable'])
            ? DurableOutcome::fromArray($raw['durable'])
            : null;
        $topHlc = isset($raw['last_commit_hlc']) && is_array($raw['last_commit_hlc'])
            ? CommitHlc::fromArray($raw['last_commit_hlc'])
            : null;

        return new self(
            queryId: (string) ($raw['query_id'] ?? ''),
            status: (string) ($raw['status'] ?? ''),
            state: (string) ($raw['state'] ?? ''),
            serverState: (string) ($raw['server_state'] ?? $raw['state'] ?? ''),
            terminalState: isset($raw['terminal_state']) ? (string) $raw['terminal_state'] : null,
            committed: array_key_exists('committed', $raw)
                ? ($raw['committed'] === null ? null : (bool) $raw['committed'])
                : null,
            outcome: $outcome,
            durable: $durable,
            lastCommitHlc: $topHlc,
            raw: $raw,
        );
    }

    public function commitHlc(): ?CommitHlc
    {
        return $this->durable?->lastCommitHlc
            ?? $this->outcome->lastCommitHlc
            ?? $this->lastCommitHlc;
    }

    public function serializationState(): string
    {
        return $this->durable?->serializationState
            ?? $this->outcome->serializationState
            ?? $this->durable?->serialization
            ?? $this->outcome->serialization
            ?? '';
    }
}
