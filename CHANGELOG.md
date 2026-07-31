# Changelog

All notable changes to `laravel-migration` will be documented in this file

## 1.1.0

Deterministic chunking. This is a behavioural change, not a bugfix only patch — chunk composition changes and `jobCount` semantics shift from rows to distinct keys. Apply it between full migration runs, never mid-migration.

- Chunk jobs are now split by primary key range (keyset chunking) instead of `LIMIT`/`OFFSET` with no `ORDER BY`. Offset chunking without a total order let the database return a different row sequence per chunk query, which could process a source row twice or skip it silently. Range chunks also drop the `OFFSET m` scan, whose cost grew quadratically across a full table walk.
- Keyset chunking engages when the source table has a single column integer primary key and the engine supports window functions (MySQL 8.0+ / MariaDB 10.2+; every other supported driver qualifies).
- Tables that do not qualify keep offset chunking, but now carry an `ORDER BY` over the primary key. Tables with no primary key at all keep the previous behaviour and log a warning naming the table.
- `MigrationJobSpawner::$totalCount` counts distinct keys rather than joined rows when keyset chunking is active, so the progress bar and `jobCount` stay accurate under a one to many join.

## 1.0.0

- initial release
