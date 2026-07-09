<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB\Transport;

use Visorcraft\MongrelDB\Exceptions\ConnectionException;

/**
 * Stream-based HTTP transport - fallback when cURL is not available.
 *
 * Uses PHP's native stream wrappers (file_get_contents with context).
 * Less efficient than cURL (no keep-alive, no connection pooling).
 */
final class StreamTransport implements TransportInterface
{
    /**
     * @param int $timeout Total request timeout (seconds)
     */
    public function __construct(
        private readonly int $timeout = 30,
    ) {}

    #[\Override]
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }

        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                // Never follow redirects: a malicious or misconfigured server
                // could redirect to an attacker-controlled host with the
                // Authorization header still attached.
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ];

        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        $context = stream_context_create($options);

        // Suppress warnings - we handle errors via response codes
        $body_response = @file_get_contents($url, false, $context);

        if ($body_response === false) {
            throw new ConnectionException(
                'HTTP request failed: unable to connect to the daemon',
            );
        }

        // Parse status code and headers from the response. PHP 8.5 deprecated the
        // auto-populated $http_response_header variable in favor of an explicit
        // accessor; use it when available and fall back for older runtimes.
        if (function_exists('http_get_last_response_headers')) {
            $responseHeaderLines = http_get_last_response_headers() ?? [];
        } else {
            $responseHeaderLines = $http_response_header ?? [];
        }

        $status = 500;
        $responseHeaders = [];

        foreach ($responseHeaderLines as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches)) {
                $status = (int) $matches[1];
            } elseif (str_contains($header, ': ')) {
                [$key, $value] = explode(': ', $header, 2);
                $responseHeaders[strtolower($key)] = $value;
            }
        }

        return new Response($status, (string) $body_response, $responseHeaders);
    }
}
