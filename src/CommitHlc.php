<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB;

/** Structural HLC from durable recovery (0.64+). */
final class CommitHlc
{
    public function __construct(
        public readonly int $physicalMicros,
        public readonly int $logical = 0,
        public readonly int $nodeTiebreaker = 0,
    ) {}

    /** @param array<string,mixed>|null $raw */
    public static function fromArray(?array $raw): ?self
    {
        if ($raw === null || !isset($raw['physical_micros'])) {
            return null;
        }

        return new self(
            physicalMicros: (int) $raw['physical_micros'],
            logical: (int) ($raw['logical'] ?? 0),
            nodeTiebreaker: (int) ($raw['node_tiebreaker'] ?? 0),
        );
    }
}
