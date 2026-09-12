<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class InProcessPlanningTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laravel-migration.table_job_mapping', ['USERS' => RecordingJob::class]);
        $app['config']->set('laravel-migration.table_dependency_groups', [['USERS']]);
        $app['config']->set('laravel-migration.default_chunk_size', 100);
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
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = ['ID' => $i, 'FNAME' => "N{$i}"];
        }
        DB::connection('migration')->table('USERS')->insert($rows);
    }

    public function test_chunk_planning_never_reaches_the_queue(): void
    {
        $this->artisan('migration:run', ['--all' => true, '--no-wait' => true])->assertExitCode(0);

        $payloads = DB::connection('testing')->table('jobs')->pluck('payload');

        $this->assertCount(5, $payloads);

        foreach ($payloads as $payload) {
            $this->assertStringNotContainsString(
                class_basename(MigrationJobSpawner::class),
                $payload,
                'Chunk planning must happen in the command, not in a queued job that can outrun retry_after.'
            );
        }
    }

    public function test_the_command_reports_the_row_and_job_counts_per_table(): void
    {
        $this->assertSame(0, Artisan::call('migration:run', ['--all' => true, '--no-wait' => true]));

        $this->assertMatchesRegularExpression(
            '/USERS [. ]+500 rows, 5 jobs/',
            Artisan::output()
        );
    }
}
