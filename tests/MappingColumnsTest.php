<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class MappingColumnsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laravel-migration.table_job_mapping', [
            'USERS' => [
                'job' => RecordingJob::class,
                'columns' => ['FNAME'],
            ],
            'BROKEN' => [
                'job' => RecordingJob::class,
                'columns' => ['NOT_A_COLUMN'],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::reset();
        cache()->flush();
        $this->createJobsTable();

        foreach (['USERS', 'BROKEN'] as $table) {
            Schema::connection('migration')->create($table, function ($t) {
                $t->integer('ID')->primary();
                $t->string('FNAME');
                $t->text('BIO');
            });

            DB::connection('migration')->table($table)->insert([
                'ID' => 1, 'FNAME' => 'Ada', 'BIO' => str_repeat('x', 1000),
            ]);
        }
    }

    public function test_the_mapping_exposes_a_columns_key(): void
    {
        $item = MigrationCommand::getMigrationItem(
            ['USERS' => ['job' => RecordingJob::class, 'columns' => ['FNAME']]],
            'USERS'
        );

        $this->assertSame(['FNAME'], $item['columns']);
    }

    public function test_a_mapping_without_columns_defaults_to_every_column(): void
    {
        $item = MigrationCommand::getMigrationItem(['USERS' => RecordingJob::class], 'USERS');

        $this->assertSame([], $item['columns']);
    }

    public function test_the_command_applies_the_configured_projection(): void
    {
        $this->artisan('migration:run', ['table' => 'USERS'])->assertExitCode(0);

        $this->assertSame(['U_ID', 'U_FNAME'], array_keys((array) RecordingJob::$items[0]));
    }

    public function test_a_bad_projection_reports_a_clean_error_instead_of_a_stack_trace(): void
    {
        $this->artisan('migration:run', ['table' => 'BROKEN'])
            ->expectsOutputToContain('NOT_A_COLUMN')
            ->assertExitCode(1);
    }
}
