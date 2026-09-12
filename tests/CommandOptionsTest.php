<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class CommandOptionsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laravel-migration.table_job_mapping', ['USERS' => RecordingJob::class]);
        $app['config']->set('laravel-migration.table_dependency_groups', [['USERS']]);
        $app['config']->set('laravel-migration.default_chunk_size', 500);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::reset();
        cache()->flush();
        $this->createJobsTable();

        Schema::connection('migration')->create('USERS', function ($table) {
            $table->integer('ID')->primary();
            $table->string('FNAME');
        });

        $rows = [];
        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = ['ID' => $i, 'FNAME' => "N{$i}"];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('migration')->table('USERS')->insert($chunk);
        }
    }

    public function test_chunk_size_can_be_overridden_on_the_command_line(): void
    {
        $this->artisan('migration:run', ['table' => 'USERS', '--queue' => true, '--no-wait' => true])
            ->assertExitCode(0);

        $this->assertSame(2, $this->queuedJobs(), '1,000 rows at the configured 500 is 2 jobs.');

        DB::connection('testing')->table('jobs')->delete();

        $this->artisan('migration:run', [
            'table' => 'USERS', '--queue' => true, '--no-wait' => true, '--chunk-size' => 100,
        ])->assertExitCode(0);

        $this->assertSame(10, $this->queuedJobs(), '1,000 rows at 100 is 10 jobs.');
    }

    public function test_a_single_table_can_be_pushed_to_the_queue_instead_of_run_synchronously(): void
    {
        $this->artisan('migration:run', ['table' => 'USERS', '--queue' => true, '--no-wait' => true])
            ->assertExitCode(0);

        $this->assertSame(2, $this->queuedJobs());
        $this->assertSame([], RecordingJob::$items, 'Nothing should have run in-process.');
    }

    public function test_a_single_table_still_runs_synchronously_by_default(): void
    {
        $this->artisan('migration:run', ['table' => 'USERS'])->assertExitCode(0);

        $this->assertCount(1000, RecordingJob::$items);
        $this->assertSame(0, $this->queuedJobs());
    }

    public function test_all_tables_can_be_run_synchronously_without_a_worker(): void
    {
        $this->artisan('migration:run', ['--all' => true, '--sync' => true])->assertExitCode(0);

        $this->assertCount(1000, RecordingJob::$items);
        $this->assertSame(0, $this->queuedJobs());
    }

    public function test_no_wait_dispatches_and_returns_without_draining_the_queue(): void
    {
        $this->artisan('migration:run', ['--all' => true, '--no-wait' => true])->assertExitCode(0);

        $this->assertSame(2, $this->queuedJobs());
        $this->assertSame([], RecordingJob::$items);
    }

    public function test_an_invalid_chunk_size_is_rejected(): void
    {
        $this->artisan('migration:run', ['table' => 'USERS', '--chunk-size' => 0])
            ->expectsOutputToContain('chunk size')
            ->assertExitCode(1);
    }

    private function queuedJobs(): int
    {
        return DB::connection('testing')->table('jobs')->count();
    }
}
