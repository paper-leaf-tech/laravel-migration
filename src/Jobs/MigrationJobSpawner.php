<?php

namespace PaperleafTech\LaravelMigration\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class MigrationJobSpawner implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Column types eligible for keyset chunking. Boundary values are inlined
     * into the chunk SQL rather than bound, so restricting to integers makes
     * an (int) cast a complete sanitization story.
     */
    protected const INTEGER_TYPES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
        'int2', 'int4', 'int8',
    ];

    /**
     * How many chunk jobs are pushed to the queue per statement.
     */
    protected const DISPATCH_BATCH_SIZE = 500;

    public int $totalCount = 0;

    public int $jobCount = 0;

    /**
     * The single column integer primary key used for keyset chunking, or null
     * when the table is not eligible and offset chunking is used instead.
     */
    protected ?string $keyColumn = null;

    /**
     * The base table's primary key columns. Used to order the offset chunking
     * fallback. Empty when the table has no primary key.
     */
    protected array $primaryKeyColumns = [];

    /**
     * Precomputed keyset chunk boundaries, or null when the table falls back
     * to offset chunking. Computed up front because the command needs
     * jobCount before it dispatches anything.
     *
     * @var array<int, array{0: int, 1: int|null}>|null
     */
    protected ?array $boundaries = null;

    public function __construct(
        protected string $job_class,
        protected string $conn,
        protected string $table,
        protected Expression $table_expr,
        protected array $wheres,
        protected array $joins,
        protected int $chunk_size = 500,
        protected bool $sync = false,
        protected array $columns = [],
    ) {
        $this->chunk_size = max(1, $this->chunk_size);

        $this->primaryKeyColumns = $this->resolvePrimaryKeyColumns();
        $this->keyColumn = $this->resolveKeyColumn();

        $this->assertSelectedColumnsExist();

        // Keyset chunking gets its totals from the boundary query, which walks
        // the key column once and reports both the boundaries and the distinct
        // key count. Only the offset fallback needs a separate count.
        if ($this->keyColumn === null) {
            $count_query = DB::connection($this->conn)->table($this->table_expr);

            $this->applyConstraints($count_query);

            $this->totalCount = $count_query->count();
            $this->jobCount = $this->totalCount === 0
                ? 0
                : (int) ceil($this->totalCount / $this->chunk_size);

            return;
        }

        $this->boundaries = $this->computeChunkBoundaries();
        $this->jobCount = count($this->boundaries);
    }

    public function handle(): void
    {
        // ⚙️ Precompute aliased columns once per job
        $selectColumns = $this->getPrefixedColumns();

        $this->dispatchChunks(
            $this->keyColumn === null
                ? $this->offsetChunkQueries($selectColumns)
                : $this->keysetChunkQueries($selectColumns)
        );
    }

    /**
     * The chunk queries for a table with a usable key column.
     *
     * A generator so that a table split into tens of thousands of chunks never
     * holds every query builder in memory at once.
     *
     * @return \Generator<int, Builder>
     */
    protected function keysetChunkQueries(array $selectColumns): \Generator
    {
        $qualifiedKey = $this->qualifyKeyColumn($this->keyColumn);

        foreach ($this->boundaries ?? [] as [$lowerBound, $upperBound]) {
            $query = DB::connection($this->conn)
                ->table($this->table_expr)
                ->select($selectColumns);

            $this->applyConstraints($query);

            // Raw predicates with cast integers. dispatchChunks() ships
            // $query->toSql(), which discards bindings entirely, so a bound
            // where() would silently lose its value.
            $query->whereRaw($qualifiedKey.' >= '.(int) $lowerBound);

            if ($upperBound !== null) {
                $query->whereRaw($qualifiedKey.' < '.(int) $upperBound);
            }

            // orderByRaw, not orderBy, so the grammar does not re-split the
            // already quoted qualified identifier on its dots.
            $query->orderByRaw($qualifiedKey.' asc');

            yield $query;
        }
    }

    /**
     * Fallback for tables without a single column integer primary key.
     *
     * Offset chunking is retained, but ordered by the primary key so that the
     * sequence of rows is stable across the separate per chunk queries. A
     * table with no primary key at all has nothing deterministic to order by
     * and keeps the previous behaviour, having warned in the constructor.
     */
    protected function offsetChunkQueries(array $selectColumns): \Generator
    {
        $orderBy = $this->getFallbackOrderBy();

        for ($i = 0; $i < $this->jobCount; $i++) {
            $offset = $i * $this->chunk_size;

            $query = DB::connection($this->conn)
                ->table($this->table_expr)
                ->skip($offset)
                ->take($this->chunk_size)
                ->select($selectColumns);

            $this->applyConstraints($query);

            if ($orderBy !== null) {
                $query->orderByRaw($orderBy);
            }

            yield $query;
        }
    }

    /**
     * Push the chunk jobs onto the queue.
     *
     * Queued jobs go out through the driver's bulk API, which is one multi-row
     * insert for the database driver and one pipelined transaction for Redis,
     * rather than a round trip per chunk. Pushes are capped at
     * DISPATCH_BATCH_SIZE so a table split into tens of thousands of chunks
     * does not build one enormous statement.
     *
     * @param  iterable<Builder>  $queries
     */
    protected function dispatchChunks(iterable $queries): void
    {
        $pending = [];

        foreach ($queries as $query) {
            $migration_job = (new $this->job_class)
                ->setQuery($query->toSql())
                ->setConnection($this->conn)
                ->setTable($this->table);

            if ($this->sync) {
                dispatch_sync($migration_job);

                continue;
            }

            $pending[] = $migration_job;

            if (count($pending) >= self::DISPATCH_BATCH_SIZE) {
                $this->pushChunks($pending);
                $pending = [];
            }
        }

        if ($pending !== []) {
            $this->pushChunks($pending);
        }
    }

    /**
     * @param  array<int, object>  $jobs
     */
    protected function pushChunks(array $jobs): void
    {
        Queue::connection(config('laravel-migration.queue_connection'))
            ->bulk($jobs, '', config('laravel-migration.queue_name'));
    }

    /**
     * Compute the keyset chunk boundaries in a single indexed pass over the
     * key column.
     *
     * The innermost query selects DISTINCT keys rather than joined rows. A one
     * to many join fans out rows, and numbering the joined rows would let a
     * boundary land mid key, so both adjacent range chunks would match that
     * key. Numbering distinct keys makes every boundary key aligned, and each
     * source key therefore falls in exactly one chunk. The trade off is that a
     * fanning join can produce a chunk carrying more than chunk_size rows.
     *
     * The same pass also reports the total number of distinct keys via
     * COUNT(*) OVER (), so totalCount costs nothing beyond this query.
     *
     * @return array<int, array{0: int, 1: int|null}> [lowerBound, upperBound]
     *                                                pairs. The final upper
     *                                                bound is null, leaving
     *                                                the last chunk open ended.
     */
    protected function computeChunkBoundaries(): array
    {
        $qualifiedKey = $this->qualifyKeyColumn($this->keyColumn);

        $distinctKeys = DB::connection($this->conn)
            ->table($this->table_expr)
            ->distinct()
            ->select(new Expression($qualifiedKey.' as chunk_key'));

        $this->applyConstraints($distinctKeys);

        // Two nested subqueries rather than GROUP BY plus a window in one
        // level, which avoids any question about window versus group by
        // evaluation order across engines.
        $sql = sprintf(
            'select chunk_key, total_keys from ('
                .'select chunk_key,'
                .' row_number() over (order by chunk_key) as row_num,'
                .' count(*) over () as total_keys'
                .' from (%s) as distinct_keys'
            .') as ordered_keys where (row_num - 1) %% %d = 0 order by row_num',
            $distinctKeys->toSql(),
            $this->chunk_size
        );

        $rows = DB::connection($this->conn)->select($sql);

        $this->totalCount = $rows === [] ? 0 : (int) $rows[0]->total_keys;

        $keys = array_map(static fn (object $row): int => (int) $row->chunk_key, $rows);

        $boundaries = [];

        foreach ($keys as $index => $key) {
            $boundaries[] = [$key, $keys[$index + 1] ?? null];
        }

        return $boundaries;
    }

    /**
     * Resolve the column to chunk on. Keyset chunking only engages for a
     * single column integer primary key on an engine with window functions.
     */
    protected function resolveKeyColumn(): ?string
    {
        if (count($this->primaryKeyColumns) !== 1) {
            return null;
        }

        if (! $this->supportsWindowFunctions()) {
            return null;
        }

        $column = $this->primaryKeyColumns[0];

        $type = collect($this->sourceColumns($this->table))
            ->firstWhere('name', $column)['type_name'] ?? null;

        return in_array($type, self::INTEGER_TYPES, true) ? $column : null;
    }

    /**
     * The source table's columns, read once per table per cache lifetime.
     *
     * The constructor needs column types to pick a key column and handle()
     * needs column names to build the select, so both go through here rather
     * than issuing their own schema queries.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sourceColumns(string $table): array
    {
        return $this->rememberSchema($table, 'columns', fn (): array => DB::connection($this->conn)
            ->getSchemaBuilder()
            ->getColumns($table));
    }

    /**
     * The source table's indexes, read once per table per cache lifetime.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sourceIndexes(string $table): array
    {
        return $this->rememberSchema($table, 'indexes', fn (): array => DB::connection($this->conn)
            ->getSchemaBuilder()
            ->getIndexes($table));
    }

    /**
     * Cache one piece of schema for a source table.
     *
     * Source databases are being read out of, not written to, so the schema is
     * treated as fixed for the life of the cache. Clear the cache if the legacy
     * schema changes mid-project.
     *
     * @param  \Closure(): array<int, array<string, mixed>>  $callback
     * @return array<int, array<string, mixed>>
     */
    protected function rememberSchema(string $table, string $kind, \Closure $callback): array
    {
        $key = "laravel-migration:schema:{$this->conn}:{$table}:{$kind}";

        try {
            return cache()->rememberForever($key, $callback);
        } catch (\Throwable $e) {
            $this->warn("could not inspect {$kind}", $e);

            return [];
        }
    }

    /**
     * @return array<int, string> The base table's primary key columns.
     */
    protected function resolvePrimaryKeyColumns(): array
    {
        $primary = collect($this->sourceIndexes($this->table))
            ->first(fn (array $index): bool => ($index['primary'] ?? false) === true);

        if ($primary === null) {
            $this->warn('has no primary key, so its chunks cannot be ordered deterministically. Rows may be processed twice or skipped');

            return [];
        }

        return array_values($primary['columns']);
    }

    /**
     * The chunk boundary query uses ROW_NUMBER(), which needs MySQL 8.0+ or
     * MariaDB 10.2+. Every other engine Laravel supports has had window
     * functions for longer than Laravel has supported the engine.
     */
    protected function supportsWindowFunctions(): bool
    {
        $connection = DB::connection($this->conn);

        if ($connection->getDriverName() !== 'mysql') {
            return true;
        }

        try {
            $version = $connection->getServerVersion();
        } catch (\Throwable $e) {
            $this->warn('could not determine the server version', $e);

            return false;
        }

        // MariaDB reports either "10.11.2-MariaDB" or, behind the legacy
        // replication handshake, "5.5.5-10.11.2-MariaDB".
        $isMariaDb = str_contains(strtolower($version), 'mariadb');
        $version = preg_replace('/^5\.5\.5-/', '', $version);

        if (! preg_match('/^\d+\.\d+(\.\d+)?/', $version, $matches)) {
            return false;
        }

        return version_compare($matches[0], $isMariaDb ? '10.2' : '8.0', '>=');
    }

    /**
     * Qualify a column against the base table.
     */
    protected function qualifyKeyColumn(string $column): string
    {
        return self::qualifyColumn($this->table, $column);
    }

    /**
     * Qualify a column against a table.
     *
     * This mirrors MigrationCommand::getTableNameExpression() by backticking
     * the whole table name — which is MySQL/MariaDB specific — so that a table
     * name containing a dot stays a single identifier instead of being split
     * into schema and table by the query grammar.
     */
    protected static function qualifyColumn(string $table, string $column): string
    {
        return sprintf('`%s`.`%s`', $table, $column);
    }

    protected function getFallbackOrderBy(): ?string
    {
        if ($this->primaryKeyColumns === []) {
            return null;
        }

        return collect($this->primaryKeyColumns)
            ->map(fn (string $column): string => $this->qualifyKeyColumn($column).' asc')
            ->implode(', ');
    }

    /**
     * Apply the configured wheres and joins to a query.
     */
    protected function applyConstraints(Builder $query): void
    {
        foreach ($this->wheres as $where) {
            $query->whereRaw($where);
        }

        // Only table, first and second are required; the config documents
        // operator and type as optional.
        foreach ($this->joins as $join) {
            $query->join(
                $join['table'],
                $join['first'],
                $join['operator'] ?? '=',
                $join['second'],
                $join['type'] ?? 'inner'
            );
        }
    }

    protected function warn(string $message, ?\Throwable $e = null): void
    {
        Log::warning(sprintf(
            'laravel-migration: table "%s" on connection "%s" %s.%s',
            $this->table,
            $this->conn,
            $message,
            $e === null ? '' : ' '.$e->getMessage()
        ));
    }

    /**
     * Build the chunk query's select list, each column aliased with the
     * initials of its table name so that same-named columns on joined tables
     * do not collide (USERS.FNAME becomes U_FNAME).
     *
     * Returned as expressions because the identifiers are already quoted; a
     * plain string would be re-split on its dots by the grammar.
     *
     * @return array<int, Expression>
     */
    protected function getPrefixedColumns(): array
    {
        $columns = [];

        foreach ($this->selectedColumns() as [$table, $column]) {
            $columns[] = new Expression(sprintf(
                '%s as `%s_%s`',
                self::qualifyColumn($table, $column),
                $this->aliasPrefix($table),
                $column
            ));
        }

        return $columns;
    }

    /**
     * The [table, column] pairs the chunk query selects.
     *
     * With no configured projection this is every column of every table
     * involved, which is what the package has always done. A configured
     * projection narrows it to the listed columns plus the base table's
     * primary key, which the chunk query orders by and which migration jobs
     * almost always need.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function selectedColumns(): array
    {
        if ($this->columns === []) {
            $all = [];

            foreach ($this->selectableTables() as $table) {
                foreach (array_column($this->sourceColumns($table), 'name') as $column) {
                    $all[] = [$table, $column];
                }
            }

            return $all;
        }

        $selected = [];

        foreach ($this->primaryKeyColumns as $column) {
            $selected["{$this->table}.{$column}"] = [$this->table, $column];
        }

        foreach ($this->columns as $specification) {
            [$table, $column] = $this->resolveColumn($specification);
            $selected["{$table}.{$column}"] = [$table, $column];
        }

        return array_values($selected);
    }

    /**
     * Fail at dispatch time, in the command, rather than once per chunk job on
     * a worker where the only symptom is a column-not-found SQL error.
     */
    protected function assertSelectedColumnsExist(): void
    {
        $unknown = [];

        foreach ($this->columns as $specification) {
            [$table, $column] = $this->resolveColumn($specification);

            if (! in_array($column, array_column($this->sourceColumns($table), 'name'), true)) {
                $unknown[] = $specification;
            }
        }

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Table "%s" is configured to migrate columns that do not exist on connection "%s": %s.',
                $this->table,
                $this->conn,
                implode(', ', $unknown)
            ));
        }
    }

    /**
     * Split a configured column into its table and column.
     *
     * Table names may themselves contain a dot, so the known table names are
     * matched longest-first rather than splitting on the first dot. An
     * unqualified name belongs to the base table.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveColumn(string $specification): array
    {
        $tables = $this->selectableTables();

        usort($tables, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($tables as $table) {
            if (str_starts_with($specification, $table.'.')) {
                return [$table, substr($specification, strlen($table) + 1)];
            }
        }

        return [$this->table, $specification];
    }

    /**
     * The base table plus every joined table.
     *
     * @return array<int, string>
     */
    protected function selectableTables(): array
    {
        return array_merge([$this->table], array_column($this->joins, 'table'));
    }

    /**
     * The alias prefix for a table: the initials of its underscore-separated
     * parts, so USER_COMPANY becomes U_C.
     */
    protected function aliasPrefix(string $table): string
    {
        return collect(explode('_', $table))
            ->map(fn (string $part): string => substr($part, 0, 1))
            ->implode('_');
    }
}
