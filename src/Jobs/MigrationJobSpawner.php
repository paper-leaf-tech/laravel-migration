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

    public function __construct(
        protected string $job_class,
        protected string $conn,
        protected string $table,
        protected Expression $table_expr,
        protected array $wheres,
        protected array $joins,
        protected int $chunk_size = 500,
        protected bool $sync = false,
    ) {
        $this->chunk_size = max(1, $this->chunk_size);

        $this->primaryKeyColumns = $this->resolvePrimaryKeyColumns();
        $this->keyColumn = $this->resolveKeyColumn();

        $count_query = DB::connection($this->conn)
            ->table($this->table_expr);

        $this->applyConstraints($count_query);

        // Keyset chunking splits on distinct keys rather than joined rows, so
        // the count that drives jobCount has to use the same unit.
        $this->totalCount = $this->keyColumn === null
            ? $count_query->count()
            : $count_query->distinct()->count(new Expression($this->qualifyKeyColumn($this->keyColumn)));

        $this->jobCount = 0;

        if ($this->totalCount !== 0) {
            $this->jobCount = (int) ceil($this->totalCount / $this->chunk_size);
        }
    }

    public function handle(): void
    {
        // ⚙️ Precompute aliased columns once per job
        $selectColumns = $this->getPrefixedColumns();

        if ($this->keyColumn === null) {
            $this->dispatchOffsetChunks($selectColumns);

            return;
        }

        $qualifiedKey = $this->qualifyKeyColumn($this->keyColumn);

        foreach ($this->getChunkBoundaries() as [$lowerBound, $upperBound]) {
            $query = DB::connection($this->conn)
                ->table($this->table_expr)
                ->select($selectColumns);

            $this->applyConstraints($query);

            // Raw predicates with cast integers. dispatchChunk() ships
            // $query->toSql(), which discards bindings entirely, so a bound
            // where() would silently lose its value.
            $query->whereRaw($qualifiedKey.' >= '.(int) $lowerBound);

            if ($upperBound !== null) {
                $query->whereRaw($qualifiedKey.' < '.(int) $upperBound);
            }

            // orderByRaw, not orderBy, so the grammar does not re-split the
            // already quoted qualified identifier on its dots.
            $query->orderByRaw($qualifiedKey.' asc');

            $this->dispatchChunk($query);
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
    protected function dispatchOffsetChunks(array $selectColumns): void
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

            $this->dispatchChunk($query);
        }
    }

    protected function dispatchChunk(Builder $query): void
    {
        $migration_job = (new $this->job_class)
            ->setQuery($query->toSql())
            ->setConnection($this->conn)
            ->setTable($this->table);

        if ($this->sync) {
            dispatch_sync($migration_job);

            return;
        }

        dispatch($migration_job)
            ->onConnection(config('laravel-migration.queue_connection'))
            ->onQueue(config('laravel-migration.queue_name'));
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
     * @return array<int, array{0: int, 1: int|null}> [lowerBound, upperBound]
     *                                                pairs. The final upper
     *                                                bound is null, leaving
     *                                                the last chunk open ended.
     */
    protected function getChunkBoundaries(): array
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
            'select chunk_key from ('
                .'select chunk_key, row_number() over (order by chunk_key) as row_num'
                .' from (%s) as distinct_keys'
            .') as ordered_keys where (row_num - 1) %% %d = 0 order by row_num',
            $distinctKeys->toSql(),
            $this->chunk_size
        );

        $keys = array_map(
            static fn (object $row): int => (int) $row->chunk_key,
            DB::connection($this->conn)->select($sql)
        );

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

        try {
            $type = collect(DB::connection($this->conn)->getSchemaBuilder()->getColumns($this->table))
                ->firstWhere('name', $column)['type_name'] ?? null;
        } catch (\Throwable $e) {
            $this->warn('could not inspect columns', $e);

            return null;
        }

        return in_array($type, self::INTEGER_TYPES, true) ? $column : null;
    }

    /**
     * @return array<int, string> The base table's primary key columns.
     */
    protected function resolvePrimaryKeyColumns(): array
    {
        try {
            $primary = collect(DB::connection($this->conn)->getSchemaBuilder()->getIndexes($this->table))
                ->first(fn (array $index): bool => ($index['primary'] ?? false) === true);
        } catch (\Throwable $e) {
            $this->warn('could not inspect indexes', $e);

            return [];
        }

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
     *
     * This mirrors MigrationCommand::getTableNameExpression() by backticking
     * the whole table name, which is MySQL/MariaDB specific.
     */
    protected function qualifyKeyColumn(string $column): string
    {
        return sprintf('`%s`.`%s`', $this->table, $column);
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

        foreach ($this->joins as $join) {
            $query->join($join['table'], $join['first'], $join['operator'], $join['second'], $join['type']);
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
     * Build a list of all columns from the base table and joined tables,
     * each prefixed with its table name.
     */
    protected function getPrefixedColumns(): array
    {
        $tables = [$this->table];

        foreach ($this->joins as $join) {
            $tables[] = $join['table'];
        }

        $schema = DB::connection($this->conn)->getSchemaBuilder();
        $columns = [];

        foreach ($tables as $table) {
            $cols = cache()->rememberForever("columns_{$this->conn}_{$table}", function () use ($schema, $table) {
                return $schema->getColumnListing($table);
            });

            $prefix = collect(explode('_', $table))
                ->map(fn ($part) => substr($part, 0, 1))
                ->implode('_');

            foreach ($cols as $col) {
                $columns[] = "{$table}.{$col} AS {$prefix}_{$col}";
            }
        }

        return $columns;
    }
}
