<?php

namespace PaperleafTech\LaravelMigration\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Query\Expression;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class MigrationJobSpawner implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(
        protected string $job_class,
        protected string $conn,
        protected string $table,
        protected Expression $table_expr,
        protected array $exclude_wheres,
        protected array $joins,
        protected int $chunk_size = 500,
        protected bool $sync = false,
    ) {}

    public function handle(): void
    {
        $count_query = DB::connection($this->conn)
            ->table($this->table_expr);

        // Apply wheres
        foreach ($this->exclude_wheres as $where) {
            $count_query->whereRaw($where);
        }

        // Apply joins
        foreach ($this->joins as $join) {
            $count_query->join($join['table'], $join['first'], $join['operator'], $join['second'], $join['type']);
        }

        $count = $count_query->count();
        $iterations = (int) ceil($count / $this->chunk_size);

        // ⚙️ Precompute aliased columns once per job
        $selectColumns = $this->getPrefixedColumns();

        for ($i = 0; $i < $iterations; $i++) {
            $offset = $i * $this->chunk_size;

            $query = DB::connection($this->conn)
                ->table($this->table_expr)
                ->skip($offset)
                ->take($this->chunk_size)
                ->select($selectColumns);

            foreach ($this->exclude_wheres as $where) {
                $query->whereRaw($where);
            }

            foreach ($this->joins as $join) {
                $query->join($join['table'], $join['first'], $join['operator'], $join['second'], $join['type']);
            }

            $migration_job = (new $this->job_class())
                ->setQuery($query->toSql())
                ->setConnection($this->conn)
                ->setTable($this->table);

            if ($this->sync) {
                dispatch_sync($migration_job);
            } else {
                dispatch($migration_job)
                    ->onConnection(config('laravel-migration.queue_connection'))
                    ->onQueue(config('laravel-migration.queue_name'));
            }
        }
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
                ->map(fn($part) => substr($part, 0, 1))
                ->implode('_');

            foreach ($cols as $col) {
                $columns[] = "{$table}.{$col} AS {$prefix}_{$col}";
            }
        }

        return $columns;
    }
}