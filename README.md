# Laravel Migration Utilities

A Laravel package to simplify the process of running repeatable data migrations and tracking migrated records. This package provides developer-friendly commands and utilities to help manage migration logic with safety and traceability.

## ✨ Features

- Create and scaffold migration jobs easily.
- Run a migration on a single table, or all tables in one command.
- Handle migration scale. From 100 rows to millions, jobs will be chunked and processed in a memory-safe way.

## 📦 Requirements

- Laravel 11, 12 or 13 (the suite runs against all three)
- PHP 8.2+
- A `database` or `redis` queue connection. These are the only two drivers the command's queue-wait logic understands; any other value for `queue_connection` will fail once a migration starts.
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

Publish the configuration file:
```bash
php artisan migration:install
```

> **Why `migration:install` and not `laravel-migration:install`?** `spatie/laravel-package-tools` builds the install command's name from the package's *short* name, which is the package name with the `laravel-` prefix stripped. The command is also registered as hidden, so it will not show up in `php artisan list`.
>
> If you prefer to publish the config directly:
> ```bash
> php artisan vendor:publish --tag=migration-config
> ```

## 🛠 Usage

1. Examine the laravel-migration config file. You will need to add entries into the `table_job_mapping` array as you add migration jobs.

2. run `php artisan migration:new-job {MigrationJobClassName}`

Create a new migration job class with boilerplate code. Each job will migrate old database data into the new laravel database table.

Update the `handleItem()` method to process a row from the source database into the laravel database.

3. run `php artisan migration:run {OldTableName}`

While developing your migration job, it is helpful to run a single migration job ignoring the job queue system. The above command will run the single job, synchronously (not as a background job).

4. run `php artisan migration:run --all`

This command will utilize values in the `table_dependency_groups` array to run migration jobs in a specific order. Keep in mind that you will need to run the job queue in a separate command line for jobs to be processed.

> `--all` iterates `table_dependency_groups`, **not** `table_job_mapping`. A table that is mapped but not listed in a dependency group is never migrated by `--all`; the command names any such table before it starts.

### Options

| Option | Effect |
| --- | --- |
| `--all`, `-A` | Migrate every table in `table_dependency_groups`, group by group. |
| `--group=N` | Start `--all` from group `N` instead of group 0. |
| `--dry-run` | Report the tables, row counts and job counts a run would produce, and dispatch nothing. |
| `--chunk-size=N` | Override the configured chunk size — and any per-table `chunk_size` — for this run. |
| `--queue` | Push a single table onto the queue instead of running it in this process. |
| `--sync` | Run everything in this process, with no queue worker. Useful for `--all` while developing. |
| `--no-wait` | Dispatch the jobs and exit instead of blocking until the queue drains. |

Before a long run, look at what it will do:

```bash
php artisan migration:run --all --dry-run
```

```
+-----------+-----------+-------+
| Table     | Rows      | Jobs  |
+-----------+-----------+-------+
| USERS     | 1,204,338 | 2,409 |
| COMPANIES | 88,120    | 177   |
+-----------+-----------+-------+
Dry run: 1,292,458 rows across 2 tables would be migrated in 2,586 jobs. Nothing was dispatched.
```

## ✅ Example

Chunk queries select every column of the source table (and of any joined tables) under an **alias**, so the properties on `$item` are not the raw column names. Each column is prefixed with the initials of its table name, split on underscores:

| Source table | Source column | Property on `$item` |
| --- | --- | --- |
| `USERS` | `FNAME` | `$item->U_FNAME` |
| `USER_COMPANY` | `NAME` | `$item->U_C_NAME` |

By default every column of the source table (and of any joined table) is selected, which is wasteful on wide legacy tables — each column is read from the source and carried in every chunk job's queue payload. Restrict the select with a `columns` key in the mapping:

```php
'USERS' => [
    'job'     => Migration\UsersMigrationJob::class,
    'columns' => ['FNAME', 'LNAME', 'EMAIL_ADDR'],
],
```

The base table's primary key is always included. Qualify a name with its table (`'USERS.EMAIL_ADDR'`) when a join makes it ambiguous. An unknown column is reported by name before anything is dispatched.

Here's how a typical `handleItem` function in a migration job might look:

```php
public function handleItem(object $item): void
{
    // Prepare the data to be stored into the Laravel model
    $data = [
        'first_name' => $item->U_FNAME,
        'last_name'  => $item->U_LNAME,
        'email'      => $item->U_EMAIL_ADDR,
        'updated_at' => $item->U_EDTIME ? Carbon::parse($item->U_EDTIME) : now(),
    ];

    // Store the data, saving quietly so that any model observers don't trigger.
    $record = new User();
    $record->fill($data);
    $record->saveQuietly();
}
```

### Migrating a chunk at a time

`handleItem()` runs once per row, which means one write per row. When the destination writes can be batched, override `handleChunk()` instead — one upsert of 500 rows rather than 500 saves is usually the largest speed-up available to a migration job:

```php
public function handleChunk(iterable $items): void
{
    User::upsert(
        collect($items)->map(fn (object $item): array => [
            'legacy_id'  => $item->U_ID,
            'first_name' => $item->U_FNAME,
        ])->all(),
        ['legacy_id'],
    );
}
```

Rows are streamed from the source, so a job that only implements `handleItem()` never holds the whole chunk in memory. Collecting the chunk, as above, opts into holding it.

### Bulk inserts across a relationship

The usual reason a migration job writes one row at a time is that it needs the
new record's id for its children. `$this->writer()` removes that constraint.

You don't need the parent's id when you *decide* a child row, only when you
*write* it — so plan the whole level in memory, write it in one statement, and
read the new ids back keyed by the legacy id the source row already carries:

```php
public function handleChunk(iterable $items): void
{
    $items = collect($items);

    // One statement, then one read-back: [legacy customer id => new user id].
    $userIds = $this->writer()->insertAndMap(
        'users',
        $items->map($this->toUser(...))->all(),
        'mig_customer_id',
    );

    // Children are planned against the legacy key and resolved through the map.
    $this->writer()->insert('registrants', $items->map(fn ($item) => [
        'user_id'    => $userIds[$item->C_ID],
        'first_name' => $item->C_FNAME,
    ])->all());
}
```

Two levels cost four queries per chunk instead of several per row. Deeper
relationships repeat the pattern — `insertAndMap()` the level, then build the
next one against the map it returns.

Logic split across traits can buffer into the shared writer instead and let the
chunk flush it:

```php
$this->writer()->add('login_history', [...]);   // called per row, from a trait
```

Buffered tables are written when the chunk finishes, in the order they were
first used. A level whose ids are needed downstream still has to be flushed
explicitly with `insertAndMap()` — the buffer is for leaf writes that nothing
references.

| Method | |
| --- | --- |
| `insert($table, $rows)` | Write rows now, split across as few statements as the placeholder limit allows. |
| `insertAndMap($table, $rows, $key)` | Write rows, then return `[legacy id => new id]`. |
| `idMap($table, $key, $values)` | Read back ids for legacy keys already written. |
| `add($table, $row)` / `addMany($table, $rows)` | Buffer for the end of the chunk. |
| `flush($table = null)` | Write one buffered table, or all of them. |

The writer is **insert-only**. It assumes each run starts from a fresh
destination, which is what makes a legacy id enough to identify a row. It also
gives every row in a batch the same columns — Laravel's `insert()` takes its
column list from the first row, so a row with different keys otherwise lands in
the wrong columns silently.

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

**Chunks are planned in the command process, not in a queued job.** Planning a large table takes longer than a worker's `--timeout` and longer than the connection's `retry_after`, and a released planning job re-dispatches chunks it had already dispatched — migrating those rows twice. Planning in the command removes that failure mode, and lets the command report row and job counts per table as it goes. Chunk jobs themselves are pushed to the queue in bulk: one multi-row insert per 500 jobs for the database driver, one pipelined transaction for Redis.

## 🚨 When jobs fail

While it waits for a group to drain, the command watches `failed_jobs` for the migration queue. Anything that fails during the run is reported and the command exits non-zero:

```
2 job(s) failed on the "migrations" queue. Inspect them with `php artisan queue:failed`.
```

Without a `failed_jobs` table there is nothing to watch, and failures are invisible — a migration can lose thousands of chunks and still look like it succeeded. Run `php artisan make:queue-failed-table` if your app has no such table.

## 🤖 For AI agents

The package ships an agent skill covering how to write a migration job that
performs: column projection, `handleChunk()` over `handleItem()`, and the
plan / insert / resolve pattern for writing a parent level and then its
children in one statement each.

[Laravel Boost](https://github.com/laravel/boost) discovers it automatically in
any application that requires this package directly — run `php artisan
boost:install` and enable `writing-migration-jobs`. It is installed to
`.ai/skills/` and linked into your agent's skills directory.

Without Boost, point your agent at it directly:

```
vendor/paper-leaf-tech/laravel-migration/resources/boost/skills/writing-migration-jobs/SKILL.md
```

## 🧪 Testing

```bash
composer install
composer test
```

The suite runs against in-memory SQLite via `orchestra/testbench`; no application or database setup is required.

## ⁉️ Common Issues

#### Running out of memory
Jobs are by default chunked to process 500 rows of data per job. If the job performs too much logic, or too many queries it may be helpful to lower this value for this job. You can do that by updating the migration job item under the laravel-migration configuration file's `table_job_mapping` key.

#### Property not fillable
The current structure requires that the model allows mass assignment of properties. You can add `protected $guarded = [];` to your model to allow all properties to be mass assigned.

#### `php artisan migration:run --all` finishes instantly
`--all` only walks `table_dependency_groups`. Populate that array — every table you want migrated needs an entry in a group, in dependency order.

#### The command hangs after dispatching jobs
`migration:run --all` waits for the queue to drain before moving to the next dependency group. Nothing drains it unless a worker is running, so start `php artisan queue:work --queue=<your migration queue>` in a separate terminal before dispatching. Use `--sync` to run without a worker, or `--no-wait` to dispatch and exit.
