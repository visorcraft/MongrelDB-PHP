# Quickstart

This guide walks through installing the MongrelDB PHP client, connecting to a
running `mongreldb-server`, and doing your first round-trip of CRUD and query.

## Prerequisites

- PHP 8.4 or newer with `ext-curl` and `ext-json`.
- A running [`mongreldb-server`](https://github.com/visorcraft/MongrelDB)
  daemon. The simplest start is the prebuilt Linux binary:

  ```sh
  curl -L -o mongreldb-server \
    https://github.com/visorcraft/MongrelDB/releases/download/v0.61.1/mongreldb-server-linux-x64
  chmod +x mongreldb-server
  ./mongreldb-server ./data --port 8453
  ```

## Install

```sh
composer require visorcraft/mongreldb-php
```

## Connect

```php
use Visorcraft\MongrelDB\Database;

$db = new Database('http://127.0.0.1:8453');
var_dump($db->health()); // true
```

## Create a table and insert rows

```php
$db->createTable('orders', [
    ['id' => 1, 'name' => 'id',       'ty' => 'int64',   'primary_key' => true,  'nullable' => false],
    ['id' => 2, 'name' => 'customer', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false],
    ['id' => 3, 'name' => 'amount',   'ty' => 'float64', 'primary_key' => false, 'nullable' => false],
    ['id' => 4, 'name' => 'status',   'ty' => 'varchar', 'primary_key' => false, 'nullable' => false,
     'enum_variants' => ['new', 'paid', 'cancelled'],
     'default_value' => 'new'],
    ['id' => 5, 'name' => 'created_at', 'ty' => 'timestamp', 'primary_key' => false, 'nullable' => false,
     'default_expr' => 'now'],
]);

$db->put('orders', [1 => 1, 2 => 'Alice', 3 => 99.50, 4 => 'new']);
$db->put('orders', [1 => 2, 2 => 'Bob',   3 => 150.00, 4 => 'paid']);

echo $db->count('orders'); // 2
```

## Schema options

Column descriptors are pass-through: any extra keys are forwarded verbatim to
the daemon. The most useful keys are `enum_variants`, `default_value`, and
`default_expr`. `default_value` may be any JSON scalar; an explicit `null`
stays a static null, a missing key means no default, and literal `"now"` /
`"uuid"` values in `default_value` are treated as static strings. Use
`default_expr` (`"now"` or `"uuid"`) for dynamic defaults:

```php
$db->createTable('events', [
    ['id' => 1, 'name' => 'message', 'ty' => 'varchar', 'primary_key' => false, 'nullable' => false,
     'default_value' => 'none'],
    ['id' => 2, 'name' => 'count',   'ty' => 'int64',   'primary_key' => false, 'nullable' => false,
     'default_value' => 0],
    ['id' => 3, 'name' => 'active',  'ty' => 'bool',    'primary_key' => false, 'nullable' => false,
     'default_value' => true],
    ['id' => 4, 'name' => 'extra',   'ty' => 'varchar', 'primary_key' => false, 'nullable' => true,
     'default_value' => null],
    ['id' => 5, 'name' => 'tag',     'ty' => 'varchar', 'primary_key' => false, 'nullable' => false,
     'default_value' => 'now'],
    ['id' => 6, 'name' => 'created', 'ty' => 'timestamp', 'primary_key' => false, 'nullable' => false,
     'default_expr' => 'now'],
]);
```

## Run a query

```php
$rows = $db->query('orders')
    ->where('pk', ['value' => 1])
    ->execute();
```

## History retention

Control the time-travel window and query historical rows with `AS OF EPOCH`:

```php
$window   = $db->historyRetentionEpochs();
$earliest = $db->earliestRetainedEpoch();

// Requires admin auth. Increasing the window cannot restore already-pruned
// history past the previous earliest epoch.
$db->setHistoryRetentionEpochs($window + 10);

$rows = $db->sql('SELECT id FROM orders AS OF EPOCH ' . (int) $earliest);
```

## Next steps

- See [`examples/`](../examples) for transactions, auth, and advanced SQL.
- Read the [`README.md`](../README.md) API reference for the full surface.
