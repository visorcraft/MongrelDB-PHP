<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB;

/** Nested durable recovery payload (server outcome/durable JSON). */
final class DurableOutcome
{
    public function __construct(
        public readonly ?bool $committed,
        public readonly ?int $committedStatements,
        public readonly ?int $lastCommitEpoch,
        public readonly ?string $lastCommitEpochText,
        public readonly ?CommitHlc $lastCommitHlc,
        public readonly ?int $firstCommitStatementIndex,
        public readonly ?int $lastCommitStatementIndex,
        public readonly ?int $completedStatements,
        public readonly ?int $statementIndex,
        public readonly string $serialization,
        public readonly ?string $serializationState,
        public readonly ?string $terminalState,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $hlc = isset($raw['last_commit_hlc']) && is_array($raw['last_commit_hlc'])
            ? CommitHlc::fromArray($raw['last_commit_hlc'])
            : null;

        return new self(
            committed: array_key_exists('committed', $raw) ? ($raw['committed'] === null ? null : (bool) $raw['committed']) : null,
            committedStatements: isset($raw['committed_statements']) ? (int) $raw['committed_statements'] : null,
            lastCommitEpoch: isset($raw['last_commit_epoch']) ? (int) $raw['last_commit_epoch'] : null,
            lastCommitEpochText: isset($raw['last_commit_epoch_text']) ? (string) $raw['last_commit_epoch_text'] : null,
            lastCommitHlc: $hlc,
            firstCommitStatementIndex: isset($raw['first_commit_statement_index']) ? (int) $raw['first_commit_statement_index'] : null,
            lastCommitStatementIndex: isset($raw['last_commit_statement_index']) ? (int) $raw['last_commit_statement_index'] : null,
            completedStatements: isset($raw['completed_statements']) ? (int) $raw['completed_statements'] : null,
            statementIndex: isset($raw['statement_index']) ? (int) $raw['statement_index'] : null,
            serialization: (string) ($raw['serialization'] ?? ''),
            serializationState: isset($raw['serialization_state']) ? (string) $raw['serialization_state'] : null,
            terminalState: isset($raw['terminal_state']) ? (string) $raw['terminal_state'] : null,
        );
    }
}
