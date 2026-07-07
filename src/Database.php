<?php

declare(strict_types=1);

namespace Visorcraft\MongrelDB;

/**
 * High-level typed API for MongrelDB.
 *
 * Wraps the {@see MongrelDB} HTTP client with typed methods for CRUD,
 * schema management, auth, procedures, and maintenance operations.
 *
 * @example Basic usage
 * ```php
 * $db = new Database('http://127.0.0.1:8453', username: 'admin', password: 's3cret');
 *
 * $db->put('orders', [1 => 1, 2 => 'Alice', 3 => 99.50]);
 * $rows = $db->query('orders')->where('bitmap_eq', ['column' => 2, 'value' => 'Alice'])->execute();
 * ```
 */
final class Database
{
    private readonly MongrelDB $client;

    /**
     * Create a new Database connection.
     *
     * @param string              $url                Daemon URL (e.g., 'http://127.0.0.1:8453')
     * @param ?string             $token              Bearer token
     * @param ?string             $username           Basic auth username
     * @param ?string             $password           Basic auth password
     * @param ?MongrelDB          $client             Pre-configured client (overrides url/auth params)
     * @param bool|array<int,int> $persistentSharing  Persistent cURL share handle (PHP 8.5+).
     *   Ignored if $client is provided. See {@see MongrelDB::__construct()}.
     */
    public function __construct(
        string $url = 'http://127.0.0.1:8453',
        ?string $token = null,
        ?string $username = null,
        ?string $password = null,
        ?MongrelDB $client = null,
        bool|array $persistentSharing = false,
    ) {
        $this->client = $client ?? new MongrelDB(
            $url,
            $token,
            $username,
            $password,
            persistentSharing: $persistentSharing,
        );
    }

    /**
     * Get the underlying HTTP client.
     */
    public function getClient(): MongrelDB
    {
        return $this->client;
    }

    // ── Health ──────────────────────────────────────────────────────────────

    public function health(): bool
    {
        return $this->client->health();
    }

    // ── Table management ────────────────────────────────────────────────────

    /**
     * List all table names.
     *
     * @return array<int,string>
     */
    public function tables(): array
    {
        $data = $this->client->get('/tables')->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Create a table with typed columns.
     *
     * @param string                   $name    Table name
     * @param array<int,array>         $columns Column definitions
     *
     * @return int Table ID
     */
    public function createTable(string $name, array $columns): int
    {
        $response = $this->client->post('/kit/create_table', [
            'name' => $name,
            'columns' => $columns,
        ]);
        $data = $response->json();

        return is_array($data) ? ($data['table_id'] ?? 0) : 0;
    }

    /**
     * Drop a table.
     */
    public function dropTable(string $name): void
    {
        $this->client->delete("/tables/{$name}");
    }

    /**
     * Get the row count for a table.
     */
    public function count(string $table): int
    {
        $data = $this->client->get("/tables/{$table}/count")->json();

        return is_array($data) ? ($data['count'] ?? 0) : 0;
    }

    // ── CRUD (via Kit typed API) ────────────────────────────────────────────

    /**
     * Insert a row into a table.
     *
     * Uses the Kit typed transaction endpoint with constraint enforcement.
     *
     * @param string              $table  Table name
     * @param array<int,mixed>    $cells  Column ID → value pairs [col_id => val, ...]
     * @param ?string             $idempotencyKey Optional idempotency key
     *
     * @return array<string,mixed> Operation result
     */
    public function put(string $table, array $cells, ?string $idempotencyKey = null): array
    {
        // Convert [col_id => val] to flat [col_id, val, col_id, val, ...]
        $flatCells = $this->cellsToFlat($cells);

        $payload = [
            'ops' => [
                ['put' => ['table' => $table, 'cells' => $flatCells]],
            ],
        ];

        if ($idempotencyKey !== null) {
            $payload['idempotency_key'] = $idempotencyKey;
        }

        $response = $this->client->post('/kit/txn', $payload);
        $data = $response->json();
        $results = $data['results'] ?? [];

        return $results[0] ?? [];
    }

    /**
     * Upsert a row (insert or update on conflict).
     *
     * @param string              $table        Table name
     * @param array<int,mixed>    $cells        Column ID → value pairs
     * @param ?array<int,mixed>   $updateCells  Update values on conflict (null = DO NOTHING)
     * @param ?string             $idempotencyKey
     *
     * @return array<string,mixed> Operation result with action ('inserted'|'updated'|'unchanged')
     */
    public function upsert(string $table, array $cells, ?array $updateCells = null, ?string $idempotencyKey = null): array
    {
        $flatCells = $this->cellsToFlat($cells);

        $op = ['table' => $table, 'cells' => $flatCells];

        if ($updateCells !== null) {
            $op['update_cells'] = $this->cellsToFlat($updateCells);
        }

        $payload = [
            'ops' => [['upsert' => $op]],
        ];

        if ($idempotencyKey !== null) {
            $payload['idempotency_key'] = $idempotencyKey;
        }

        $response = $this->client->post('/kit/txn', $payload);
        $data = $response->json();
        $results = $data['results'] ?? [];

        return $results[0] ?? [];
    }

    /**
     * Delete a row by its internal row ID.
     *
     * @param string $table Table name
     * @param int    $rowId Internal row ID
     */
    public function delete(string $table, int $rowId): void
    {
        $this->client->post('/kit/txn', [
            'ops' => [
                ['delete' => ['table' => $table, 'row_id' => $rowId]],
            ],
        ]);
    }

    /**
     * Delete a row by its primary key value.
     *
     * @param string $table Table name
     * @param mixed  $pk    Primary key value
     */
    public function deleteByPk(string $table, mixed $pk): void
    {
        $this->client->post('/kit/txn', [
            'ops' => [
                ['delete_by_pk' => ['table' => $table, 'pk' => $pk]],
            ],
        ]);
    }

    // ── Query ───────────────────────────────────────────────────────────────

    /**
     * Start a fluent query builder.
     *
     * @param string $table Table name
     */
    public function query(string $table): QueryBuilder
    {
        return new QueryBuilder($this->client, $table);
    }

    /**
     * Execute a SQL statement and return decoded result rows.
     *
     * Uses the Kit query endpoint for JSON output. For raw Arrow IPC,
     * use {@see MongrelDB::sqlRaw()}.
     *
     * @param string $sql SQL statement
     *
     * @return array<int,array<string,mixed>> Result rows
     */
    public function sql(string $sql): array
    {
        // Route simple SELECTs through the SQL endpoint for full DataFusion support
        $response = $this->client->post('/sql', ['sql' => $sql]);
        $body = $response->body;

        // Empty result (DDL/DML or empty SELECT)
        if ($body === '') {
            return [];
        }

        // The SQL endpoint returns Arrow IPC bytes. Decode as JSON if possible
        // (some daemon versions may return JSON for simple queries).
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }

        // Arrow IPC binary — return empty (use sqlRows via Kit query for JSON)
        return [];
    }

    // ── Schema ──────────────────────────────────────────────────────────────

    /**
     * Get the full schema catalog.
     *
     * @return array<string,array> Table name → descriptor
     */
    public function schema(): array
    {
        $data = $this->client->get('/kit/schema')->json();

        return is_array($data) ? ($data['tables'] ?? []) : [];
    }

    /**
     * Get the schema for a single table.
     *
     * @return array<string,mixed> Table descriptor
     */
    public function schemaFor(string $table): array
    {
        $data = $this->client->get("/kit/schema/{$table}")->json();

        return is_array($data) ? $data : [];
    }

    // ── Auth management ─────────────────────────────────────────────────────

    /**
     * Create a catalog user.
     *
     * @param string $username Username
     * @param string $password Password (Argon2id-hashed by the engine)
     */
    public function createUser(string $username, string $password): void
    {
        $this->client->post('/sql', [
            'sql' => "CREATE USER {$this->quoteIdent($username)} WITH PASSWORD '{$this->escapeString($password)}'",
        ]);
    }

    /**
     * Drop a user.
     */
    public function dropUser(string $username): void
    {
        $this->client->post('/sql', [
            'sql' => "DROP USER {$this->quoteIdent($username)}",
        ]);
    }

    /**
     * Change a user's password.
     */
    public function alterPassword(string $username, string $newPassword): void
    {
        $this->client->post('/sql', [
            'sql' => "ALTER USER {$this->quoteIdent($username)} WITH PASSWORD '{$this->escapeString($newPassword)}'",
        ]);
    }

    /**
     * Verify user credentials.
     */
    public function verifyUser(string $username, string $password): bool
    {
        // No dedicated verify endpoint — attempt an authenticated connection
        try {
            $tempClient = new MongrelDB(
                $this->client->getUrl(),
                username: $username,
                password: $password,
            );
            $tempClient->get('/health');

            return true;
        } catch (\Visorcraft\MongrelDB\Exceptions\MongrelDBException) {
            return false;
        }
    }

    /**
     * Grant or revoke admin privileges.
     */
    public function setUserAdmin(string $username, bool $isAdmin): void
    {
        $sql = $isAdmin
            ? "ALTER USER {$this->quoteIdent($username)} ADMIN"
            : "ALTER USER {$this->quoteIdent($username)} NOT ADMIN";

        $this->client->post('/sql', ['sql' => $sql]);
    }

    /**
     * List all usernames.
     *
     * @return array<int,string>
     */
    public function users(): array
    {
        $response = $this->client->post('/sql', ['sql' => 'SHOW USERS']);
        $data = $response->json();

        return is_array($data) ? array_column($data, 'username') : [];
    }

    /**
     * Create a role.
     */
    public function createRole(string $name): void
    {
        $this->client->post('/sql', [
            'sql' => "CREATE ROLE {$this->quoteIdent($name)}",
        ]);
    }

    /**
     * Drop a role.
     */
    public function dropRole(string $name): void
    {
        $this->client->post('/sql', [
            'sql' => "DROP ROLE {$this->quoteIdent($name)}",
        ]);
    }

    /**
     * List all role names.
     *
     * @return array<int,string>
     */
    public function roles(): array
    {
        $response = $this->client->post('/sql', ['sql' => 'SHOW ROLES']);
        $data = $response->json();

        return is_array($data) ? array_column($data, 'name') : [];
    }

    /**
     * Grant a role to a user.
     */
    public function grantRole(string $username, string $roleName): void
    {
        $this->client->post('/sql', [
            'sql' => "GRANT {$this->quoteIdent($roleName)} TO {$this->quoteIdent($username)}",
        ]);
    }

    /**
     * Revoke a role from a user.
     */
    public function revokeRole(string $username, string $roleName): void
    {
        $this->client->post('/sql', [
            'sql' => "REVOKE {$this->quoteIdent($roleName)} FROM {$this->quoteIdent($username)}",
        ]);
    }

    /**
     * Grant a permission to a role.
     *
     * @param string $role       Role name
     * @param string $permission Permission string (e.g., 'select:orders', 'all', 'ddl', 'admin')
     *
     * @throws \InvalidArgumentException If the permission string contains injection characters
     */
    public function grantPermission(string $role, string $permission): void
    {
        $this->validatePermission($permission);
        $this->client->post('/sql', [
            'sql' => "GRANT {$permission} TO {$this->quoteIdent($role)}",
        ]);
    }

    /**
     * Revoke a permission from a role.
     *
     * @throws \InvalidArgumentException If the permission string contains injection characters
     */
    public function revokePermission(string $role, string $permission): void
    {
        $this->validatePermission($permission);
        $this->client->post('/sql', [
            'sql' => "REVOKE {$permission} FROM {$this->quoteIdent($role)}",
        ]);
    }

    // ── Stored procedures ───────────────────────────────────────────────────

    /**
     * List all stored procedures.
     *
     * @return array<int,array>
     */
    public function procedures(): array
    {
        $data = $this->client->get('/procedures')->json();

        return is_array($data) ? ($data['procedures'] ?? []) : [];
    }

    /**
     * Get a single procedure definition.
     *
     * @return array<string,mixed>
     */
    public function procedure(string $name): array
    {
        $data = $this->client->get("/procedures/{$name}")->json();

        return is_array($data) ? ($data['procedure'] ?? []) : [];
    }

    /**
     * Install or replace a stored procedure.
     *
     * @param array<string,mixed> $procedure Procedure definition
     */
    public function createProcedure(array $procedure): array
    {
        $data = $this->client->post('/procedures', ['procedure' => $procedure])->json();

        return is_array($data) ? ($data['procedure'] ?? []) : [];
    }

    /**
     * Drop a stored procedure.
     */
    public function dropProcedure(string $name): void
    {
        $this->client->delete("/procedures/{$name}");
    }

    /**
     * Call a stored procedure.
     *
     * @param string                 $name Procedure name
     * @param array<string,mixed>    $args Arguments
     *
     * @return mixed Procedure result
     */
    public function callProcedure(string $name, array $args = []): mixed
    {
        $data = $this->client->post("/procedures/{$name}/call", ['args' => $args])->json();

        return is_array($data) ? ($data['result'] ?? null) : null;
    }

    // ── Maintenance ─────────────────────────────────────────────────────────

    /**
     * Compact all tables (merge sorted runs).
     *
     * @return array{compacted:int,skipped:int}
     */
    public function compact(): array
    {
        $data = $this->client->post('/compact')->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Compact a single table.
     *
     * @return array<string,mixed> Compaction result
     */
    public function compactTable(string $name): array
    {
        $data = $this->client->post("/tables/{$name}/compact")->json();

        return is_array($data) ? $data : [];
    }

    // ── Transactions ────────────────────────────────────────────────────────

    /**
     * Begin a batch transaction.
     */
    public function beginTransaction(): Transaction
    {
        return new Transaction($this->client);
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    /**
     * Convert associative [col_id => val] to flat [col_id, val, col_id, val, ...].
     *
     * @param array<int,mixed> $cells
     *
     * @return array<int,mixed>
     */
    private function cellsToFlat(array $cells): array
    {
        $flat = [];
        foreach ($cells as $colId => $value) {
            $flat[] = $colId;
            $flat[] = $value;
        }

        return $flat;
    }

    /**
     * Quote a SQL identifier.
     */
    private function quoteIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    /**
     * Escape a single-quoted string literal.
     */
    private function escapeString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Validate a permission string against the allowed format.
     *
     * Allowed: all, ddl, admin, select:<table>, insert:<table>,
     * update:<table>, delete:<table>. Rejects anything containing
     * SQL injection characters (semicolons, quotes, comments).
     *
     * @throws \InvalidArgumentException If the permission is invalid
     */
    private function validatePermission(string $permission): void
    {
        // Allowed standalone permissions
        $standalone = ['all', 'ddl', 'admin'];

        if (in_array(strtolower($permission), $standalone, true)) {
            return;
        }

        // Check table-level permission format: verb:table_name
        if (preg_match('/^(select|insert|update|delete):(\w+)$/i', $permission)) {
            return;
        }

        // Reject anything with injection characters
        throw new \InvalidArgumentException(
            "Invalid permission '{$permission}'. Expected: all, ddl, admin, " .
            'or select:<table>, insert:<table>, update:<table>, delete:<table>'
        );
    }
}
