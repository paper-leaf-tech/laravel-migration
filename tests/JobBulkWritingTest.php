<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Support\BulkWriter;
use PaperleafTech\LaravelMigration\Tests\Fixtures\BufferingJob;

class JobBulkWritingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();

        Schema::connection('migration')->create('USERS', function ($table) {
            $table->integer('ID')->primary();
            $table->string('FNAME');
        });

        Schema::connection('testing')->create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('mig_customer_id')->nullable();
        });

        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = ['ID' => $i, 'FNAME' => "N{$i}"];
        }
        DB::connection('migration')->table('USERS')->insert($rows);
    }

    public function test_a_job_exposes_a_bulk_writer(): void
    {
        $this->assertInstanceOf(BulkWriter::class, (new BufferingJob)->exposedWriter());
    }

    public function test_the_same_writer_is_shared_across_a_job(): void
    {
        $job = new BufferingJob;

        $this->assertSame(
            $job->exposedWriter(),
            $job->exposedWriter(),
            'Traits must buffer into one writer, not a new one per call.'
        );
    }

    public function test_rows_buffered_while_handling_a_chunk_are_written_when_it_finishes(): void
    {
        DB::connection('testing')->flushQueryLog();
        DB::connection('testing')->enableQueryLog();

        $this->spawn()->handle();

        $this->assertSame(120, DB::connection('testing')->table('users')->count());
        $this->assertSame(
            2,
            count(array_filter(
                DB::connection('testing')->getQueryLog(),
                fn (array $q): bool => str_starts_with($q['query'], 'insert')
            )),
            '120 rows across two chunks of 60 should be two statements, not 120.'
        );
    }

    public function test_the_writer_targets_the_destination_not_the_source(): void
    {
        $this->spawn()->handle();

        $this->assertSame(120, DB::connection('testing')->table('users')->count());
        $this->assertFalse(Schema::connection('migration')->hasTable('users'));
    }

    private function spawn(): MigrationJobSpawner
    {
        return new MigrationJobSpawner(
            BufferingJob::class,
            'migration',
            'USERS',
            MigrationCommand::getTableNameExpression('USERS'),
            [],
            [],
            60,
            true,
        );
    }
}
