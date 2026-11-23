<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\ShapeRecordingJob;

/**
 * Reads a real PostgreSQL table through the package. Skipped unless pointed
 * at one, so the suite still needs nothing but SQLite:
 *
 *     MIGRATION_TEST_PGSQL_URL=pgsql://user:secret@127.0.0.1:5432/legacy \
 *     MIGRATION_TEST_PGSQL_TABLE=some_schema.some_table \
 *     composer test -- --filter PostgresSourceTest
 *
 * The session is made read-only before anything runs, and nothing is
 * created, so it is safe to point at a legacy database.
 */
class PostgresSourceTest extends TestCase
{
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('MIGRATION_TEST_PGSQL_URL') ?: null;
        $table = getenv('MIGRATION_TEST_PGSQL_TABLE') ?: null;

        if ($url === null || $table === null) {
            $this->markTestSkipped('Set MIGRATION_TEST_PGSQL_URL and MIGRATION_TEST_PGSQL_TABLE to read a real PostgreSQL table.');
        }

        $this->table = $table;

        config()->set('database.connections.pgsql_source', ['driver' => 'pgsql', 'url' => $url, 'prefix' => '']);

        DB::connection('pgsql_source')->statement('set default_transaction_read_only = on');

        ShapeRecordingJob::reset();
        cache()->flush();
    }

    public function test_every_row_of_a_schema_qualified_table_reaches_the_job_under_upper_case_aliases(): void
    {
        $expected = DB::connection('pgsql_source')->table($this->table)->count();

        $spawner = new MigrationJobSpawner(
            ShapeRecordingJob::class,
            'pgsql_source',
            $this->table,
            MigrationCommand::getTableNameExpression($this->table, 'pgsql_source'),
            [],
            [],
            1000,
            true,
        );

        $this->assertSame($expected, $spawner->totalCount);

        $spawner->handle();

        $this->assertSame($expected, ShapeRecordingJob::$seen);

        foreach (ShapeRecordingJob::$properties as $property) {
            $this->assertSame(strtoupper($property), $property);
        }
    }
}
