<?php

namespace PaperleafTech\LaravelMigration\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
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
        {--group= : Group index (start from 0) to start the migrate all tables on}
        {--chunk-size= : Override the configured chunk size for this run}
        {--queue : Push a single table onto the queue instead of running it in this process}
        {--sync : Run every table in this process, with no queue worker}
        {--no-wait : Dispatch the jobs and exit without waiting for the queue to drain}
        {--dry-run : Report what would be dispatched without dispatching anything}';

    protected $queueName;
    protected $queueConnection;
    protected $connection;
    protected $chunkSize;
    protected $mapping;
    protected $dependancyMapping;
    protected $afterJobs;

    /**
     * Run everything in this process rather than on the queue.
     */
    protected bool $runSynchronously = false;

    /**
     * Dispatch and return without waiting for the queue to drain.
     */
    protected bool $skipWaiting = false;

    /**
     * Report what would be dispatched without dispatching it.
     */
    protected bool $dryRun = false;

    /**
     * Collected [table, rows, jobs] rows for the dry-run report.
     *
     * @var array<int, array{0: string, 1: int, 2: int}>
     */
    protected array $plan = [];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrates the old database records into the new database.';

    /**
     * The queue drivers getQueueCount() knows how to poll.
     */
    protected const SUPPORTED_QUEUE_CONNECTIONS = ['database', 'redis'];

    /**
     * Longest gap between queue polls. The wait backs off towards this while
     * the queue is not moving and drops back to one second as soon as it is,
     * so a migration that runs for hours does not spend it counting rows.
     */
    protected const MAX_POLL_SECONDS = 10;

    public function __construct()
    {
        parent::__construct();

        $this->queueName         = config('laravel-migration.queue_name');
        $this->queueConnection   = config('laravel-migration.queue_connection');
        $this->connection        = config('laravel-migration.database_connection');
        $this->chunkSize         = config('laravel-migration.default_chunk_size');
        $this->mapping           = config('laravel-migration.table_job_mapping');
        $this->dependancyMapping = config('laravel-migration.table_dependency_groups');
        $this->afterJobs         = config('laravel-migration.after_jobs', []);
    }

    public function verifyEnvironment(): bool
    {
        // Check if the migration connection is set, and valid.
        $connection = config('database.connections.'. $this->connection);
        if (! $connection) {
            $this->error('The migration source and destination are not set or valid in your config/laravel-migration.php file.');
            return false;
        }

        if (! in_array($this->queueConnection, self::SUPPORTED_QUEUE_CONNECTIONS, true)) {
            $this->error(sprintf(
                'Unsupported queue connection "%s". laravel-migration can only track progress on a %s queue.',
                $this->queueConnection,
                implode(' or ', self::SUPPORTED_QUEUE_CONNECTIONS)
            ));
            return false;
        }

        if ( $this->queueConnection === 'database' && ! Schema::connection($this->queueDatabase())->hasTable($this->queueTable()) ) {
            $this->error(sprintf(
                'The queue table "%s" is not present on the "%s" database connection. Install it before running a migration.',
                $this->queueTable(),
                $this->queueDatabase() ?? config('database.default')
            ));
            return false;
        }

        if ( $this->queueConnection ==='redis') {
            $ping = $this->redisQueueConnection()->ping();
            // phpredis
            if ( $ping instanceof bool ) {
                if ( $ping !== true ) {
                    $this->error('Redis server is not reachable or not running.');
                    return false;
                }
            }
            // predis
            else {
                if ( false === class_exists(\Predis\Response\Status::class)
                    || ($ping instanceof \Predis\Response\Status) && $ping->getPayload() !== 'PONG' ) {
                    $this->error('Redis server is not reachable or not running.');
                    return false;
                }
            }
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
        // No time limit for this command.
        set_time_limit(0);

        if (! $this->applyChunkSizeOverride()) {
            return Command::FAILURE;
        }

        if (! $this->verifyEnvironment()) {
            return Command::FAILURE;
        };

        $this->runSynchronously = (bool) $this->option('sync');
        $this->skipWaiting      = (bool) $this->option('no-wait');
        $this->dryRun           = (bool) $this->option('dry-run');

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

        // Migrate a single table. Synchronous unless --queue is given, which
        // keeps the historical behaviour of `migration:run TABLE`.
        if (! empty($table)) {
            $succeeded = $this->migrateTable($table, sync: ! $this->option('queue'));
        }
        // Migrate all tables.
        else {
            $succeeded = $this->migrateAllTables($group);
        }

        return $succeeded ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Apply --chunk-size, which overrides both the configured default and any
     * per-table chunk size for this run.
     */
    protected function applyChunkSizeOverride(): bool
    {
        $override = $this->option('chunk-size');

        if ($override === null) {
            return true;
        }

        if (! ctype_digit((string) $override) || (int) $override < 1) {
            $this->error('The chunk size must be a positive integer.');

            return false;
        }

        $this->chunkSize = (int) $override;

        return true;
    }

    public function checkForLogging(): bool
    {
        if (! class_exists('Laravel\Telescope\TelescopeServiceProvider')) {
            return true;
        }

        $telescope_paused = Cache::get('telescope:pause-recording');
        if ($telescope_paused !== true) {
            Cache::set('telescope:pause-recording', true);
            $this->warn('Telescope recording has been paused to avoid logging migration queries.');
        }

        return true;
    }

    /**
     * @return bool Whether every table in the group was dispatched.
     */
    public function migrateJobGroup(array $group, ProgressBar $progressBar): bool
    {
        $jobs = [];
        $jobCount = 0;
        $succeeded = true;

        foreach ($group as $table) {
            $migrationItem = self::getMigrationItem($this->mapping, $table, $this->chunkSizeOverride());

            if (! $migrationItem) {
                $this->error('Invalid migration job. Check if '. $table .' has valid mapping entry.');
                $succeeded = false;
                continue;
            }

            $spawnerJobInstance = $this->makeSpawner($migrationItem, $table, $this->runSynchronously);

            if ($spawnerJobInstance === null) {
                $succeeded = false;
                continue;
            }

            $jobs[$table] = $spawnerJobInstance;
            $jobCount    += $spawnerJobInstance->jobCount;
        }

        // Chunks are planned and pushed here in the command process rather
        // than from a queued spawner job. A spawner big enough to matter
        // outruns the worker's timeout and the connection's retry_after, and a
        // released spawner re-dispatches chunks it already dispatched.
        foreach ($jobs as $table => $job) {
            $this->plan[] = [$table, $job->totalCount, $job->jobCount];

            if ($this->dryRun) {
                continue;
            }

            $this->info(sprintf(
                'Migrating table: %s (%s rows, %s jobs)',
                $table,
                number_format($job->totalCount),
                number_format($job->jobCount)
            ));

            $job->handle();
        }

        if ($this->dryRun) {
            return $succeeded;
        }

        if ($this->shouldWait()) {
            $succeeded = $this->awaitQueue($progressBar, $jobCount) && $succeeded;
        }

        $this->line("\n");

        return $succeeded;
    }

    /**
     * Whether a dispatched group should be waited on.
     */
    protected function shouldWait(): bool
    {
        return ! $this->runSynchronously && ! $this->skipWaiting;
    }

    protected function chunkSizeOverride(): ?int
    {
        return $this->option('chunk-size') === null ? null : $this->chunkSize;
    }

    public function runAfterJobs(ProgressBar $progressBar)
    {
        $jobs     = $this->afterJobs;
        $jobCount = count($jobs);

        $dispatched = 0;

        foreach ($jobs as $job) {
            if ( ! class_exists( $job ) ) continue;
            $this->info('Running after job: '. $job);

            if ($this->runSynchronously) {
                dispatch_sync(new $job);
                continue;
            }

            dispatch(new $job)
                ->onConnection($this->queueConnection)
                ->onQueue($this->queueName);

            $dispatched++;
        }

        if ($this->shouldWait() && $dispatched > 0) {
            $this->awaitQueue($progressBar, $dispatched);
        }

        return;
    }

    /**
     * Wait for the migration queue to drain, then report anything that failed.
     *
     * @return bool Whether the queue drained without any job failing.
     */
    public function awaitQueue(ProgressBar $progressBar, int $jobCount): bool
    {
        $failedBefore = $this->failedJobCount();

        $progressBar->setFormat(
            '  %current%/%max% [%bar%] %percent:3s%%  elapsed %elapsed:6s%  eta %estimated:-6s%  %memory:6s%'
        );
        $progressBar->start($jobCount);

        $remaining = $this->getQueueCount();
        $wait      = 1;

        while ($remaining > 0) {
            $progressBar->setProgress(max(0, $jobCount - $remaining));

            sleep($wait);

            $previous  = $remaining;
            $remaining = $this->getQueueCount();

            // Responsive while the queue is moving, quiet while it is not.
            $wait = $remaining === $previous
                ? min(self::MAX_POLL_SECONDS, $wait * 2)
                : 1;
        }

        $progressBar->setProgress($jobCount);
        $progressBar->finish();

        return $this->reportFailures($failedBefore);
    }

    /**
     * Report jobs that landed in failed_jobs while this group was running.
     *
     * Without this a migration can lose thousands of chunks and still print
     * "Migration completed." — the queue is empty either way.
     *
     * @return bool Whether nothing new failed.
     */
    protected function reportFailures(int $failedBefore): bool
    {
        $failed = $this->failedJobCount() - $failedBefore;

        if ($failed <= 0) {
            return true;
        }

        $this->newLine();
        $this->error(sprintf(
            '%s job(s) failed on the "%s" queue. Inspect them with `php artisan queue:failed`.',
            number_format($failed),
            $this->queueName
        ));

        return false;
    }

    /**
     * How many failed jobs are recorded for the migration queue.
     *
     * Returns zero when the application has no failed_jobs table, in which
     * case failures are invisible and the migration cannot report on them.
     */
    protected function failedJobCount(): int
    {
        $connection = config('queue.failed.database');
        $table      = config('queue.failed.table') ?? 'failed_jobs';

        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                return 0;
            }

            return DB::connection($connection)
                ->table($table)
                ->where('queue', $this->queueName)
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Count the jobs still outstanding on the migration queue.
     *
     * Scoped to the configured queue name so that unrelated application jobs
     * sharing the same connection cannot keep the wait loop spinning forever.
     */
    protected function getQueueCount(): int
    {
        switch ($this->queueConnection) {
            case 'database':
                return DB::connection($this->queueDatabase())
                    ->table($this->queueTable())
                    ->where('queue', $this->queueName)
                    ->count();

            case 'redis':
                $redis = $this->redisQueueConnection();

                // Reserved jobs are in flight and delayed jobs are waiting to
                // be retried; both still count as outstanding work.
                return $redis->llen("queues:{$this->queueName}")
                    + $redis->zcard("queues:{$this->queueName}:reserved")
                    + $redis->zcard("queues:{$this->queueName}:delayed");

            default:
                throw new \RuntimeException("Unsupported queue connection: {$this->queueConnection}");
        }
    }

    /**
     * The database connection the queue driver stores its jobs on, or null for
     * the application default.
     */
    protected function queueDatabase(): ?string
    {
        return config("queue.connections.{$this->queueConnection}.connection");
    }

    /**
     * The table the database queue driver stores its jobs in.
     */
    protected function queueTable(): string
    {
        return config("queue.connections.{$this->queueConnection}.table") ?? 'jobs';
    }

    /**
     * The Redis connection the queue driver is configured to use.
     */
    protected function redisQueueConnection()
    {
        return Redis::connection(
            config("queue.connections.{$this->queueConnection}.connection") ?? 'default'
        );
    }

    /**
     * @return bool Whether every dependency group was dispatched.
     */
    public function migrateAllTables(int $start_group = 0): bool
    {
        $succeeded = true;
        $progressBar = $this->output->createProgressBar(1);

        if (empty($this->dependancyMapping)) {
            $this->warn('No dependency groups are configured, so --all has nothing to migrate. Populate table_dependency_groups in config/laravel-migration.php.');
        }

        $this->warnAboutUngroupedTables();

        foreach ($this->dependancyMapping as $index => $group) {
            if ($start_group > $index) {
                continue; // Skip groups until we reach the current group.
            }

            $this->alert("Dispatching job group " . $index);

            $succeeded = $this->migrateJobGroup($group, $progressBar) && $succeeded;
        }

        if ($this->dryRun) {
            $this->reportPlan();

            return $succeeded;
        }

        if ( ! empty($this->afterJobs) ) {
            $this->alert('Dispatching after jobs');
            $this->runAfterJobs($progressBar);
        }

        $this->alert('Migration completed.');

        return $succeeded;
    }

    /**
     * Tables that have a job mapping but appear in no dependency group are
     * never touched by --all. That is almost always an oversight, and it is
     * otherwise completely silent.
     */
    protected function warnAboutUngroupedTables(): void
    {
        $grouped = collect($this->dependancyMapping)->flatten()->all();

        $ungrouped = array_values(array_diff(array_keys($this->mapping), $grouped));

        if ($ungrouped === []) {
            return;
        }

        $this->warn(sprintf(
            '%s mapped but not listed in any dependency group, so --all will skip %s: %s.',
            count($ungrouped) === 1 ? 'This table is' : 'These tables are',
            count($ungrouped) === 1 ? 'it' : 'them',
            implode(', ', $ungrouped)
        ));
    }

    /**
     * Render what a run would dispatch.
     */
    protected function reportPlan(): void
    {
        $this->newLine();

        if ($this->plan === []) {
            $this->warn('Nothing would be dispatched.');

            return;
        }

        $this->table(
            ['Table', 'Rows', 'Jobs'],
            array_map(
                fn (array $row): array => [$row[0], number_format($row[1]), number_format($row[2])],
                $this->plan
            )
        );

        $this->info(sprintf(
            'Dry run: %s rows across %s tables would be migrated in %s jobs. Nothing was dispatched.',
            number_format(array_sum(array_column($this->plan, 1))),
            number_format(count($this->plan)),
            number_format(array_sum(array_column($this->plan, 2)))
        ));
    }

    /**
     * @return bool Whether the table was migrated.
     */
    public function migrateTable(string $table, bool $sync = false): bool
    {
        $migrationItem = self::getMigrationItem($this->mapping, $table, $this->chunkSizeOverride());

        if (! array_key_exists($table, $this->mapping) || null === $migrationItem) {
            $this->error('The table ' . $table . ' does not exist in our job mapping, or the migration job does not exist.');
            return false;
        }

        if ($this->dryRun) {
            $spawner = $this->makeSpawner($migrationItem, $table, sync: true);

            if ($spawner === null) {
                return false;
            }

            $this->plan[] = [$table, $spawner->totalCount, $spawner->jobCount];
            $this->reportPlan();

            return true;
        }

        if ($sync) {
            $this->info('Migrating table: '. $table);

            $spawner = $this->makeSpawner($migrationItem, $table, sync: true);

            if ($spawner === null) {
                return false;
            }

            dispatch_sync($spawner);

            $this->alert('Migrated table: '. $table. '.');

            return true;
        }

        $group = [$table];
        $progressBar = $this->output->createProgressBar(1);

        $succeeded = $this->migrateJobGroup($group, $progressBar);

        $this->alert('Migrated table: '. $table. '.');

        return $succeeded;
    }

    /**
     * Build the spawner for one table, reporting a configuration problem as a
     * command error rather than letting it surface as an uncaught exception.
     *
     * @param  array<string, mixed>  $migrationItem
     */
    protected function makeSpawner(array $migrationItem, string $table, bool $sync): ?MigrationJobSpawner
    {
        try {
            return new MigrationJobSpawner(
                $migrationItem['job'],
                $this->connection,
                $table,
                self::getTableNameExpression($table),
                $migrationItem['wheres'],
                $migrationItem['joins'],
                $migrationItem['chunk_size'],
                $sync,
                $migrationItem['columns'],
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /**
     * This is a workaround for having a dot in the table name.
     */
    public static function getTableNameExpression(string $table): Expression
    {
        if ( strpos($table, '.') !== false ) {
            return new Expression(sprintf('`%s`', $table));
        }

        return new Expression($table);
    }

    public static function getMigrationItem(string|array $mapping, string $table, ?int $chunkSizeOverride = null): ?array
    {
        $chunkSize     = $chunkSizeOverride ?? config('laravel-migration.default_chunk_size');
        $migrationItem = $mapping[$table] ?? null;

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
                'joins'      => [],
                'columns'    => [],
                'chunk_size' => $chunkSize,
            ];
        }

        if (! class_exists($migrationItem['job'])) {
            return null;
        }

        return [
            'job'        => $migrationItem['job'],
            'wheres'     => $migrationItem['wheres'] ?? [],
            'joins'      => $migrationItem['joins'] ?? [],
            'columns'    => $migrationItem['columns'] ?? [],
            'chunk_size' => $chunkSizeOverride ?? $migrationItem['chunk_size'] ?? $chunkSize,
        ];
    }
}
