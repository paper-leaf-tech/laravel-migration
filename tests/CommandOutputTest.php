<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class CommandOutputTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laravel-migration.table_job_mapping', [
            'registry' => RecordingJob::class,
            'customer' => RecordingJob::class,
        ]);
        $app['config']->set('laravel-migration.table_dependency_groups', [['registry'], ['customer']]);
        $app['config']->set('laravel-migration.default_chunk_size', 100);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createJobsTable();
        RecordingJob::reset();
        cache()->flush();

        foreach (['registry' => 40, 'customer' => 450] as $table => $count) {
            Schema::connection('migration')->create($table, function ($t) {
                $t->integer('ID')->primary();
                $t->string('NAME');
            });

            $rows = [];
            for ($i = 1; $i <= $count; $i++) {
                $rows[] = ['ID' => $i, 'NAME' => "N{$i}"];
            }
            DB::connection('migration')->table($table)->insert($rows);
        }
    }

    public function test_a_dry_run_does_not_claim_to_be_dispatching_anything(): void
    {
        $output = $this->runMigration(['--all' => true, '--dry-run' => true]);

        $this->assertStringNotContainsString('Dispatching', $output);
        $this->assertStringNotContainsString('Migrating table', $output);
    }

    public function test_job_counts_are_pluralised(): void
    {
        $output = $this->runMigration(['--all' => true, '--sync' => true]);

        // The per-table detail line, not the progress bar's "1/1 jobs" ratio.
        $this->assertMatchesRegularExpression('/registry [. ]+40 rows, 1 job(?!s)/', $output);
        $this->assertMatchesRegularExpression('/customer [. ]+450 rows, 5 jobs/', $output);
    }

    public function test_a_synchronous_run_reports_progress_as_chunks_complete(): void
    {
        $output = $this->runMigration(['--all' => true, '--sync' => true]);

        $this->assertStringContainsString('5/5 jobs', $output, 'A --sync run must show progress, not sit silent.');
    }

    public function test_a_completed_run_summarises_what_moved(): void
    {
        $output = $this->runMigration(['--all' => true, '--sync' => true]);

        $this->assertStringContainsString('490 rows', $output);
        $this->assertStringContainsString('2 tables', $output);
        $this->assertStringContainsString('6 jobs', $output);
    }

    public function test_group_headers_are_not_drawn_as_banner_boxes(): void
    {
        $output = $this->runMigration(['--all' => true, '--sync' => true]);

        $this->assertStringNotContainsString('****', $output);
    }

    private function runMigration(array $arguments): string
    {
        $this->assertSame(0, Artisan::call('migration:run', $arguments));

        return Artisan::output();
    }
}
