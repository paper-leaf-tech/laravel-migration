<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class SchemaInspectionTest extends TestCase
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
            DB::connection('migration')->table('USERS')->insert(['ID' => $i, 'FNAME' => "N{$i}"]);
        }
    }

    public function test_dispatching_chunks_does_not_re_read_the_source_schema(): void
    {
        $spawner = $this->spawner();

        DB::connection('migration')->flushQueryLog();
        DB::connection('migration')->enableQueryLog();

        $spawner->handle();

        $this->assertSame(
            [],
            $this->schemaQueries(),
            'handle() must reuse the schema the constructor already read.'
        );
    }

    public function test_a_second_spawner_for_the_same_table_reuses_the_cached_schema(): void
    {
        $this->spawner();

        DB::connection('migration')->flushQueryLog();
        DB::connection('migration')->enableQueryLog();

        $this->spawner();

        $this->assertSame([], $this->schemaQueries());
    }

    /**
     * @return array<int, string>
     */
    private function schemaQueries(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $q): string => $q['query'],
                DB::connection('migration')->getQueryLog()
            ),
            fn (string $sql): bool => str_contains($sql, 'pragma_table_xinfo')
                || str_contains($sql, 'sqlite_master')
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
