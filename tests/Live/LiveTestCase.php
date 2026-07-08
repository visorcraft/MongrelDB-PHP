<?php

declare(strict_types=1);

/**
 * Shared base for live integration tests against a real mongreldb-server.
 *
 * Provides the daemon-reachability skip guard, a fresh-database connection,
 * and table lifecycle helpers used across the live test files.
 */

namespace Visorcraft\MongrelDB\Tests\Live;

use PHPUnit\Framework\TestCase;
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\Exceptions\MongrelDBException;

abstract class LiveTestCase extends TestCase
{
    protected Database $db;

    protected string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('MONGRELDB_URL') ?: 'http://127.0.0.1:8453';
        $this->db = new Database($this->baseUrl);

        if (!$this->daemonReachable()) {
            $this->markTestSkipped(
                "No mongreldb-server reachable at {$this->baseUrl} "
                . '(set MONGRELDB_URL or start a daemon to run live tests)'
            );
        }
    }

    /**
     * Reachability check that doesn't go through the typed client.
     */
    private function daemonReachable(): bool
    {
        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_TIMEOUT, 2);
        // If a bearer token is configured, include it — otherwise an
        // auth-enabled daemon returns 401 on /health and looks "unreachable".
        $token = getenv('MONGRELDB_TOKEN') ?: '';
        if ($token !== '') {
            curl_setopt($ch, \CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
        }
        curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        unset($ch);

        return $code >= 200 && $code < 300;
    }

    /**
     * Drop and recreate a fresh table, isolating each test's data.
     *
     * @param array<int,array<string,mixed>> $columns
     */
    protected function withFreshTable(string $name, array $columns): void
    {
        try {
            $this->db->dropTable($name);
        } catch (MongrelDBException) {
            // Table didn't exist - fine.
        }
        $this->db->createTable($name, $columns);
    }
}
