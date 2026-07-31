# Laravel Migration Utilities

A Laravel package to simplify the process of running repeatable data migrations and tracking migrated records. This package provides developer-friendly commands and utilities to help manage migration logic with safety and traceability.

## ✨ Features

- Create and scaffold migration jobs easily.
- Run a migration on a single table, or all tables in one command.
- Handle migration scale. From 100 rows to millions, jobs will be chunked and processed in a memory-safe way.

## 📦 Requirements

- Laravel 11+
- PHP 8.2+
- If using a database queue the [jobs table](https://laravel.com/docs/12.x/queues#driver-prerequisites) must be present. A redis queue can ignore this requirement.

## 🚀 Installation

Add the repository to your `composer.json` file:
```json
"repositories": [
    {
        "type": "github",
        "url": "git@github.com:paper-leaf-tech/laravel-migration.git"
    }
],
```

Install via Composer:
```bash
composer require paper-leaf-tech/laravel-migration
```

Publish the configuration and migration files:
```bash
php artisan laravel-migration:install
```

## 🛠 Usage

1. Examine the laravel-migration config file. You will need to add entries into the `table_job_mapping` array as you add migration jobs.

2. run `php artisan migration:new-job {MigrationJobClassName}`

Create a new migration job class with boilerplate code. Each job will migrate old database data into the new laravel database table.

Update the `handleItem()` method to process a row from the source database into the laravel database.

3. run `php artisan migration:run {OldTableName}`

While developing your migration job, it is helpful to run a single migration job ignoring the job queue system. The above command will run the single job, synchronously (not as a background job).

4. run `php artisan migration:run --all`

This command will utilize values in the `table_dependency_groups` array to run migration jobs in a specific order. Keep in mind that you will need to run the job queue in a separate command line for jobs to be processed.

## ✅ Example

Here's how a typical `handleItem` function in a migration job might look:

```php
public function handleItem($item): void
{
    // Prepare the data to be stored into the Laravel model
    $data = [
        'first_name' => $item->FNAME,
        'last_name'  => $item->LNAME,
        'email'      => $item->EMAIL_ADDR,
        'updated_at' => $item->EDTIME ? Carbon::parse($item->EDTIME) : now(),
    ];

    // Store the data, saving quietly so that any model observers don't trigger.
    $record = new User();
    $record->fill($data);
    $record->saveQuietly();
}
```

## 🧩 How chunking works

Each source table is split into chunk jobs by **primary key range** (keyset chunking) rather than by `LIMIT`/`OFFSET`. Every chunk query carries an explicit `ORDER BY`, so a source row lands in exactly one chunk — no duplicates, no silent omissions — and the migration avoids the `OFFSET` scan that makes deep chunks progressively slower.

Keyset chunking engages when both hold:

- The source table has a **single-column integer primary key**. Boundary values are inlined into the chunk SQL rather than bound, so the integer restriction is what makes the query safe to build.
- The engine supports window functions — **MySQL 8.0+ or MariaDB 10.2+**. All other drivers Laravel supports qualify.

Otherwise the package falls back to offset chunking with an `ORDER BY` over the primary key, which is still deterministic. A table with **no primary key at all** has nothing stable to order by, so it keeps the old unordered behaviour and logs a warning naming the table. If you migrate such a table, add a primary key to the source or accept that rows may be processed twice or skipped.

Two things worth knowing:

- **Counts are in distinct keys.** When keyset chunking is active, the chunk size and the progress bar count distinct primary keys, not joined rows. A one-to-many join can therefore produce a chunk carrying more rows than `chunk_size`. This is deliberate: aligning boundaries to keys is what prevents a key from straddling two chunks.
- **Identifier quoting is MySQL/MariaDB specific.** Key columns are qualified with backticks, matching the existing handling of table names that contain a dot.

Chunk composition differs from versions before `1.1.0`. Upgrade between full migration runs, not partway through one.

## ⁉️ Common Issues

#### Running out of memory
Jobs are by default chunked to process 500 rows of data per job. If the job performs too much logic, or too many queries it may be helpful to lower this value for this job. You can do that by updating the migration job item under the laravel-migration configuration file's `table_job_mapping` key.

#### Property not fillable
The current structure requires that the model allows mass assignment of properties. You can add `protected $guarded = [];` to your model to allow all properties to be mass assigned.