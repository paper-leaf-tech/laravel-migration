<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\BulkRecordingJob;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class ChunkHookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::reset();
        BulkRecordingJob::reset();
        cache()->flush();

        Schema::connection('migration')->create('USERS', function ($table) {
            $table->integer('ID')->primary();
            $table->string('FNAME');
        });

        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = ['ID' => $i, 'FNAME' => "N{$i}"];
        }
        DB::connection('migration')->table('USERS')->insert($rows);
    }

    public function test_a_job_can_handle_a_whole_chunk_in_one_call(): void
    {
        $this->spawn(BulkRecordingJob::class)->handle();

        $this->assertSame([100, 100, 50], BulkRecordingJob::$batchSizes);
        $this->assertCount(250, BulkRecordingJob::$items);
    }

    public function test_the_chunk_hook_receives_the_aliased_rows(): void
    {
        $this->spawn(BulkRecordingJob::class)->handle();

        $this->assertSame('N1', BulkRecordingJob::$items[0]->U_FNAME);
    }

    public function test_a_job_overriding_only_the_per_row_hook_still_works(): void
    {
        $this->spawn(RecordingJob::class)->handle();

        $this->assertCount(250, RecordingJob::$items);
        $this->assertSame(1, RecordingJob::$items[0]->U_ID);
    }

    private function spawn(string $job): MigrationJobSpawner
    {
        return new MigrationJobSpawner(
            $job,
            'migration',
            'USERS',
            MigrationCommand::getTableNameExpression('USERS'),
            [],
            [],
            100,
            true,
        );
    }
}
