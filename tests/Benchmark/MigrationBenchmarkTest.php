<?php

namespace PaperleafTech\LaravelMigration\Tests\Benchmark;

use Illuminate\Support\Facades\DB;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\CollectingJob;
use PaperleafTech\LaravelMigration\Tests\Fixtures\CountingJob;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;
use PaperleafTech\LaravelMigration\Tests\TestCase;

/**
 * Not assertions — a reproducible measurement of what one table migration
 * costs. Run with: vendor/bin/phpunit --testsuite benchmark
 */
class MigrationBenchmarkTest extends TestCase
{
    use Harness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createJobsTable();
        RecordingJob::reset();
        cache()->flush();
        $this->buildSourceTable();
    }

    public function test_benchmark(): void
    {
        fwrite(STDERR, sprintf(
            "\n\n=== %s rows x %s columns, chunk_size 100 (50 chunk jobs) ===\n",
            number_format($this->rowCount),
            $this->columnCount
        ));

        $this->startMeasuring();
        $this->spawner(sync: false)->handle();
        $this->report('dispatching chunk jobs to the queue', $this->measurements());

        DB::connection('testing')->table('jobs')->delete();
        RecordingJob::reset();
        cache()->flush();

        $this->startMeasuring();
        $this->spawner(sync: false, columns: ['FNAME', 'LNAME', 'EMAIL_ADDR'])->handle();
        $this->report('dispatching with a 4-of-60 column projection', $this->measurements());

        DB::connection('testing')->table('jobs')->delete();
        RecordingJob::reset();
        cache()->flush();

        $this->startMeasuring();
        $this->spawner(sync: true)->handle();
        $measurements = $this->measurements();
        $measurements['rows migrated'] = count(RecordingJob::$items);
        $this->report('running the whole table synchronously', $measurements);

        $this->assertSame($this->rowCount, count(RecordingJob::$items));

        $this->reportChunkMemory();
    }

    /**
     * One chunk holding the whole table, handled row by row versus collected
     * into an array, to show what streaming the chunk actually buys.
     */
    private function reportChunkMemory(): void
    {
        fwrite(STDERR, "\n=== one chunk of 5,000 rows x 60 columns ===\n");

        foreach ([CountingJob::class => 'per-row hook (streamed)', CollectingJob::class => 'chunk collected into an array'] as $job => $label) {
            RecordingJob::reset();
            $job::reset();
            cache()->flush();

            gc_collect_cycles();
            $before = memory_get_usage(false);
            memory_reset_peak_usage();

            (new MigrationJobSpawner(
                $job, 'migration', 'CUSTOMER_MASTER',
                MigrationCommand::getTableNameExpression('CUSTOMER_MASTER'),
                [], [], 5000, true,
            ))->handle();

            fwrite(STDERR, sprintf(
                "     %-32s peak +%s bytes (%s rows)\n",
                $label,
                number_format(memory_get_peak_usage(false) - $before),
                number_format($job::$seen)
            ));
        }
    }

    private function spawner(bool $sync, array $columns = []): MigrationJobSpawner
    {
        return new MigrationJobSpawner(
            RecordingJob::class,
            'migration',
            'CUSTOMER_MASTER',
            MigrationCommand::getTableNameExpression('CUSTOMER_MASTER'),
            [],
            [],
            100,
            $sync,
            $columns,
        );
    }
}
