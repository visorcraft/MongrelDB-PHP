<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB\Transport;

/**
 * HTTP transport abstraction for the MongrelDB daemon client.
 *
 * Implementations handle the actual HTTP request/response cycle. The default
 * implementation uses cURL; a stream-based fallback is also provided. Users
 * can inject their own implementation (e.g., a Guzzle PSR-18 adapter) via
 * the MongrelDB constructor.
 */
interface TransportInterface
{
    /**
     * Perform an HTTP request and return the response.
     *
     * @param string               $method  HTTP method (GET, POST, PUT, DELETE)
     * @param string               $url     Full URL
     * @param array<string,string> $headers HTTP headers
     * @param ?string              $body    Request body (null for no body)
     *
     * @return Response Response object with status, headers, and body
     *
     * @throws \Visorcraft\MongrelDB\Exceptions\ConnectionException On network errors
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response;
}
