# Changelog

All notable changes to `laravel-migration` will be documented in this file

## 2.0.1

- Dropped elapsed time, the estimated-time reading and the memory reading from the queue progress bar. The times rendered as `< 1 ms` with ragged padding and told you nothing useful — progress here is driven by queue depth, which moves in bursts as workers pick chunks up, so a linear extrapolation is noise. The memory figure was the command process's own, not the workers' doing the migrating. The bar now reads `1204/2409 jobs [=====>----] 49%`.
- The progress bar is redrawn after each poll rather than before it, so the figure shown is the depth just observed instead of the previous round's.

## 2.0.0

Correctness, performance and reporting work across the command and the chunk
spawner. Applications only using the `migration:run` / `migration:new-job`
commands should upgrade cleanly; read the breaking changes first if you call
the package's classes directly or run it unattended.

### Breaking changes

- **`MigrationCommand::waitForEmptyQueue()` is now `awaitQueue()`** and returns `bool` (whether the queue drained without failures) instead of `void`.
- **`migrateTable()`, `migrateJobGroup()` and `migrateAllTables()` return `bool`** instead of `void`, reporting whether the work succeeded.
- **`migration:run` exits non-zero** when a table is unmapped, its job class is missing, a configured column does not exist, or any job failed while the run was waiting. It previously printed the error and exited `0`.
- **The queue-wait loop is scoped to the configured queue name** and, on Redis, also counts the `:delayed` set. If your migration queue is shared with the rest of the application, a delayed or released job on that queue will now hold the run until it clears. Give migrations their own queue (`MIGRATION_QUEUE_NAME`) to avoid this.
- **A `queue_connection` other than `database` or `redis` is rejected** during environment verification rather than throwing partway through a run.
- **Chunk jobs are pushed with the queue driver's bulk API**, which bypasses the Bus dispatcher. Job middleware, `ShouldBeUnique` and `after_commit` do not apply to chunk jobs.
- **Laravel 11 is the minimum.** The package uses the Laravel 11 schema inspection API (`getIndexes()` / `getColumns()`); on Laravel 10 those throw, get swallowed, and the package silently degrades to unordered chunking.
- Protected members of `MigrationJobSpawner` were renamed: `getChunkBoundaries()` → `computeChunkBoundaries()`, `dispatchChunk()` → `dispatchChunks()`, `dispatchOffsetChunks()` → `offsetChunkQueries()`.

### Fixed

- The published config no longer maps `USERS` to `App\Jobs\Migration\UsersMigrationJob`. That class does not exist in a freshly installed app, so environment verification failed and every `migration:run` aborted until the entry was commented out by hand.
- The queue-wait loop now counts only jobs on the configured migration queue, on the queue driver's own database connection and table (`queue.connections.*.connection` / `.table`). It previously ran `DB::table('jobs')->count()` against the default connection, so unrelated application jobs kept it spinning and a renamed or relocated jobs table broke it outright.
- `composer.json` declares its `illuminate/*` constraints (`^11.0|^12.0|^13.0`), and the suite runs against all three. The package uses the Laravel 11 schema inspection API (`getIndexes()` / `getColumns()`); on Laravel 10 those throw, get swallowed, and the package silently degrades to unordered chunking.
- Selected columns are now emitted as pre-quoted expressions, so a source table whose name contains a dot stays one identifier. Column aliases are unchanged (`USERS.FNAME` is still `U_FNAME`).
- Join definitions only require `table`, `first` and `second`. `operator` defaults to `=` and `type` defaults to `inner`, matching what the config comments already promised; omitting either previously raised an undefined-key error.
- The generated job stub no longer calls `fill()` on an undefined `$record`, which made every freshly scaffolded job fatal on first run.
- `--all` warns when `table_dependency_groups` is empty instead of reporting "Migration completed." having dispatched nothing.
- Added a test suite (`composer test`) covering the above, plus the 1.1.0 chunking behaviour.

### Performance

- Chunks are now planned in the command process instead of in a queued spawner job. Planning a large table outran the worker's `--timeout` (60s) and the connection's `retry_after` (90s); a released spawner then re-dispatched chunks it had already dispatched, migrating those rows twice.
- Chunk jobs are pushed through the queue driver's bulk API in batches of 500 — one multi-row insert for the database driver, one pipelined transaction for Redis — instead of one round trip per chunk. Dispatching 50 chunks went from 50 writes to 1.
- Added an optional `columns` key to the table mapping. Chunk queries previously selected every column of every table involved, on the source read and in each job's queue payload. Projecting 4 of 60 columns cut the queued payload for one table by 77%.
- The keyset boundary query now reports the distinct key count via `COUNT(*) OVER ()`, removing the separate `count(distinct ...)` scan. A table is walked once before dispatch, not twice.
- Source schema (columns and indexes) is read once per table and cached, instead of once in the constructor and again when building the select. Per-table pre-flight queries went from 7 to 4.
- `BaseMigrationJob::handle()` streams the chunk with a cursor rather than materialising it. A 5,000-row chunk handled row by row peaks at ~128 KB instead of ~14 MB.
- The queue-wait loop backs off from 1s towards 10s while the queue is not moving and drops back to 1s as soon as it is, instead of counting rows every second for the length of the migration.

### Experience

- Failures are reported. The command watches `failed_jobs` for the migration queue while a group runs, names the count at the end, and exits non-zero. Previously a run could lose every chunk and still print "Migration completed."
- Added `--dry-run`, which reports the tables, row counts and job counts a run would produce without dispatching anything.
- Added `--chunk-size=N`, `--queue`, `--sync` and `--no-wait`. `--queue` makes the previously unreachable queued single-table path usable; `--sync` runs everything without a worker.
- Added `BaseMigrationJob::handleChunk()`, a per-chunk hook for jobs that can batch their destination writes. `handleItem()` still works unchanged.
- Mapped tables missing from every dependency group are named at the start of `--all` instead of being silently skipped.
- The progress bar shows elapsed time, an ETA and memory, and tracks completed jobs directly rather than inferring them from queue-depth deltas.

## 1.1.0

Deterministic chunking. This is a behavioural change, not a bugfix only patch — chunk composition changes and `jobCount` semantics shift from rows to distinct keys. Apply it between full migration runs, never mid-migration.

- Chunk jobs are now split by primary key range (keyset chunking) instead of `LIMIT`/`OFFSET` with no `ORDER BY`. Offset chunking without a total order let the database return a different row sequence per chunk query, which could process a source row twice or skip it silently. Range chunks also drop the `OFFSET m` scan, whose cost grew quadratically across a full table walk.
- Keyset chunking engages when the source table has a single column integer primary key and the engine supports window functions (MySQL 8.0+ / MariaDB 10.2+; every other supported driver qualifies).
- Tables that do not qualify keep offset chunking, but now carry an `ORDER BY` over the primary key. Tables with no primary key at all keep the previous behaviour and log a warning naming the table.
- `MigrationJobSpawner::$totalCount` counts distinct keys rather than joined rows when keyset chunking is active, so the progress bar and `jobCount` stay accurate under a one to many join.

## 1.0.0

- initial release
