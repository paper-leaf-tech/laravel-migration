<?php

namespace PaperleafTech\LaravelMigration\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Helper\ProgressBar;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;

class MigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example for running on local:
     *
     * php artisan migration:run --all
     * php artisan migration:run TABLENAME
     *
     * @var string
     */
    protected $signature = 'migration:run 
        {table? : Migrate a single table see config/laravel-migration.php table_job_mapping}
        {--A|all : Migrate all tables}
        {--group= : Group index (start from 0) to start the migrate all tables on}';

    protected $connection;
    protected $chunkSize;
    protected $mapping;
    protected $dependancyMapping;
    protected $afterJobs;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrates the old database records into the new database.';

    public function __construct()
    {
        parent::__construct();

        $this->connection        = config('laravel-migration.database_connection');
        $this->chunkSize         = config('laravel-migration.default_chunk_size');
        $this->mapping           = config('laravel-migration.table_job_mapping');
        $this->dependancyMapping = config('laravel-migration.table_dependency_groups');
        $this->afterJobs         = config('laravel-migration.after_jobs', []);
    }

    public function verifyEnvironment(): bool
    {
        $queue = config('laravel-migration.queue_connection');

        // Check if the migration connection is set, and valid.
        $connection = config('database.connections.'. $this->connection);
        if (! $connection) {
            $this->error('The migration source and destination are not set or valid in your config/laravel-migration.php file.');
            return false;
        }

        if ( $queue === 'database' && ! Schema::hasTable('jobs') ) {
            $this->error('The queue tables (jobs and job_batches) are not present in your database. Install them before running a migration');
            return false;
        }

        // Check that mapping classes exist.
        foreach ($this->mapping as $table => $job) {
            if (is_string($job) && class_exists($job)) {
                continue;
            }
            if (is_array($job) && isset($job['job']) && class_exists($job['job'])) {
                continue;
            }
            $this->error("The migration job for table '{$table}' is not a valid class.");
            return false;
        }

        return true;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->verifyEnvironment()) {
            return Command::FAILURE;
        };

        $opts = $this->options();
        $args = $this->arguments();

        $table = Arr::get($args, 'table', null);
        $all   = (bool) Arr::get($opts, 'all', false);
        $group = (int) Arr::get($opts, 'group', 0);

        if ($table === null && $all === false) {
            $this->error('Specify a table or choose to migrate all tables.');
            return Command::FAILURE;
        }

        if (! $this->checkForLogging()) {
            return Command::FAILURE;
        }

        // Migrate a single table.
        if (! empty($table)) {
            $this->migrateTable($table, sync: true);
        }
        // Migrate all tables.
        else {
            $this->migrateAllTables($group);
        }

        return Command::SUCCESS;
    }

    public function checkForLogging(): bool
    {
        if (! class_exists('Laravel\Telescope\TelescopeServiceProvider')) {
            return true;
        }

        $telescope_paused = Cache::get('telescope:pause-recording');
        if ($telescope_paused !== true && ! $this->confirm('Telescope is currently not paused. You can pause Telescope via the /telescope page. Continue?')) {
            return false;
        }

        return true;
    }

    public function migrateJobGroup(array $group, ProgressBar $progressBar)
    {
        $jobs = [];
        $jobCount = 0;
        foreach ($group as $table) {
            $this->info('Migrating table: '. $table);

            $migrationItem = self::getMigrationItem($this->mapping, $table);

            if (! $migrationItem) {
                $this->error('Invalid migration job. Check if '. $table .' has valid mapping entry.');
                continue;
            }

            $spawnerJobInstance = new MigrationJobSpawner(
                $migrationItem['job'],
                $this->connection,
                $table,
                self::getTableNameExpression($table),
                false,
                $migrationItem['wheres'],
                $migrationItem['chunk_size']
            );

            $jobs[]    = $spawnerJobInstance;
            $jobCount += (int) ceil(
                DB::connection($this->connection)->table($table)->count() / 500
            );  // The number of final jobs
        }

        foreach ( $jobs as $job ) {
            dispatch($job)
                ->onQueue(config('laravel-migration.queue'));
        }

        $this->waitForEmptyQueue($progressBar, $jobCount);

        $this->line("\n");
    }

    public function runAfterJobs(array $jobs, ProgressBar $progressBar)
    {
        $jobCount = count($jobs);

        foreach ($jobs as $job) {
            $this->info('Running after job: '. get_class($job));

            dispatch($job)
                ->onQueue(config('laravel-migration.queue'));
        }

        $this->waitForEmptyQueue($progressBar, $jobCount);

        return;
    }

    public function waitForEmptyQueue(ProgressBar $progressBar, int $jobCount): void
    {
        $progressBar->start($jobCount);

        $lastQueueCount = 0;

        $queueCount = $this->getQueueCount();

        // Wait for the job queue to be empty before running the next task.
        while ($queueCount !== 0) {
            if ($queueCount < $lastQueueCount) {
                // Job count decrementing, can start counting completions.
                $progressBar->advance(abs($queueCount - $lastQueueCount));
            }
            $lastQueueCount = $queueCount;
            sleep(1);

            $queueCount = $this->getQueueCount();
        }

        $progressBar->finish();

        return;
    }

    private function getQueueCount(): int
    {
        $queue = config('laravel-migration.queue_connection');
        $count = 0;

        switch ($queue) {
            case 'database':
                $count = DB::table('jobs')->count();
                break;
                
            case 'redis':
                $redis = Redis::connection();
                $queueName = config('queue.connections.redis.queue', 'default');
                $queueKey = "queues:$queueName";
                $count = $redis->llen($queueKey);
                break;
                
            default:
                throw new \RuntimeException("Unsupported queue connection: {$queue}");
        }

        return $count;
    }

    public function migrateAllTables(int $start_group = 0): void
    {
        $progressBar = $this->output->createProgressBar(1);
        foreach ($this->dependancyMapping as $index => $group) {
            if ($start_group > $index) {
                continue; // Skip groups until we reach the current group.
            }

            $this->alert("Dispatching job group " . $index);

            $this->migrateJobGroup($group, $progressBar);
        }

        if ( ! empty($this->afterJobs) ) {
            foreach ($this->afterJobs as $jobClass) {
                dispatch(new $jobClass)->onQueue(config('laravel-migration.queue'));
            }
            $this->alert('After jobs dispatched.');
        }

        $this->alert('Migration completed.');
    }

    public function migrateTable(string $table, bool $sync = false): void
    {
        $migrationItem = self::getMigrationItem($this->mapping, $table);

        if (! array_key_exists($table, $this->mapping) || null === $migrationItem) {
            $this->error('The table ' . $table . ' does not exist in our job mapping, or the migration job does not exist.');
            return;
        }

        if ($sync) {
            $this->info('Migrating table: '. $table);

            dispatch_sync(new MigrationJobSpawner(
                $migrationItem['job'],
                $this->connection,
                $table,
                self::getTableNameExpression($table),
                $sync,
                $migrationItem['wheres'],
                $migrationItem['chunk_size']
            ));

            $this->alert('Migrated table: '. $table. '.');

            return;
        }

        $group = [$table];
        $progressBar = $this->output->createProgressBar(1);

        $this->migrateJobGroup($group, $progressBar);

        $this->alert('Migrated table: '. $table. '.');
    }

    /**
     * This is a workaround for having a dot in the table name.
     */
    public static function getTableNameExpression(string $table): Expression
    {
        return new Expression(sprintf('`%s`', $table));
    }

    public static function getMigrationItem(string|array $mapping, string $table): ?array
    {
        $default_chunk_size = config('laravel-migration.default_chunk_size');
        $migrationItem      = $mapping[$table] ?? null;

        if (is_null($migrationItem)) {
            return null;
        }

        if (is_string($migrationItem)) {
            if (! class_exists($migrationItem)) {
                return null;
            }
            return [
                'job'        => $migrationItem,
                'wheres'     => [],
                'chunk_size' => $default_chunk_size,
            ];
        }

        if (! class_exists($migrationItem['job'])) {
            return null;
        }

        return [
            'job'        => $migrationItem['job'],
            'wheres'     => $migrationItem['wheres'] ?? [],
            'chunk_size' => $migrationItem['chunk_size'] ?? $default_chunk_size,
        ];
    }
}
