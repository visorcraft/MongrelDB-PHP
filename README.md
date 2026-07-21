<p align="center">
  <img src="assets/mongrel.png" alt="MongrelDB logo" width="250" />
</p>

<h1 align="center">MongrelDB PHP Client</h1>

<p align="center">
  <b>Pure PHP client for MongrelDB - embedded+server database with SQL, vector search, full-text search, and AI-native retrieval.</b>
</p>

<p align="center">
  <a href="https://packagist.org/packages/visorcraft/mongreldb-php"><img src="https://img.shields.io/packagist/v/visorcraft/mongreldb-php.svg" alt="Packagist" /></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-%3E%3D8.4-777bb4.svg" alt="PHP" /></a>
  <a href="#license"><img src="https://img.shields.io/badge/license-MIT%20OR%20Apache--2.0-blue.svg" alt="License" /></a>
</p>

## Package

| Surface | Package | Install |
|---|---|---|
| PHP client | `visorcraft/mongreldb-php` | `composer require visorcraft/mongreldb-php` |

## Requirements

- **PHP 8.4 or newer** (PHP 8.5 supported with opt-in performance features)
- **ext-curl** (default HTTP transport) and **ext-json** (always required)
- A running [`mongreldb-server`](https://github.com/visorcraft/MongrelDB) daemon

## What It Provides

- **Typed CRUD** over the Kit transaction endpoint: `put`, `upsert` (insert-or-update on PK conflict), `delete` by row id or primary key, with idempotency keys for safe retries.
- **Fluent query builder** that pushes conditions down to the engine's specialized indexes for sub-millisecond lookups: bitmap equality/IN, learned-range, null checks, FM-index full-text search, HNSW vector similarity (`ann`), and sparse vector match.
- **Idempotent batch transactions** - all operations staged locally and committed atomically, with the engine enforcing unique, foreign key, and check constraints at commit time. Idempotency keys return the original response on duplicate commits, even after a crash.
- **Full SQL access** through the DataFusion-backed `/sql` endpoint: recursive CTEs, window functions, `CREATE TABLE AS SELECT`, materialized views, multi-statement execution, and the `mongreldb_fts_rank` relevance-scoring UDF.
- **Schema management**: typed table creation, full schema catalog, and per-table descriptors.
- **User/role/credentials management**: Argon2id-hashed catalog users, roles, `GRANT`/`REVOKE` table-level permissions, daemon HTTP Basic + Bearer auth, and a client-side permission validator that blocks SQL injection in grant strings.
- **Stored procedures**: install, list, drop, and call with typed arguments.
- **Maintenance**: compaction (all tables or per-table).
- **Pluggable HTTP transport**: cURL by default (with keep-alive connection pooling), a stream-based fallback, and a `TransportInterface` for custom adapters (e.g. a Guzzle/PSR-18 bridge).
- **Typed exception hierarchy**: `AuthException` (401/403), `NotFoundException` (404), `ConstraintException` (409, with error code + op index), `ConnectionException` (network), and `QueryException` (everything else).
- **Robust JSON handling**: malformed UTF-8 is substituted rather than rejecting the whole request; INF/NAN and recursive structures raise a clear `QueryException` instead of corrupting data.

## Examples

Runnable, commented examples live in [`examples/`](examples):

- [Basic CRUD](examples/basic_crud.php) - connect, create a table, insert, query, count.
- [Authentication](examples/auth_example.php) - users, roles, table-level permissions, credential verification.
- [Transactions](examples/transactions.php) - batch commits, idempotency keys, constraint handling.
- [SQL queries](examples/sql_queries.php) - recursive CTEs, window functions, advanced SQL.

## Quick Example

```php
use Visorcraft\MongrelDB\Database;

// Connect to a running mongreldb-server daemon
$db = new Database('http://127.0.0.1:8453');

// Create a table with an enum column, a regex CHECK, and a server-side default
$db->getClient()->post('/kit/create_table', [
    'name' => 'orders',
    'columns' => [
        ['id' => 1, 'name' => 'id',             'ty' => 'int64',           'primary_key' => true,  'nullable' => false],
        ['id' => 2, 'name' => 'customer_email', 'ty' => 'varchar',         'primary_key' => false, 'nullable' => false],
        ['id' => 3, 'name' => 'amount',         'ty' => 'float64',         'primary_key' => false, 'nullable' => false],
        ['id' => 4, 'name' => 'status',         'ty' => 'enum',            'primary_key' => false, 'nullable' => false,
         'enum_variants' => ['new', 'paid', 'cancelled']],
        ['id' => 5, 'name' => 'created_at',     'ty' => 'timestamp_nanos', 'primary_key' => false, 'nullable' => false,
         'default_expr' => 'now'],
    ],
    'constraints' => [
        'checks' => [[
            'id' => 1,
            'name' => 'ck_customer_email',
            'expr' => ['Regex' => [
                'col' => 2,
                'pattern' => '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$',
                'negated' => false,
                'case_insensitive' => true,
            ]],
        ]],
    ],
]);

// Insert rows; created_at is filled by the default_value above
$db->put('orders', [1 => 1, 2 => 'alice@example.com', 3 => 99.50,  4 => 'new']);
$db->put('orders', [1 => 2, 2 => 'bob@example.com',   3 => 150.00, 4 => 'paid']);

// Upsert (insert or update on PK conflict)
$db->upsert('orders', [1 => 1, 2 => 'alice@example.com', 3 => 120.00, 4 => 'paid'], updateCells: [3 => 120.00, 4 => 'paid']);

// Query with a native index condition (learned-range index)
$rows = $db->query('orders')
    ->where('range', ['column' => 3, 'min' => 100.0])
    ->projection([1, 2, 4])
    ->limit(100)
    ->execute();

echo $db->count('orders'); // 2

// Run SQL
$db->sql("UPDATE orders SET amount = 200.0 WHERE customer_email = 'bob@example.com'");
```

## Authentication

```php
// Bearer token (--auth-token mode)
$db = new Database('http://127.0.0.1:8453', token: 'my-secret-token');

// HTTP Basic (--auth-users mode)
$db = new Database('http://127.0.0.1:8453', username: 'admin', password: 's3cret');
```

## Batch transactions

Operations are staged locally and committed atomically. The engine enforces
unique, foreign key, and check constraints at commit time.

```php
$txn = $db->beginTransaction();
$txn->put('orders', [1 => 10, 2 => 'Dave', 3 => 50.00]);
$txn->put('orders', [1 => 11, 2 => 'Eve',  3 => 75.00]);
$txn->deleteByPk('orders', 2);

try {
    $txn->commit();                       // atomic - all or nothing
    echo "Staged {$txn->count()} operations\n";
} catch (\Visorcraft\MongrelDB\Exceptions\ConstraintException $e) {
    echo "Constraint violated: {$e->errorCode} - {$e->getMessage()}\n";
    $txn->rollback();
}

// Idempotent commit - safe to retry; daemon returns the original response
$txn = $db->beginTransaction();
$txn->put('orders', [1 => 20, 2 => 'Frank', 3 => 100.00]);
$txn->commit(idempotencyKey: 'order-20-create');
```

## Native query builder

Conditions push down to the engine's specialized indexes. The builder accepts
friendly aliases that are translated to the server's on-wire keys: `column`
(-> `column_id`), `min`/`max` (-> `lo`/`hi`). The canonical keys are also
accepted directly.

```php
// Bitmap equality (low-cardinality columns)
$db->query('orders')->where('bitmap_eq', ['column' => 2, 'value' => 'Alice'])->execute();

// Range query (learned-range index)
$db->query('orders')
    ->where('range', ['column' => 3, 'min' => 50.0, 'max' => 150.0])
    ->limit(100)->execute();

// Full-text search (FM-index)
$db->query('documents')
    ->where('fm_contains', ['column' => 2, 'pattern' => 'database performance'])
    ->limit(10)->execute();

// Vector similarity search (HNSW)
$db->query('embeddings')
    ->where('ann', ['column' => 2, 'query' => [0.1, 0.2, 0.3], 'k' => 10])
    ->execute();

// Check whether a result was capped by the limit
$query = $db->query('orders')->where('range', ['column' => 3, 'min' => 0])->limit(100);
$rows = $query->execute();
if ($query->truncated()) {
    // result set hit the limit; more matches exist on the server
}
```

## SQL

```php
$db->sql("INSERT INTO orders (id, customer, amount) VALUES (99, 'Zoe', 999.0)");
$db->sql("CREATE TABLE archive AS SELECT * FROM orders WHERE amount > 500");

// Recursive CTEs and window functions
$db->sql("WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n<10) SELECT n FROM r");
$db->sql("SELECT id, ROW_NUMBER() OVER (PARTITION BY customer ORDER BY amount DESC) FROM orders");
```

## ANN index backends

The engine's `ann` index is swappable across three backends - `hnsw` (the default), `diskann`, and `ivf` - selected with the `algorithm` option. Quantization is independently configurable: `dense`, `binary_sign`, or `product` (product quantization, with `num_subvectors`, `bits_per_subvector`, `pq_training_samples`, `pq_seed`, and `pq_rerank_factor`). These are ordinary DDL strings run through `sql`, so no client changes are needed.

```php
// DiskANN (in-memory Vamana graph)
$db->sql("CREATE INDEX orders_emb_diskann ON orders USING ann (embedding) WITH (algorithm = 'diskann', quantization = 'dense', diskann_l = 50, diskann_r = 64, beam_width = 8)");

// IVF with dense vectors (clustered)
$db->sql("CREATE INDEX orders_emb_ivf ON orders USING ann (embedding) WITH (algorithm = 'ivf', quantization = 'dense', nlist = 1024, nprobe = 16)");

// HNSW with product quantization (recall-tuned)
$db->sql("CREATE INDEX orders_emb_hnsw_pq ON orders USING ann (embedding) WITH (algorithm = 'hnsw', quantization = 'product', m = 16, ef_construction = 200, ef_search = 50, num_subvectors = 32, pq_training_samples = 50000, pq_rerank_factor = 8)");
```


## User & role management

```php
$db->createUser('admin', 's3cret-pw');
$db->setUserAdmin('admin', true);

$db->createRole('analyst');
$db->grantPermission('analyst', 'select:orders');   // validated client-side
$db->grantRole('alice', 'analyst');

if ($db->verifyUser('alice', 'alice-pw')) { echo "Authenticated\n"; }

print_r($db->users());   // [['username' => 'admin'], ['username' => 'alice']]
print_r($db->roles());   // [['role' => 'analyst']]
```

## Performance: persistent cURL sharing (PHP 8.5+)

By default the client pools keep-alive connections **within** a single PHP
request. On PHP 8.5+, opt in to sharing DNS resolution, TLS sessions, and the
connection pool **across** requests (e.g. between PHP-FPM invocations) for
further latency reductions on the same daemon host:

```php
// Enable with sensible defaults (DNS + TLS sessions + connection pool)
$db = new Database('http://127.0.0.1:8453', persistentSharing: true);

// Or pass an explicit list of CURL_LOCK_DATA_* constants
use Visorcraft\MongrelDB\Transport\CurlTransport;
$transport = new CurlTransport(persistentSharing: [\CURL_LOCK_DATA_DNS]);
```

Off by default; silently degrades to per-request pooling on PHP 8.4.
`CURL_LOCK_DATA_COOKIE` is rejected - it would leak cookies across requests and
is unsafe for a stateless database client.

## Custom HTTP transport

```php
use Visorcraft\MongrelDB\Transport\CurlTransport;

$transport = new CurlTransport(timeout: 60, connectTimeout: 5);
$client = new MongrelDB('http://127.0.0.1:8453', token: 'secret', transport: $transport);
$db = new Database(client: $client);
```

## Error handling

```php
use Visorcraft\MongrelDB\Exceptions;

try {
    $db->put('orders', [1 => 1]); // duplicate PK
} catch (Exceptions\ConstraintException $e) {
    echo "Constraint: {$e->errorCode}\n";          // UNIQUE_VIOLATION
} catch (Exceptions\AuthException $e) {
    echo "Not authorized: {$e->getMessage()}\n";
} catch (Exceptions\NotFoundException $e) {
    echo "Not found: {$e->getMessage()}\n";
} catch (Exceptions\ConnectionException $e) {
    echo "Can't reach daemon: {$e->getMessage()}\n";
} catch (Exceptions\MongrelDBException $e) {
    echo "Error: {$e->getMessage()}\n";
}
```

Enum-domain failures and regex/check-constraint failures are reported by the
server as `ConstraintException` with `$e->errorCode === 'CHECK_VIOLATION'`.

## API reference

### `Database` class

| Method | Description |
|--------|-------------|
| `health(): bool` | Check daemon health |
| `historyRetentionEpochs(): int` | Current history-retention window (epochs) |
| `earliestRetainedEpoch(): int` | Oldest epoch still queryable with `AS OF EPOCH` |
| `setHistoryRetentionEpochs(int $epochs): array` | Set the history-retention window; requires admin |
| `tables(): array` | List table names |
| `createTable(string $name, array $columns, array $constraints = [], array $indexes = []): int` | Create a table with all index definitions |
| `dropTable(string $name): void` | Drop a table |
| `count(string $table): int` | Row count |
| `put(string $table, array $cells, ?string $idempotencyKey): array` | Insert a row |
| `upsert(string $table, array $cells, ?array $updateCells, ?string $idempotencyKey): array` | Upsert a row |
| `delete(string $table, int $rowId): void` | Delete by row ID |
| `deleteByPk(string $table, mixed $pk): void` | Delete by primary key |
| `query(string $table): QueryBuilder` | Start a native query |
| `sql(string $sql): array` | Execute SQL |
| `schema(): array` | Full schema catalog |
| `schemaFor(string $table): array` | Single table schema |
| `createUser(string $user, string $pw): void` | Create a user |
| `dropUser(string $user): void` | Drop a user |
| `alterPassword(string $user, string $pw): void` | Change a password |
| `verifyUser(string $user, string $pw): bool` | Verify credentials |
| `setUserAdmin(string $user, bool $isAdmin): void` | Grant/revoke admin |
| `users(): array` | List users (row objects with a `username` key) |
| `createRole(string $name): void` | Create a role |
| `dropRole(string $name): void` | Drop a role |
| `roles(): array` | List roles (row objects with a `role` key) |
| `grantRole(string $user, string $role): void` | Grant role to user |
| `revokeRole(string $user, string $role): void` | Revoke role from user |
| `grantPermission(string $role, string $perm): void` | Grant permission |
| `revokePermission(string $role, string $perm): void` | Revoke permission |
| `procedures(): array` | List procedures |
| `procedure(string $name): array` | Get a procedure |
| `createProcedure(array $proc): array` | Install/replace a procedure |
| `dropProcedure(string $name): void` | Drop a procedure |
| `callProcedure(string $name, array $args): mixed` | Call a procedure |
| `compact(): array` | Compact all tables |
| `compactTable(string $name): array` | Compact one table |
| `beginTransaction(): Transaction` | Start a batch |

`createTable()` forwards each column array unchanged in the `columns` payload.
Column specs accept the standard keys (`id`, `name`, `ty`, `primary_key`,
`nullable`, `auto_increment`, `encrypted`, `encrypted_indexable`) plus:

| Column key | Description |
|------------|-------------|
| `enum_variants` | String variants for `ty => 'enum'`; required and non-empty for enum columns |
| `default_value` | Static JSON scalar default filled in when a row omits the column. Explicit `null` stays a static null; a missing key means no default. Literal strings `"now"` or `"uuid"` here are static, not dynamic. |
| `default_expr` | Dynamic default expression; only `"now"` and `"uuid"` are accepted by the server. Use this instead of `default_value` for dynamic defaults. |

All supported static-default shapes pass through with their original JSON types:

```php
$db->createTable('events', [
    ['id' => 1, 'name' => 'message', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false,
     'default_value' => 'none'],
    ['id' => 2, 'name' => 'count',   'ty' => 'int64',   'primary_key' => false, 'nullable' => false,
     'default_value' => 0],
    ['id' => 3, 'name' => 'active',  'ty' => 'bool',    'primary_key' => false, 'nullable' => false,
     'default_value' => true],
    ['id' => 4, 'name' => 'extra',   'ty' => 'varchar', 'primary_key' => false, 'nullable' => true,
     'default_value' => null],          // explicit JSON null
    ['id' => 5, 'name' => 'tag',     'ty' => 'varchar', 'primary_key' => false, 'nullable' => false,
     'default_value' => 'now'],         // static literal, not dynamic
    ['id' => 6, 'name' => 'created', 'ty' => 'timestamp', 'primary_key' => false, 'nullable' => false,
     'default_expr' => 'now'],          // dynamic default
]);
```

Table-level check constraints are passed as the optional third argument under
`constraints.checks`:

```php
$db->createTable('orders', $columns, [
    'checks' => [[
        'id' => 1,
        'name' => 'ck_email',
        'expr' => ['Regex' => [
            'col' => 2,
            'pattern' => '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$',
            'negated' => false,
            'case_insensitive' => true,
        ]],
    ]],
]);
```

### `QueryBuilder` class

| Method | Description |
|--------|-------------|
| `where(string $type, array $params): static` | Add a native condition |
| `projection(array $columnIds): static` | Set column projection |
| `limit(int $limit): static` | Set row limit |
| `offset(int $offset): static` | Skip matching rows before the limit |
| `build(): array` | Build the request payload |
| `execute(): array` | Run the query |

### `Transaction` class

| Method | Description |
|--------|-------------|
| `put(string $table, array $cells): static` | Stage an insert |
| `upsert(string $table, array $cells, ?array $updateCells): static` | Stage an upsert |
| `delete(string $table, int $rowId): static` | Stage a delete |
| `deleteByPk(string $table, mixed $pk): static` | Stage a delete by PK |
| `commit(?string $idempotencyKey): array` | Commit atomically |
| `rollback(): void` | Discard all operations |
| `count(): int` | Number of staged operations |

## Building and testing

The project uses PHPUnit 12 and has two testsuites:

- `unit` — fast offline conformance tests using a mock HTTP transport.
- `live` — integration tests against a real `mongreldb-server`. They skip
  automatically when `MONGRELDB_URL` is unset or unreachable.

```sh
composer install
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite live     # requires a daemon at http://127.0.0.1:8453
```

The unit suite exercises the HTTP contract, SQL-injection hardening, JSON edge
cases (INF/NAN, malformed UTF-8, recursion), transport behavior, transaction
state machines, and the optional persistent-sharing feature.

## Contributing

Contributions are welcome. Please:

1. Open an issue first for non-trivial changes.
2. Add focused tests near your change - the suite must stay green.
3. Keep PHP 8.4 as the minimum supported version (PHP 8.5-only features must
   degrade gracefully, detected at runtime via `function_exists`/`defined`).
4. Match the existing style: strict types, `declare(strict_types=1)`, tabs,
   `readonly` properties where applicable, and `#[\Override]` on overridden
   methods.

## History retention

History retention controls how far back `AS OF EPOCH` time-travel queries can
read. Use these methods with `mongreldb-server` 0.48.0+:

```php
$window   = $db->historyRetentionEpochs();   // current retention window
$earliest = $db->earliestRetainedEpoch();    // oldest readable epoch

// Increase the window. Requires admin auth. Increasing retention cannot
// restore history already pruned past the previous earliest epoch.
$db->setHistoryRetentionEpochs($window + 10);

// Query historical state.
$rows = $db->sql('SELECT id FROM orders AS OF EPOCH ' . (int) $earliest);
```

## License

Dual-licensed under the **MIT License** or the **Apache License, Version 2.0**,
at your option. See [MIT](LICENSE-MIT) OR [Apache-2.0](LICENSE-APACHE) for the full text.

`SPDX-License-Identifier: MIT OR Apache-2.0`
