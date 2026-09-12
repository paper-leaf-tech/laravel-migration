<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class DryRunTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laravel-migration.table_job_mapping', [
            'USERS' => RecordingJob::class,
            'COMPANIES' => RecordingJob::class,
            'ORPHANED' => RecordingJob::class,
        ]);
        $app['config']->set('laravel-migration.table_dependency_groups', [['USERS'], ['COMPANIES']]);
        $app['config']->set('laravel-migration.default_chunk_size', 100);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::reset();
        cache()->flush();
        $this->createJobsTable();

        $this->seedSource('USERS', 500);
        $this->seedSource('COMPANIES', 250);
        $this->seedSource('ORPHANED', 10);
    }

    public function test_a_dry_run_reports_rows_and_jobs_per_table(): void
    {
        $output = $this->runMigration(['--all' => true, '--dry-run' => true]);

        $this->assertMatchesRegularExpression('/USERS\s*\|\s*500\s*\|\s*5\s*\|/', $output);
        $this->assertMatchesRegularExpression('/COMPANIES\s*\|\s*250\s*\|\s*3\s*\|/', $output);
    }

    public function test_a_dry_run_reports_the_totals(): void
    {
        $output = $this->runMigration(['--all' => true, '--dry-run' => true]);

        $this->assertStringContainsString('750 rows', $output);
        $this->assertStringContainsString('8 jobs', $output);
        $this->assertStringContainsString('Nothing was dispatched', $output);
    }

    public function test_a_dry_run_dispatches_nothing(): void
    {
        $this->artisan('migration:run', ['--all' => true, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, DB::connection('testing')->table('jobs')->count());
        $this->assertSame([], RecordingJob::$items);
    }

    public function test_a_dry_run_honours_a_chunk_size_override(): void
    {
        $output = $this->runMigration(['--all' => true, '--dry-run' => true, '--chunk-size' => 50]);

        $this->assertStringContainsString('15 jobs', $output);
    }

    public function test_a_single_table_can_be_dry_run(): void
    {
        $output = $this->runMigration(['table' => 'USERS', '--dry-run' => true]);

        $this->assertMatchesRegularExpression('/USERS\s*\|\s*500\s*\|\s*5\s*\|/', $output);
        $this->assertSame([], RecordingJob::$items);
    }

    public function test_mapped_tables_missing_from_every_dependency_group_are_reported(): void
    {
        $output = $this->runMigration(['--all' => true, '--dry-run' => true]);

        $this->assertStringContainsString('not listed in any dependency group', $output);
        $this->assertStringContainsString('ORPHANED', $output);
    }

    public function test_a_group_that_covers_every_mapped_table_produces_no_warning(): void
    {
        config(['laravel-migration.table_dependency_groups' => [['USERS', 'COMPANIES', 'ORPHANED']]]);

        $output = $this->runMigration(['--all' => true, '--dry-run' => true]);

        $this->assertStringNotContainsString('not listed in any dependency group', $output);
    }

    private function runMigration(array $arguments): string
    {
        $this->assertSame(0, Artisan::call('migration:run', $arguments));

        return Artisan::output();
    }

    private function seedSource(string $table, int $rows): void
    {
        Schema::connection('migration')->create($table, function ($t) {
            $t->integer('ID')->primary();
            $t->string('FNAME');
        });

        $records = [];
        for ($i = 1; $i <= $rows; $i++) {
            $records[] = ['ID' => $i, 'FNAME' => "N{$i}"];
        }
        DB::connection('migration')->table($table)->insert($records);
    }
}
