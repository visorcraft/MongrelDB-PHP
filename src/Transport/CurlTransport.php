<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB\Transport;

use Visorcraft\MongrelDB\Exceptions\ConnectionException;

/**
 * cURL-based HTTP transport — the default and recommended transport.
 *
 * Uses keep-alive connection pooling within a single request for low-latency
 * sequential requests. Thread-safe in PHP-FPM (each request gets its own
 * handle pool).
 *
 * On PHP 8.5+, an optional persistent share handle can share DNS resolution,
 * TLS sessions, and the connection pool ACROSS requests (e.g. between PHP-FPM
 * invocations hitting the same daemon) for further latency reductions. This is
 * off by default and degrades gracefully to per-request pooling on PHP 8.4.
 *
 * @see https://www.php.net/manual/en/function.curl-share-init-persistent.php
 */
final class CurlTransport implements TransportInterface
{
    private const int DEFAULT_TIMEOUT = 30;
    private const int DEFAULT_CONNECT_TIMEOUT = 10;

    /** @var array<int, \CurlHandle> Pool of reusable handles keyed by host */
    private array $handlePool = [];

    /** @var ?object Lazily-created persistent share handle (PHP 8.5+) */
    private ?object $shareHandle = null;

    private bool $shareHandleInitialized = false;

    /**
     * @param int                 $timeout           Total request timeout (seconds)
     * @param int                 $connectTimeout    Connection timeout (seconds)
     * @param bool|array<int,int> $persistentSharing Persistent cURL share handle:
     *   - false (default): per-request pooling only (PHP 8.4-compatible)
     *   - true: enable with sensible defaults (DNS + TLS sessions + connection pool)
     *   - array<int,int>: explicit list of CURL_LOCK_DATA_* constants to share
     *
     *   Requires PHP 8.5+. On PHP 8.4 any non-false value silently falls back to
     *   per-request pooling. CURL_LOCK_DATA_COOKIE is rejected — it is forbidden
     *   by curl_share_init_persistent and would leak cookies across requests,
     *   which is unsafe for a stateless database client.
     */
    public function __construct(
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        private readonly bool|array $persistentSharing = false,
    ) {
        // Fail fast on unsafe options at construction time rather than deferring
        // to the first request. The persistent API itself may be absent on PHP
        // 8.4, but the cookie guard is a correctness check, not a runtime check.
        $this->guardShareOptions();
    }

    #[\Override]
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        $ch = $this->getHandle($url);

        curl_setopt($ch, \CURLOPT_URL, $url);
        curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_HEADER, true);
        curl_setopt($ch, \CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);

        if ($body !== null) {
            curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }
        if ($headerLines !== []) {
            curl_setopt($ch, \CURLOPT_HTTPHEADER, $headerLines);
        }

        $result = curl_exec($ch);

        if ($result === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            throw new ConnectionException(
                "HTTP request failed: {$error} (errno {$errno})",
            );
        }

        $status = curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, \CURLINFO_HEADER_SIZE);

        $rawHeaders = substr((string) $result, 0, $headerSize);
        $responseBody = substr((string) $result, $headerSize);

        $responseHeaders = $this->parseHeaders($rawHeaders);

        return new Response($status, $responseBody, $responseHeaders);
    }

    /**
     * Get a reusable cURL handle from the pool, or create a new one.
     *
     * The handle is reset and — when persistent sharing is enabled on PHP 8.5+ —
     * re-attached to the persistent share handle so that DNS, TLS, and connection
     * pools are reused across requests.
     */
    private function getHandle(string $url): \CurlHandle
    {
        $host = parse_url($url, \PHP_URL_HOST) ?? 'localhost';
        $host = (string) $host;

        if (!isset($this->handlePool[\crc32($host)])) {
            $ch = curl_init();
            $this->handlePool[\crc32($host)] = $ch;
        }

        // Reset previous request state
        $ch = $this->handlePool[\crc32($host)];
        curl_reset($ch);

        // curl_reset clears CURLOPT_SHARE, so re-attach the persistent share
        // handle (if any) on every checkout.
        $share = $this->getShareHandle();
        if ($share !== null) {
            curl_setopt($ch, \CURLOPT_SHARE, $share);
        }

        return $ch;
    }

    /**
     * Lazily create (or reuse) the persistent share handle.
     *
     * PHP deduplicates persistent share handles by their option set, so creating
     * it once per instance and caching it is safe and cheap. The handle is never
     * closed — persistent handles outlive the request by design.
     *
     * @return ?object The persistent CurlSharePersistentHandle, or null when
     *                 persistent sharing is disabled or unsupported (PHP 8.4)
     */
    private function getShareHandle(): ?object
    {
        if ($this->shareHandleInitialized) {
            return $this->shareHandle;
        }
        $this->shareHandleInitialized = true;

        $options = $this->normalizedShareOptions();
        if ($options === null) {
            return null;
        }

        $this->shareHandle = curl_share_init_persistent($options);

        return $this->shareHandle;
    }

    /**
     * Normalize the persistentSharing config into a list of CURL_LOCK_DATA_*
     * constants, or null if disabled or unsupported on this PHP runtime.
     *
     * @return ?array<int,int>
     */
    private function normalizedShareOptions(): ?array
    {
        if ($this->persistentSharing === false) {
            return null;
        }

        // PHP 8.5+ is required for persistent share handles; otherwise degrade
        // gracefully to per-request pooling.
        if (!function_exists('curl_share_init_persistent')) {
            return null;
        }

        return $this->persistentSharing === true
            ? $this->defaultShareOptions()
            : $this->persistentSharing;
    }

    /**
     * Sensible default share options — the latency wins without correctness risk.
     *
     * Shares DNS resolution, TLS sessions, and the connection pool across
     * requests. Cookie sharing is intentionally excluded (see guardShareOptions).
     *
     * @return array<int,int>
     */
    private function defaultShareOptions(): array
    {
        $options = [\CURL_LOCK_DATA_DNS, \CURL_LOCK_DATA_SSL_SESSION];

        // CURL_LOCK_DATA_CONNECT (connection-pool sharing) requires libcurl 7.67+.
        if (\defined('CURL_LOCK_DATA_CONNECT')) {
            $options[] = \CURL_LOCK_DATA_CONNECT;
        }

        return $options;
    }

    /**
     * Reject unsafe share options at construction time (fail-fast).
     *
     * CURL_LOCK_DATA_COOKIE is forbidden by curl_share_init_persistent and would
     * leak cookies across requests, which is unsafe for a stateless database
     * client. This guard does not depend on the PHP 8.5 runtime API, so it also
     * catches the error on PHP 8.4 (where the persistent handle would otherwise
     * be silently skipped).
     *
     * @throws \InvalidArgumentException If CURL_LOCK_DATA_COOKIE is included
     */
    private function guardShareOptions(): void
    {
        if (!is_array($this->persistentSharing)) {
            return;
        }

        // ext-curl is a hard dependency, so the constant is defined; the literal
        // fallback (=2) is defensive only.
        $cookie = \defined('CURL_LOCK_DATA_COOKIE') ? \CURL_LOCK_DATA_COOKIE : 2;
        if (in_array($cookie, $this->persistentSharing, true)) {
            throw new \InvalidArgumentException(
                'CURL_LOCK_DATA_COOKIE cannot be shared persistently: it is '
                . 'forbidden by curl_share_init_persistent and would leak '
                . 'cookies across requests.'
            );
        }
    }

    /**
     * Parse raw HTTP headers into an associative array (lowercase keys).
     *
     * @param string $rawHeaders Raw header block from cURL
     *
     * @return array<string,string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = explode("\r\n", $rawHeaders);

        foreach ($lines as $line) {
            if (str_contains($line, ': ')) {
                [$key, $value] = explode(': ', $line, 2);
                $headers[strtolower($key)] = $value;
            }
        }

        return $headers;
    }

    /**
     * Close all pooled handles.
     *
     * Since PHP 8.0, \CurlHandle is an object with a destructor that frees the
     * underlying resource automatically, so there is nothing to close here. We
     * drop the references so the handles are collected promptly. The persistent
     * share handle is intentionally NOT released — it is designed to outlive the
     * request and be reused across PHP-FPM invocations.
     */
    public function __destruct()
    {
        $this->handlePool = [];
    }
}
