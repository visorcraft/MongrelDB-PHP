<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB\Exceptions;

/**
 * Thrown on 409 Conflict (unique, foreign key, check, or trigger violation).
 *
 * The exception code is a string error code from the server:
 * UNIQUE_VIOLATION, FK_VIOLATION, CHECK_VIOLATION, TRIGGER_VALIDATION, CONFLICT.
 */
class ConstraintException extends MongrelDBException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = '',
        public readonly ?int $opIndex = null,
    ) {
        parent::__construct($message);
    }
}
