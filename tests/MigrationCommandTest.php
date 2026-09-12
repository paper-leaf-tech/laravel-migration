<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class MigrationCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laravel-migration.table_job_mapping', [
            'USERS' => RecordingJob::class,
        ]);
        $app['config']->set('laravel-migration.table_dependency_groups', []);
        $app['config']->set('laravel-migration.after_jobs', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createJobsTable();
        RecordingJob::reset();
    }

    public function test_migrating_an_unmapped_table_reports_failure(): void
    {
        $this->artisan('migration:run', ['table' => 'NOT_MAPPED'])
            ->expectsOutputToContain('does not exist in our job mapping')
            ->assertExitCode(1);
    }

    public function test_migrating_a_mapped_table_reports_success(): void
    {
        $this->createSourceUsers(3);

        $this->artisan('migration:run', ['table' => 'USERS'])
            ->assertExitCode(0);

        $this->assertCount(3, RecordingJob::$items);
    }

    private function createSourceUsers(int $count): void
    {
        Schema::connection('migration')->create('USERS', function ($table) {
            $table->integer('ID')->primary();
            $table->string('FNAME');
        });

        for ($i = 1; $i <= $count; $i++) {
            DB::connection('migration')
                ->table('USERS')
                ->insert(['ID' => $i, 'FNAME' => "Name {$i}"]);
        }
    }
}
