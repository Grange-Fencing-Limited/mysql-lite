# grangefencing-mysql-lite

Lightweight helper for creating a PDO MySQL connection and managing simple transactions.

This repository exposes `GrangeFencing\MySqlLite\MySqlConnection` — a small wrapper around PDO that handles connecting, transactions and a couple of convenience helpers.

## Quick usage example

Copy this snippet into a file (for example `examples/using-connection.php`) and run it with `php` or include it in your project.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use GrangeFencing\MySqlLite\MySqlConnection;
use const GrangeFencing\MySqlLite\MYSQL_INTEGRITY_CONSTRAINT_VIOLATION;

// Option A: Pass connection details directly (overrides environment values)
$override = [
    'database_host'     => '127.0.0.1',
    'database_port'     => '3306',
    'database_name'     => 'test_db',
    'database_username' => 'test_user',
    'database_password' => 'secret',
];

$db = new MySqlConnection($override);
$pdo = $db->conn; // public PDO instance

try {
    // Enable debug logging to stdout (optional)
    $db->debugToConsole(true);

    // Start a transaction
    $db->beginTransaction();

    // Example insert using prepared statement
    $stmt = $pdo->prepare('INSERT INTO users (email, name) VALUES (:email, :name)');
    $stmt->execute([
        ':email' => 'alice@example.com',
        ':name'  => 'Alice',
    ]);

    // Get last insert id from the connection wrapper
    $insertId = $db->lastInsertId();

    // Commit the transaction
    $db->commit();

    echo "Inserted row id: {$insertId}\n";
}
catch (PDOException $e) {
    // Handle SQL errors. For example detect integrity constraint violations
    if ((int)$e->getCode() === MYSQL_INTEGRITY_CONSTRAINT_VIOLATION) {
        echo "Integrity constraint violation: " . $e->getMessage() . "\n";
        // take corrective action for duplicate keys / FK violations...
    } else {
        echo "Database error: " . $e->getMessage() . "\n";
    }

    // Roll back any active transaction
    $db->rollBack();
}
finally {
    // Close the connection when finished
    $db->close();
}
```

Notes
- The library reads connection details from the environment by default (DATABASE_HOST, DATABASE_PORT, DATABASE_NAME, DATABASE_USERNAME, DATABASE_PASSWORD). You can pass an override array (as shown above) to the constructor.
- The `MySqlConnection` wrapper exposes the raw PDO instance as the public property `->conn` so you can use any PDO features not provided by the wrapper.
- The constant `MYSQL_INTEGRITY_CONSTRAINT_VIOLATION` is defined in the library's namespace. You can import it with `use const` as shown above, or reference it as `\\GrangeFencing\\MySqlLite\\MYSQL_INTEGRITY_CONSTRAINT_VIOLATION`.

## Extending MySqlTable (recommended pattern)

`MySqlTable` is designed to be extended by a small table-specific class that contains methods for the common queries you want to run against a particular table. This keeps your SQL close to the table logic and allows the shared execution, error handling and automatic HTTP response behavior implemented by `MySqlTable`.

Below is an example of creating a `Staff` table class that extends `MySqlTable`, and how to use it in an API handler.

Example usage (in a request handler):

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use GrangeFencing\MySqlLite\MySqlConnection;
use App\Tables\Staff; // your class that extends MySqlTable

$db = new MySqlConnection();

// Chain setters and automatic response behavior, then call the table method.
$staff = (new Staff($db))
    ->setProperty(":site", $_POST["site"])
    ->setProperty(":id", $_POST["id"])
    ->automatic204();

$data = $staff->get();

// Access the results
if ($staff->wasSuccess) {
    // $staff->data contains the results (or object when singleRowReturn enabled)
    var_dump($staff->data);
}
```

And inside your `Staff` table class (e.g. `src/Tables/Staff.php`):

```php
<?php

namespace App\Tables;

use GrangeFencing\MySqlLite\MySqlTable;

class Staff extends MySqlTable {

    public function get(): static {
        $sql = "SELECT * FROM staff";

        return $this
            ->prepareSql($sql)
            ->execute();
    }

}
```

Notes and tips
- Constructor: pass a `MySqlConnection` instance into your table class (see examples above). The `MySqlTable` constructor stores it and exposes `->conn` via the connection wrapper.
- Chaining: `prepareSql()` and `execute()` both return `$this`, so you can chain calls inside your table methods.
- Properties: use `setProperty()` and friends to populate bound parameters on the table instance (they become properties on the instance and can be bound in `get()` or other methods if you prefer `bindParam`).
- Automatic responses: use `automatic204()`, `automatic401()`, `automatic403()` and `automatic409()` to instruct `MySqlTable` to return specific HTTP responses when zero rows are returned or affected.
- Data shape: after calling `execute()`, the results are available in `->data`. Use `singleRowReturn()` or `flatDataReturn()` to change the shape of `->data`.