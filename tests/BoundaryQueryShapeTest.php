<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class BoundaryQueryShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::reset();
        cache()->flush();

        Schema::connection('migration')->create('USERS', function ($table) {
            $table->integer('ID')->primary();
            $table->integer('COMPANY_ID')->nullable();
        });

        Schema::connection('migration')->create('ADDRESSES', function ($table) {
            $table->integer('ID')->primary();
            $table->integer('USER_ID');
        });

        for ($i = 1; $i <= 20; $i++) {
            DB::connection('migration')->table('USERS')->insert(['ID' => $i, 'COMPANY_ID' => 1]);
            // Two addresses per user: a join that fans rows out.
            DB::connection('migration')->table('ADDRESSES')->insert([
                ['ID' => $i * 2 - 1, 'USER_ID' => $i],
                ['ID' => $i * 2, 'USER_ID' => $i],
            ]);
        }
    }

    public function test_an_unjoined_key_column_is_not_deduplicated(): void
    {
        $this->spawn();

        $this->assertStringNotContainsString(
            'distinct',
            $this->boundaryQuery(),
            'Without a join the key column is unique by definition; DISTINCT only adds a dedup step.'
        );
    }

    public function test_a_joined_key_column_is_still_deduplicated(): void
    {
        $this->spawn(joins: [[
            'table' => 'ADDRESSES', 'first' => 'ADDRESSES.USER_ID', 'second' => 'USERS.ID',
        ]]);

        $this->assertStringContainsString(
            'distinct',
            $this->boundaryQuery(),
            'A fanning join repeats the key, so boundaries would land mid-key without DISTINCT.'
        );
    }

    public function test_counts_are_unchanged_without_a_join(): void
    {
        $spawner = $this->spawn(chunkSize: 7);

        $this->assertSame(20, $spawner->totalCount);
        $this->assertSame(3, $spawner->jobCount);
    }

    public function test_a_fanning_join_still_counts_distinct_keys_and_covers_every_row(): void
    {
        $spawner = $this->spawn(chunkSize: 7, joins: [[
            'table' => 'ADDRESSES', 'first' => 'ADDRESSES.USER_ID', 'second' => 'USERS.ID',
        ]]);

        $this->assertSame(20, $spawner->totalCount, 'Distinct keys, not fanned-out rows.');

        $spawner->handle();

        $ids = array_unique(array_map(fn (object $i): int => $i->U_ID, RecordingJob::$items));
        sort($ids);

        $this->assertSame(range(1, 20), $ids);
        $this->assertCount(40, RecordingJob::$items, 'Two address rows per user.');
    }

    private function boundaryQuery(): string
    {
        foreach (DB::connection('migration')->getQueryLog() as $query) {
            if (str_contains($query['query'], 'row_number()')) {
                return $query['query'];
            }
        }

        $this->fail('No boundary query was issued.');
    }

    private function spawn(int $chunkSize = 100, array $joins = []): MigrationJobSpawner
    {
        DB::connection('migration')->flushQueryLog();
        DB::connection('migration')->enableQueryLog();

        return new MigrationJobSpawner(
            RecordingJob::class, 'migration', 'USERS',
            MigrationCommand::getTableNameExpression('USERS'),
            [], $joins, $chunkSize, true,
        );
    }
}
