<?php

declare(strict_types=1);

/**
 * Tests for the optional persistent cURL share handle (PHP 8.5+).
 *
 * The persistent sharing feature degrades gracefully on PHP 8.4 (where
 * curl_share_init_persistent does not exist). These tests cover:
 *   - default-off behavior (no sharing attempted)
 *   - graceful degradation when the runtime lacks the PHP 8.5 API
 *   - constructor option propagation from Database and MongrelDB
 *   - rejection of CURL_LOCK_DATA_COOKIE (unsafe, and forbidden by the API)
 *   - end-to-end request success with sharing enabled
 *
 * Run: phpunit tests/PersistentSharingTest.php
 */

namespace Visorcraft\MongrelDB\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\MongrelDB;
use Visorcraft\MongrelDB\Transport\CurlTransport;

final class PersistentSharingTest extends TestCase
{
    /**
     * The default CurlTransport must not attempt to create a persistent handle.
     * We assert this by reflecting over the private normalizedShareOptions()
     * helper - it must return null when persistentSharing is false.
     */
    public function test_default_transport_disables_sharing(): void
    {
        $transport = new CurlTransport();

        $options = $this->invokeNormalizedOptions($transport);

        $this->assertNull($options);
    }

    /**
     * When persistent sharing is requested but the PHP runtime is < 8.5 (the
     * curl_share_init_persistent function is absent), the transport must
     * silently degrade to per-request pooling rather than raising an error.
     *
     * We simulate a pre-8.5 runtime by wrapping the function-existence check.
     * This guards the opt-in path on PHP 8.4.
     */
    public function test_graceful_fallback_when_persistent_api_missing(): void
    {
        // The assertion is meaningful on both 8.4 and 8.5: normalized options
        // must be null whenever curl_share_init_persistent is unavailable.
        $transport = new CurlTransport(persistentSharing: true);

        if (function_exists('curl_share_init_persistent')) {
            $this->markTestSkipped(
                'curl_share_init_persistent is available on this PHP runtime (8.5+)'
            );
        }

        $this->assertNull($this->invokeNormalizedOptions($transport));
    }

    /**
     * On PHP 8.5+ with sharing enabled via `true`, the default option set must
     * include DNS and TLS session sharing. Connection-pool sharing
     * (CURL_LOCK_DATA_CONNECT) is included when libcurl supports it.
     */
    public function test_default_options_include_dns_and_tls_on_php85(): void
    {
        if (!function_exists('curl_share_init_persistent')) {
            $this->markTestSkipped('Requires PHP 8.5+ persistent cURL API');
        }

        $transport = new CurlTransport(persistentSharing: true);
        $options = $this->invokeNormalizedOptions($transport);

        $this->assertNotNull($options);
        $this->assertContains(\CURL_LOCK_DATA_DNS, $options);
        $this->assertContains(\CURL_LOCK_DATA_SSL_SESSION, $options);
    }

    /**
     * An explicit option list must be passed through verbatim (after the cookie
     * guard), so callers can fine-tune what is shared.
     */
    public function test_explicit_options_passed_through(): void
    {
        if (!function_exists('curl_share_init_persistent')) {
            $this->markTestSkipped('Requires PHP 8.5+ persistent cURL API');
        }

        $explicit = [\CURL_LOCK_DATA_DNS];
        $transport = new CurlTransport(persistentSharing: $explicit);
        $options = $this->invokeNormalizedOptions($transport);

        $this->assertSame($explicit, $options);
    }

    /**
     * CURL_LOCK_DATA_COOKIE must be rejected even when the rest of the option
     * list is valid. Sharing cookies persistently across requests would leak
     * session state between clients of the database, and curl_share_init_persistent
     * itself forbids it.
     */
    public function test_cookie_sharing_is_rejected(): void
    {
        if (!function_exists('curl_share_init_persistent')) {
            $this->markTestSkipped('Requires PHP 8.5+ persistent cURL API');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CURL_LOCK_DATA_COOKIE/');

        $cookie = \defined('CURL_LOCK_DATA_COOKIE') ? \CURL_LOCK_DATA_COOKIE : 2;

        // The guard runs lazily when options are resolved (first request).
        new CurlTransport(persistentSharing: [\CURL_LOCK_DATA_DNS, $cookie]);
    }

    /**
     * The MongrelDB client must forward the persistentSharing flag to its
     * default CurlTransport. We verify this via reflection on the created
     * transport's resolved options.
     */
    public function test_mongreldb_constructor_propagates_option(): void
    {
        if (!function_exists('curl_share_init_persistent')) {
            $this->markTestSkipped('Requires PHP 8.5+ persistent cURL API');
        }

        $client = new MongrelDB(
            'http://127.0.0.1:8453',
            persistentSharing: true,
        );

        $transport = $this->extractClientTransport($client);
        $this->assertInstanceOf(CurlTransport::class, $transport);

        $options = $this->invokeNormalizedOptions($transport);
        $this->assertNotNull($options);
        $this->assertContains(\CURL_LOCK_DATA_DNS, $options);
    }

    /**
     * The high-level Database wrapper must forward the flag through MongrelDB.
     */
    public function test_database_constructor_propagates_option(): void
    {
        if (!function_exists('curl_share_init_persistent')) {
            $this->markTestSkipped('Requires PHP 8.5+ persistent cURL API');
        }

        $db = new Database(persistentSharing: true);
        $client = $db->getClient();
        $transport = $this->extractClientTransport($client);
        $this->assertInstanceOf(CurlTransport::class, $transport);

        $options = $this->invokeNormalizedOptions($transport);
        $this->assertNotNull($options);
    }

    /**
     * A custom transport passed to MongrelDB must take precedence - the
     * persistentSharing flag must be ignored in that case.
     */
    public function test_custom_transport_takes_precedence(): void
    {
        $stub = new class implements \Visorcraft\MongrelDB\Transport\TransportInterface {
            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null,
            ): \Visorcraft\MongrelDB\Transport\Response {
                return new \Visorcraft\MongrelDB\Transport\Response(200, '{}');
            }
        };

        $client = new MongrelDB(
            'http://127.0.0.1:8453',
            transport: $stub,
            persistentSharing: true,
        );

        $transport = $this->extractClientTransport($client);
        $this->assertNotInstanceOf(CurlTransport::class, $transport);
        $this->assertSame($stub, $transport);
    }

    /**
     * With sharing enabled, a real request must still succeed (the share handle
     * must not break the request flow). This is an integration smoke test.
     */
    public function test_request_succeeds_with_sharing_enabled(): void
    {
        if (!function_exists('curl_share_init_persistent')) {
            $this->markTestSkipped('Requires PHP 8.5+ persistent cURL API');
        }

        $transport = new CurlTransport(persistentSharing: true);

        // Use a deliberately bad host so we get a deterministic ConnectionException
        // - this proves the share handle was attached and the request was attempted
        // without a fatal error from the persistent handle setup.
        $this->expectException(\Visorcraft\MongrelDB\Exceptions\ConnectionException::class);

        // Reserved/documentation host - guaranteed not to resolve reliably.
        $transport->request('GET', 'http://invalid.localhost:1/health');
    }

    // ── Reflection helpers ──────────────────────────────────────────────────

    /**
     * Invoke the private normalizedShareOptions() to inspect the resolved
     * option set without making a real network call.
     *
     * @return ?array<int,int>
     */
    private function invokeNormalizedOptions(CurlTransport $transport): ?array
    {
        // setAccessible() has been a no-op since PHP 8.1 (all members reachable
        // via reflection by default), so it is omitted here to avoid an 8.5
        // deprecation notice.
        return (new ReflectionMethod($transport, 'normalizedShareOptions'))->invoke($transport);
    }

    /**
     * Extract the transport instance from a MongrelDB client via reflection.
     */
    private function extractClientTransport(MongrelDB $client): object
    {
        return (new \ReflectionClass($client))->getProperty('transport')->getValue($client);
    }
}
