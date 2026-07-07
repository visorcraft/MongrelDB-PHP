<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB\Exceptions;

/**
 * Thrown when the daemon is unreachable or returns a network error.
 */
class ConnectionException extends MongrelDBException
{
}
