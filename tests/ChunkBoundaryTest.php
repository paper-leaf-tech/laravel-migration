<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class ChunkBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::reset();
        cache()->flush();

        Schema::connection('migration')->create('USERS', function ($table) {
            $table->integer('ID')->primary();
            $table->string('FNAME');
        });

        for ($i = 1; $i <= 300; $i++) {
            DB::connection('migration')->table('USERS')->insert(['ID' => $i * 2, 'FNAME' => "N{$i}"]);
        }
    }

    public function test_keyset_chunking_reads_the_key_column_in_a_single_pass(): void
    {
        DB::connection('migration')->flushQueryLog();
        DB::connection('migration')->enableQueryLog();

        $spawner = $this->spawner();

        $this->assertSame([], $this->queriesMatching('count(distinct'));
        $this->assertSame(300, $spawner->totalCount);
        $this->assertSame(3, $spawner->jobCount);
    }

    public function test_the_chunk_boundaries_are_not_recomputed_when_chunks_are_dispatched(): void
    {
        $spawner = $this->spawner();

        DB::connection('migration')->flushQueryLog();
        DB::connection('migration')->enableQueryLog();

        $spawner->handle();

        $this->assertSame([], $this->queriesMatching('row_number()'));
    }

    public function test_every_row_still_reaches_the_job_exactly_once(): void
    {
        $this->spawner()->handle();

        $ids = array_map(fn (object $item): int => $item->U_ID, RecordingJob::$items);
        sort($ids);

        $this->assertSame(range(2, 600, 2), $ids);
    }

    public function test_an_empty_source_table_dispatches_nothing(): void
    {
        DB::connection('migration')->table('USERS')->delete();

        $spawner = $this->spawner();
        $spawner->handle();

        $this->assertSame(0, $spawner->totalCount);
        $this->assertSame(0, $spawner->jobCount);
        $this->assertSame([], RecordingJob::$items);
    }

    /**
     * @return array<int, string>
     */
    private function queriesMatching(string $needle): array
    {
        return array_values(array_filter(
            array_map(fn (array $q): string => $q['query'], DB::connection('migration')->getQueryLog()),
            fn (string $sql): bool => str_contains($sql, $needle)
        ));
    }

    private function spawner(): MigrationJobSpawner
    {
        return new MigrationJobSpawner(
            RecordingJob::class,
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
