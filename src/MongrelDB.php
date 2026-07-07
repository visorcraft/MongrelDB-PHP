<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB;

use Visorcraft\MongrelDB\Exceptions\AuthException;
use Visorcraft\MongrelDB\Exceptions\ConnectionException;
use Visorcraft\MongrelDB\Exceptions\ConstraintException;
use Visorcraft\MongrelDB\Exceptions\MongrelDBException;
use Visorcraft\MongrelDB\Exceptions\NotFoundException;
use Visorcraft\MongrelDB\Exceptions\QueryException;
use Visorcraft\MongrelDB\Transport\CurlTransport;
use Visorcraft\MongrelDB\Transport\Response;
use Visorcraft\MongrelDB\Transport\TransportInterface;

/**
 * MongrelDB HTTP client — connects to a running mongreldb-server daemon.
 *
 * This is the low-level client that handles HTTP transport, authentication,
 * and error mapping. For typed CRUD operations, use the {@see Database}
 * wrapper class.
 *
 * @see Database For the high-level typed API
 */
final class MongrelDB
{
    private readonly TransportInterface $transport;

    /** @var array<string,string> Default headers sent on every request */
    private readonly array $defaultHeaders;

    /**
     * Create a new MongrelDB client.
     *
     * @param string               $url                Daemon base URL (e.g., 'http://127.0.0.1:8453')
     * @param ?string              $token              Bearer token (for --auth-token mode)
     * @param ?string              $username           Username (for --auth-users mode)
     * @param ?string              $password           Password (for --auth-users mode)
     * @param ?TransportInterface  $transport          Custom HTTP transport (defaults to cURL)
     * @param bool|array<int,int>  $persistentSharing  Persistent cURL share handle (PHP 8.5+).
     *   Ignored unless the default cURL transport is used. See
     *   {@see \Visorcraft\MongrelDB\Transport\CurlTransport::__construct()}.
     */
    public function __construct(
        private readonly string $url,
        ?string $token = null,
        ?string $username = null,
        ?string $password = null,
        ?TransportInterface $transport = null,
        bool|array $persistentSharing = false,
    ) {
        $this->transport = $transport ?? new CurlTransport(persistentSharing: $persistentSharing);

        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        } elseif ($username !== null) {
            $credentials = base64_encode("{$username}:" . ($password ?? ''));
            $headers['Authorization'] = "Basic {$credentials}";
        }

        $this->defaultHeaders = $headers;
    }

    // ── HTTP helpers ────────────────────────────────────────────────────────

    /**
     * Perform a GET request.
     *
     * @param string               $path    URL path (e.g., '/health')
     * @param array<string,string> $headers Additional headers
     *
     * @return Response Raw HTTP response
     */
    public function get(string $path, array $headers = []): Response
    {
        return $this->request('GET', $path, $headers);
    }

    /**
     * Perform a POST request with a JSON body.
     *
     * @param string               $path    URL path
     * @param mixed                $data    Data to JSON-encode as the body
     * @param array<string,string> $headers Additional headers
     *
     * @return Response Raw HTTP response
     */
    public function post(string $path, mixed $data = null, array $headers = []): Response
    {
        $body = $data !== null ? $this->encodeJson($data) : null;

        return $this->request('POST', $path, $headers, $body);
    }

    /**
     * Perform a PUT request with a JSON body.
     */
    public function put(string $path, mixed $data = null, array $headers = []): Response
    {
        $body = $data !== null ? $this->encodeJson($data) : null;

        return $this->request('PUT', $path, $headers, $body);
    }

    /**
     * Perform a DELETE request.
     */
    public function delete(string $path, array $headers = []): Response
    {
        return $this->request('DELETE', $path, $headers);
    }

    /**
     * JSON-encode a request body with graceful recovery for malformed UTF-8 and
     * a clear, typed error for genuinely non-encodable values.
     *
     * Encoding policy:
     *   - Malformed UTF-8 bytes are substituted with the U+FFFD replacement
     *     character (JSON_INVALID_UTF8_SUBSTITUTE). This is recoverable — the
     *     surrounding data is still valid and meaningful, and refusing the whole
     *     request over one bad byte would be disproportionate.
     *   - INF, NAN, and recursive structures have no JSON representation and no
     *     meaningful database value. Coercing them silently would corrupt data,
     *     so they raise a QueryException at the client boundary.
     *
     * @throws QueryException If the value cannot be JSON-encoded
     */
    private function encodeJson(mixed $data): string
    {
        try {
            return json_encode(
                $data,
                \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (\JsonException $e) {
            throw new QueryException(
                'Request payload cannot be JSON-encoded: ' . $e->getMessage()
                . '. (INF, NAN, and recursive structures have no JSON representation.)',
            );
        }
    }

    /**
     * Low-level HTTP request with error mapping.
     *
     * @param string               $method  HTTP method
     * @param string               $path    URL path
     * @param array<string,string> $headers Additional headers (merged with defaults)
     * @param ?string              $body    Raw request body
     *
     * @return Response Raw HTTP response
     *
     * @throws ConnectionException   Network errors
     * @throws AuthException         401/403
     * @throws NotFoundException     404
     * @throws ConstraintException   409
     * @throws QueryException        400/500
     */
    public function request(string $method, string $path, array $headers = [], ?string $body = null): Response
    {
        $allHeaders = array_merge($this->defaultHeaders, $headers);
        $url = rtrim($this->url, '/') . '/' . ltrim($path, '/');

        $response = $this->transport->request($method, $url, $allHeaders, $body);

        if ($response->isSuccessful()) {
            return $response;
        }

        // Map HTTP status codes to typed exceptions
        $this->throwForStatus($response);

        // throwForStatus always throws — this is unreachable
        throw new MongrelDBException("Unexpected response status: {$response->status}");
    }

    /**
     * Map an error HTTP response to a typed exception.
     *
     * @throws AuthException        401/403
     * @throws NotFoundException     404
     * @throws ConstraintException   409
     * @throws QueryException        400/500
     */
    private function throwForStatus(Response $response): never
    {
        $status = $response->status;
        $body = $response->body;

        // Try to parse JSON error envelope
        $json = null;
        if (str_starts_with(trim($body), '{')) {
            try {
                $json = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Not JSON — treat as plain text
            }
        }

        $message = $json['error']['message'] ?? $body;
        $errorCode = $json['error']['code'] ?? '';

        throw match ($status) {
            401, 403 => new AuthException($message ?: "Authentication failed ({$status})"),
            404 => new NotFoundException($message ?: 'Resource not found'),
            409 => new ConstraintException(
                message: $message ?: 'Constraint violation',
                errorCode: $errorCode,
                opIndex: $json['error']['op_index'] ?? null,
            ),
            default => new QueryException($message ?: "Server error ({$status})"),
        };
    }

    // ── Convenience API ─────────────────────────────────────────────────────

    /**
     * Check if the daemon is healthy.
     */
    public function health(): bool
    {
        try {
            $this->get('/health');

            return true;
        } catch (MongrelDBException) {
            return false;
        }
    }

    /**
     * Execute a SQL statement. Returns the raw Arrow IPC response body.
     *
     * For decoded JSON rows, use {@see Database::sql()} instead.
     *
     * @param string $sql SQL statement
     *
     * @return string Raw Arrow IPC bytes (binary)
     */
    public function sqlRaw(string $sql): string
    {
        $response = $this->post('/sql', ['sql' => $sql]);

        return $response->body;
    }

    /**
     * Get the base URL.
     */
    public function getUrl(): string
    {
        return $this->url;
    }
}
