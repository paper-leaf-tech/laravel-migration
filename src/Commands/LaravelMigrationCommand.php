<?php

namespace App\Console\Commands;

use App\Jobs\Migration\GenericJobSpawner;
use App\Models\MigrationMapping;
use Illuminate\Console\Command;
use Database\Seeders\Migration\MigrationSeeder;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LaravelMigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
	 *
	 * Example for running on local:
	 *
	 * php artisan nisis:migrate_db --all --sync
	 * php artisan queue:work --queue=migration
     *
     * @var string
     */
    protected $signature = 'paperleaf:migrate_db 
        {table? : Migrate a single table see MigrationSeeder $table_job_mapping}
        {--S|sync} {--A|all : Migrate all tables} 
        {--group= : Group index (start from 0) to start the migrate all tables on}
        {--Y|skip-confirm : Skips confirmation when not in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrates the old database records into the new database.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('I work');
        return;
        // dd( $this->options(), $this->arguments() );
        $opts = $this->options();
        $args = $this->arguments();

        if ($args['table'] === null && $opts['all'] === false) {
            $this->error('Specify a table or choose to migrate all tables.');
            return Command::FAILURE;
        }

        // Either migrate all tables
        if ($opts['all']) {
            $group = 0;
            if ( $opts['group'] ) {
                $group = (int) $opts['group'];
            }
            $this->migrateAllTables($group);
        } // Or a single table
        else {
            $this->migrateTable($args['table'], (bool)$opts['sync']);
        }

        return Command::SUCCESS;
    }

    public function migrateAllTables(int $start_group = 0): void
    {
        $telescope_paused = Cache::get('telescope:pause-recording');
        if ( $telescope_paused !== true && ! $this->confirm('Telescope is currently not paused. You can pause Telescope via the /telescope page. Continue?') ) {
            return;
        }

        $progressBar = $this->output->createProgressBar(1);
        foreach ((new MigrationSeeder)::$migrate_all_mapping as $index => $group) {
            if ( $start_group > $index ) {
                continue; // Skip groups until we reach the current group.
            }
            
            $this->alert("Dispatching job group " . ($index));

            $jobs = [];
            $jobCount = 0;
            foreach ($group as $table) {
                $finalJobClass = MigrationSeeder::$table_job_mapping[$table];
                $this->info('Migrating table: '. $table);

                $spawnerJobInstance = new GenericJobSpawner(
                    $finalJobClass,
                    config('migration.connection'),
                    $table,
                    MigrationSeeder::getTableNameExpression($table),
                    false,
                    MigrationSeeder::$where_raw_mapping[$table] ?? [],
                    500
                );

                $jobs[] = $spawnerJobInstance;
                $jobCount += (int) ceil(DB::connection(config('migration.connection'))->table($table)->count() / 500); // The number of final jobs
            }

            Bus::batch($jobs)
                ->dispatch();

            $progressBar->start($jobCount);
            $lastQueueCount = 0;

            $queueCount = DB::table('jobs')->count();

            // Wait for the job queue to be empty before running the next batch.
            while ( $queueCount !== 0 ) {
                if ( $queueCount < $lastQueueCount ) {
                    // Job count decrementing, can start counting completions.
                    $progressBar->advance(abs($queueCount - $lastQueueCount));
                }
                $lastQueueCount = $queueCount;
                sleep(1);

                $queueCount = DB::table('jobs')->count();
            }

            $progressBar->finish();
            $this->line("\n");
        }

        $this->alert('Migration completed.');
    }

    public function migrateTable(string $table, bool $sync = false): void
    {
        if (!array_key_exists($table, (new MigrationSeeder)::$table_job_mapping)) {
            $this->error('The table ' . $table . ' does not exist in our job mapping. See MigrationSeeder::$table_job_mapping.');
            return;
        }

        // Run the seeder.
        (new MigrationSeeder)->run($table, $sync);

        $this->newLine();
        $this->line('<fg=green>Migrated ' . $table . '</>');
    }
}
