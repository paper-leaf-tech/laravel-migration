<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class BulkDispatchTest extends TestCase
{
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
        for ($i = 1; $i <= 1200; $i++) {
            $rows[] = ['ID' => $i, 'FNAME' => "N{$i}"];
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::connection('migration')->table('USERS')->insert($chunk);
        }
    }

    public function test_chunk_jobs_are_written_to_the_queue_in_one_statement(): void
    {
        $this->measure(fn () => $this->spawn(chunkSize: 100)->handle());

        $this->assertSame(1, $this->queueInserts(), '12 chunk jobs should be one insert.');
        $this->assertSame(12, DB::connection('testing')->table('jobs')->count());
    }

    public function test_very_large_dispatches_are_written_in_bounded_batches(): void
    {
        $this->measure(fn () => $this->spawn(chunkSize: 1)->handle());

        $this->assertSame(3, $this->queueInserts(), '1,200 chunk jobs at 500 per write is 3 statements.');
        $this->assertSame(1200, DB::connection('testing')->table('jobs')->count());
    }

    public function test_queued_jobs_carry_the_configured_queue_name(): void
    {
        $this->spawn(chunkSize: 100)->handle();

        $this->assertSame(
            ['migrations'],
            DB::connection('testing')->table('jobs')->distinct()->pluck('queue')->all()
        );
    }

    public function test_synchronous_runs_never_touch_the_queue(): void
    {
        $this->spawn(chunkSize: 100, sync: true)->handle();

        $this->assertSame(0, DB::connection('testing')->table('jobs')->count());
        $this->assertCount(1200, RecordingJob::$items);
    }

    private function measure(callable $callback): void
    {
        DB::connection('testing')->flushQueryLog();
        DB::connection('testing')->enableQueryLog();

        $callback();
    }

    private function queueInserts(): int
    {
        return count(array_filter(
            DB::connection('testing')->getQueryLog(),
            fn (array $q): bool => str_starts_with($q['query'], 'insert')
        ));
    }

    private function spawn(int $chunkSize, bool $sync = false): MigrationJobSpawner
    {
        return new MigrationJobSpawner(
            RecordingJob::class,
            'migration',
            'USERS',
            MigrationCommand::getTableNameExpression('USERS'),
            [],
            [],
            $chunkSize,
            $sync,
        );
    }
}
