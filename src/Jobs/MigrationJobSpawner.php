<?php

namespace PaperleafTech\LaravelMigration\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class MigrationJobSpawner implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use Batchable;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        protected string $job_class,
        protected string $conn,
        protected string $table,
        protected Expression $table_expr,
        protected bool $sync = false,
        protected array $wheres,
        protected int $chunk_size = 500
    ) {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $count_query = DB::connection($this->conn)
            ->table($this->table_expr);

        if (! empty($this->wheres)) {
            foreach ($this->wheres as $where) {
                $count_query->whereRaw($where);
            }
        }

        $count = $count_query->count();

        $iterations = (int) ceil($count / $this->chunk_size);

        for ($i = 0; $i < $iterations; $i++) {
            $offset = $i * $this->chunk_size;
            // $i = 0, $offset = 0
            // $i = 1, $offset = 500
            // $i = 2, $offset = 1000
            // ...

            $query = DB::connection($this->conn)
                ->table($this->table_expr)
                ->skip($offset)
                ->take($this->chunk_size);

            if (! empty($this->wheres)) {
                foreach ($this->wheres as $where) {
                    $count_query->whereRaw($where);
                }
            }

            $migration_job = (new $this->job_class())
                ->setQuery($query->toSql())
                ->setConnection($this->conn)
                ->setTable($this->table);

            if ($this->sync) {
                dispatch_sync($migration_job);
            } else {
                dispatch($migration_job)
                    ->onQueue(config('laravel-migration.queue'));
                ;
            }
        }
    }
}
