<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB\Transport;

use Visorcraft\MongrelDB\Exceptions\ConnectionException;

/**
 * cURL-based HTTP transport — the default and recommended transport.
 *
 * Uses persistent connections (keep-alive) for low-latency sequential
 * requests. Thread-safe in PHP-FPM (each request gets its own handle).
 */
final class CurlTransport implements TransportInterface
{
    private const int DEFAULT_TIMEOUT = 30;
    private const int DEFAULT_CONNECT_TIMEOUT = 10;

    /** @var array<int, \CurlHandle> Pool of reusable handles keyed by host */
    private array $handlePool = [];

    /**
     * @param int $timeout         Total request timeout (seconds)
     * @param int $connectTimeout  Connection timeout (seconds)
     */
    public function __construct(
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
    ) {}

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

        return $ch;
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
     */
    public function __destruct()
    {
        foreach ($this->handlePool as $ch) {
            curl_close($ch);
        }
    }
}
