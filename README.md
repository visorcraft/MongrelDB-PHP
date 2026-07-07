# MongrelDB PHP Client

A professional, pure-PHP client library for [MongrelDB](https://github.com/visorcraft/MongrelDB) — a fast embedded+server database with SQL, vector search, full-text search, and AI-native retrieval.

Requires **PHP 8.4+**. No C extensions, no compilation. Install via Composer.

## Install

```sh
composer require visorcraft/mongreldb-php
```

## Quick start

```php
use Visorcraft\MongrelDB\Database;

// Connect to a running mongreldb-server daemon
$db = new Database('http://127.0.0.1:8453');

// Create a table
$db->createTable('orders', [
    ['id' => 1, 'name' => 'id',       'ty' => 'int64',  'primary_key' => true, 'nullable' => false],
    ['id' => 2, 'name' => 'customer', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
    ['id' => 3, 'name' => 'amount',   'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
]);

// Insert rows
$db->put('orders', [1 => 1, 2 => 'Alice', 3 => 99.50]);
$db->put('orders', [1 => 2, 2 => 'Bob',   3 => 150.00]);

// Query with native index conditions
$rows = $db->query('orders')
    ->where('range', ['column' => 3, 'min' => 100.0])
    ->execute();

// Count
echo $db->count('orders'); // 2

// Run SQL
$db->sql("UPDATE orders SET amount = 200.0 WHERE customer = 'Bob'");
```

## Authentication

```php
// Bearer token (--auth-token mode)
$db = new Database('http://127.0.0.1:8453', token: 'my-secret-token');

// HTTP Basic (--auth-users mode)
$db = new Database('http://127.0.0.1:8453', username: 'admin', password: 's3cret');
```

## CRUD operations

```php
// Insert
$result = $db->put('orders', [1 => 3, 2 => 'Carol', 3 => 75.25]);

// Upsert (insert or update on PK conflict)
$db->upsert('orders', [1 => 1, 2 => 'Alice', 3 => 120.00], updateCells: [3 => 120.00]);

// Delete by primary key
$db->deleteByPk('orders', 3);

// Delete by internal row ID
$db->delete('orders', 42);
```

## Batch transactions

All operations are staged locally and committed atomically. The engine
enforces unique, foreign key, and check constraints at commit time.

```php
$txn = $db->beginTransaction();
$txn->put('orders', [1 => 10, 2 => 'Dave', 3 => 50.00]);
$txn->put('orders', [1 => 11, 2 => 'Eve',  3 => 75.00]);
$txn->deleteByPk('orders', 2);

try {
    $results = $txn->commit(); // atomic — all or nothing
    echo "Inserted {$txn->count()} operations\n";
} catch (\Visorcraft\MongrelDB\Exceptions\ConstraintException $e) {
    echo "Constraint violated: {$e->errorCode} — {$e->getMessage()}\n";
    $txn->rollback();
}
```

### Idempotent transactions

Provide an idempotency key for safe retries — the daemon returns the
original response on duplicate keys, even after a crash:

```php
$txn = $db->beginTransaction();
$txn->put('orders', [1 => 20, 2 => 'Frank', 3 => 100.00]);
$results = $txn->commit(idempotencyKey: 'order-20-create');
```

## Native query builder

The query builder pushes conditions down to the engine's specialized indexes
for sub-millisecond lookups:

```php
// Bitmap equality (low-cardinality columns)
$rows = $db->query('orders')
    ->where('bitmap_eq', ['column' => 2, 'value' => 'Alice'])
    ->execute();

// Range query (learned-range index)
$rows = $db->query('orders')
    ->where('range', ['column' => 3, 'min' => 50.0, 'max' => 150.0])
    ->projection([1, 2])
    ->limit(100)
    ->execute();

// Full-text search (FM-index)
$rows = $db->query('documents')
    ->where('fm_contains', ['column' => 2, 'value' => 'database performance'])
    ->limit(10)
    ->execute();

// Vector similarity search (HNSW)
$rows = $db->query('embeddings')
    ->where('ann', [
        'column' => 2,
        'query'  => [0.1, 0.2, 0.3, /* ... */],
        'k'      => 10,
    ])
    ->execute();
```

## User & role management

```php
// Create an admin
$db->createUser('admin', 's3cret-pw');
$db->setUserAdmin('admin', true);

// Create a role with table-level permissions
$db->createRole('analyst');
$db->grantPermission('analyst', 'select:orders');
$db->grantPermission('analyst', 'select:customers');
$db->grantRole('alice', 'analyst');

// Verify credentials
if ($db->verifyUser('alice', 'alice-pw')) {
    echo "Authenticated\n";
}

// List users and roles
print_r($db->users());   // ['admin', 'alice']
print_r($db->roles());   // ['analyst']
```

## Stored procedures

```php
// List procedures
$procs = $db->procedures();

// Call a procedure
$result = $db->callProcedure('calculate_total', ['customer_id' => 1]);

// Install a procedure
$db->createProcedure([
    'name' => 'get_count',
    'mode' => 'read_only',
    'body' => ['steps' => [['SqlQuery' => ['sql' => 'SELECT count(*) FROM orders']]]],
    'params' => [],
]);
```

## SQL

```php
// Run any SQL statement
$db->sql("INSERT INTO orders (id, customer, amount) VALUES (99, 'Zoe', 999.0)");
$db->sql("CREATE TABLE archive AS SELECT * FROM orders WHERE amount > 500");

// Advanced SQL features
$db->sql("WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n<10) SELECT n FROM r");
$db->sql("SELECT id, ROW_NUMBER() OVER (PARTITION BY customer ORDER BY amount DESC) FROM orders");
```

## Error handling

```php
use Visorcraft\MongrelDB\Exceptions;

try {
    $db->put('orders', [1 => 1]); // duplicate PK
} catch (Exceptions\ConstraintException $e) {
    echo "Constraint: {$e->errorCode}\n"; // UNIQUE_VIOLATION
    echo "Message: {$e->getMessage()}\n";
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

## Custom HTTP transport

The client uses cURL by default. You can inject any PSR-7-compatible transport:

```php
use Visorcraft\MongrelDB\Database;
use Visorcraft\MongrelDB\Transport\CurlTransport;

$transport = new CurlTransport(timeout: 60, connectTimeout: 5);
$client = new MongrelDB('http://127.0.0.1:8453', token: 'secret', transport: $transport);
$db = new Database(client: $client);
```

## API reference

### `Database` class

| Method | Description |
|--------|-------------|
| `health(): bool` | Check daemon health |
| `tables(): array` | List table names |
| `createTable(string $name, array $columns): int` | Create a table |
| `dropTable(string $name): void` | Drop a table |
| `count(string $table): int` | Row count |
| `put(string $table, array $cells, ?string $key): array` | Insert a row |
| `upsert(string $table, array $cells, ?array $update, ?string $key): array` | Upsert a row |
| `delete(string $table, int $rowId): void` | Delete by row ID |
| `deleteByPk(string $table, mixed $pk): void` | Delete by primary key |
| `query(string $table): QueryBuilder` | Start a native query |
| `sql(string $sql): array` | Execute SQL |
| `schema(): array` | Full schema catalog |
| `schemaFor(string $table): array` | Single table schema |
| `createUser(string $user, string $pw): void` | Create a user |
| `dropUser(string $user): void` | Drop a user |
| `users(): array` | List usernames |
| `createRole(string $name): void` | Create a role |
| `grantRole(string $user, string $role): void` | Grant role to user |
| `grantPermission(string $role, string $perm): void` | Grant permission |
| `procedures(): array` | List procedures |
| `callProcedure(string $name, array $args): mixed` | Call a procedure |
| `compact(): array` | Compact all tables |
| `beginTransaction(): Transaction` | Start a batch |

### `QueryBuilder` class

| Method | Description |
|--------|-------------|
| `where(string $type, array $params): static` | Add a native condition |
| `projection(array $columnIds): static` | Set column projection |
| `limit(int $limit): static` | Set row limit |
| `execute(): array` | Run the query |

### `Transaction` class

| Method | Description |
|--------|-------------|
| `put(string $table, array $cells, bool $returning): static` | Stage an insert |
| `upsert(string $table, array $cells, ?array $update): static` | Stage an upsert |
| `delete(string $table, int $rowId): static` | Stage a delete |
| `deleteByPk(string $table, mixed $pk): static` | Stage a delete by PK |
| `commit(?string $key): array` | Commit atomically |
| `rollback(): void` | Discard all operations |
| `count(): int` | Number of staged operations |

## License

MIT OR Apache-2.0
