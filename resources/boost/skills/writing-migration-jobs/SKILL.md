---
name: writing-migration-jobs
description:
  Writes and speeds up legacy data migration jobs built on
  paper-leaf-tech/laravel-migration. Use when creating or modifying a class in
  app/Jobs/Migration, editing config/laravel-migration.php, running
  migration:run or migration:new-job, or when a migration is slow, is running
  out of memory, or is writing one row at a time.
---

# Writing migration jobs

`paper-leaf-tech/laravel-migration` chunks a legacy source table and hands each
chunk to a job. The source is read over the `database_connection` configured in
`config/laravel-migration.php`; everything you write goes to the application's
default connection.

Scaffold with `php artisan migration:new-job {ClassName}`, then map the source
table in `config/laravel-migration.php` under `table_job_mapping`, and list it
in `table_dependency_groups` — **`--all` iterates the dependency groups, not the
mapping**, so a mapped table that is in no group is never migrated.

## Row properties are aliased, not raw column names

Every selected column is aliased with the initials of its table name, split on
underscores. This trips people up constantly:

| Source table | Column | Property |
| --- | --- | --- |
| `USERS` | `FNAME` | `$item->U_FNAME` |
| `USER_COMPANY` | `NAME` | `$item->U_C_NAME` |

## Select only the columns you use

Without a `columns` key, every column of the base table and of every joined
table is read from the source and carried in each chunk job's queue payload.
On a wide legacy table that dominates the cost of the migration.

```php
'customer' => [
    'job'        => Migration\CustomerMigrationJob::class,
    'chunk_size' => 500,
    'joins'      => [
        ['table' => 'application_user', 'first' => 'application_user.id', 'second' => 'customer.application_user_id', 'type' => 'LEFT'],
    ],
    // Unqualified names belong to the base table; qualify anything a join
    // would make ambiguous.
    'columns' => ['email_address', 'phone_number', 'application_user.username'],
],
```

The base table's primary key is always included. Qualify a name with its table
when a join makes it ambiguous. An unknown column is reported by name before
anything is dispatched.

## Prefer `handleChunk()` over `handleItem()`

`handleItem()` runs once per row, so it produces one write per row. Override
`handleChunk()` when the writes can be batched — this is almost always the
largest speed-up available.

```php
public function handleChunk(iterable $items): void
{
    $this->writer()->insert('users', collect($items)->map($this->toRow(...))->all());
}
```

Rows are streamed from the source, so a job that only implements `handleItem()`
never holds the whole chunk in memory. Calling `collect($items)` opts into
holding it — fine, and necessary for batching, but that is the trade.

## Writing a parent and then its children

This is the pattern to reach for when a job currently inserts a row so it can
use the new id for related records.

**You do not need the parent's id when you decide a child row, only when you
write it.** So plan the level in memory, write it in one statement, and read
the new ids back keyed by the legacy id the source row already carries.

```php
public function handleChunk(iterable $items): void
{
    $items = collect($items);

    // One insert, then one read-back: [legacy customer id => new user id].
    $userIds = $this->writer()->insertAndMap(
        'users',
        $items->map($this->toUser(...))->all(),
        'mig_customer_id',
    );

    // Children reference the map, and go out in one statement of their own.
    $this->writer()->insert('registrants', $items->map(fn ($item) => [
        'user_id'    => $userIds[$item->C_ID],
        'first_name' => $item->C_FNAME,
    ])->all());
}
```

Deeper levels repeat it: `insertAndMap()` the level, build the next one against
the map it returns.

This requires the destination table to carry the legacy id (a `mig_*` column).
If it does not, add one — nullable and indexed — before converting the job.

### Buffering from traits

Logic split across traits can buffer into the job's shared writer instead of
each writing its own rows. Buffered tables are flushed when the chunk finishes,
in the order they were first used:

```php
$this->writer()->add('login_history', [...]);   // called per row, from a trait
```

A level whose ids are needed downstream still has to be written explicitly with
`insertAndMap()`. The buffer is for leaf writes that nothing references.

### Writer API

| Method | |
| --- | --- |
| `insert($table, $rows)` | Write now, split to stay under the bound-parameter limit |
| `insertAndMap($table, $rows, $key)` | Write, then return `[legacy id => new id]` |
| `idMap($table, $key, $values)` | Read back ids for legacy keys already written |
| `add($table, $row)` / `addMany($table, $rows)` | Buffer for the end of the chunk |
| `flush($table = null)` | Write one buffered table, or all of them |
| `pending($table)` | How many rows are waiting |

The writer is insert-only. It assumes each run starts from a fresh destination,
which is what makes a legacy id enough to identify a row.

## Do not

- **Do not save one model per row** inside `handleItem()` when the rows could be
  collected and inserted together. This is the single most common reason a
  migration takes hours.
- **Do not call `insertGetId()` per parent** to get an id for children. Use
  `insertAndMap()` — that is what it is for.
- **Do not derive ids from `LAST_INSERT_ID()`** after a bulk insert. MySQL 8
  defaults to `innodb_autoinc_lock_mode=2`, where inserted ids are not
  guaranteed to be consecutive. Read them back by legacy key.
- **Do not pass rows with differing keys to the query builder's own `insert()`.**
  It takes its column list from the first row and sorts each row's values by
  key, so a row with a different key set lands in the wrong columns with nothing
  raised. `$this->writer()` gives every row in a batch the same columns; a
  missing value becomes an explicit null.
- **Do not query the source once per row** to enrich it. Prefetch what the whole
  chunk needs in `handleChunk()` with a single `whereIn`, then index it in
  memory.

## Running and checking

```bash
php artisan migration:run --all --dry-run      # tables, row counts, job counts
php artisan migration:run TABLE                # one table, in this process
php artisan migration:run TABLE --queue        # one table, onto the queue
php artisan migration:run --all                # every group, in order
php artisan migration:run --all --sync         # no queue worker needed
php artisan migration:run --all --chunk-size=50
```

`--all` waits for each dependency group to drain before starting the next, so a
worker must be running unless you pass `--sync` or `--no-wait`. Failures are
reported from `failed_jobs` and make the command exit non-zero; inspect them
with `php artisan queue:failed`.

Give migrations their own queue (`MIGRATION_QUEUE_NAME`) rather than sharing
`default`. The wait loop counts everything on the queue it is told to watch,
including delayed and reserved jobs from the rest of the application.

Lower `chunk_size` for jobs that do a lot of work per row; raise it for jobs
that batch their writes.
