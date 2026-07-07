<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB\Transport;

/**
 * Immutable HTTP response value object.
 */
final readonly class Response
{
    /**
     * @param int                    $status  HTTP status code
     * @param string                 $body    Raw response body
     * @param array<string,string>   $headers Response headers (lowercase keys)
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    /**
     * Decode the body as JSON.
     *
     * @return mixed Decoded JSON value
     */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Check if the response is successful (2xx).
     */
    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
