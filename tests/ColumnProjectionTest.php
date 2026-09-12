<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;

class ColumnProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::reset();
        cache()->flush();

        Schema::connection('migration')->create('USERS', function ($table) {
            $table->integer('ID')->primary();
            $table->string('FNAME');
            $table->text('BIO');
            $table->integer('COMPANY_ID');
        });

        Schema::connection('migration')->create('COMPANIES', function ($table) {
            $table->integer('ID')->primary();
            $table->string('NAME');
        });

        DB::connection('migration')->table('COMPANIES')->insert(['ID' => 10, 'NAME' => 'Acme']);
        DB::connection('migration')->table('USERS')->insert([
            'ID' => 1, 'FNAME' => 'Ada', 'BIO' => str_repeat('x', 5000), 'COMPANY_ID' => 10,
        ]);
    }

    public function test_omitting_columns_selects_every_column(): void
    {
        $this->spawn()->handle();

        $this->assertSame(
            ['U_ID', 'U_FNAME', 'U_BIO', 'U_COMPANY_ID'],
            array_keys((array) RecordingJob::$items[0])
        );
    }

    public function test_only_the_configured_columns_are_selected(): void
    {
        $this->spawn(columns: ['FNAME'])->handle();

        $this->assertSame(
            ['U_ID', 'U_FNAME'],
            array_keys((array) RecordingJob::$items[0]),
            'The key column is always included; nothing else unlisted is.'
        );
    }

    public function test_columns_can_be_qualified_against_a_joined_table(): void
    {
        $joins = [[
            'table' => 'COMPANIES', 'first' => 'USERS.COMPANY_ID', 'second' => 'COMPANIES.ID',
        ]];

        $this->spawn(columns: ['USERS.FNAME', 'COMPANIES.NAME'], joins: $joins)->handle();

        $item = RecordingJob::$items[0];

        $this->assertSame(['U_ID', 'U_FNAME', 'C_NAME'], array_keys((array) $item));
        $this->assertSame('Acme', $item->C_NAME);
    }

    public function test_an_unknown_column_is_rejected_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NOT_A_COLUMN');

        $this->spawn(columns: ['FNAME', 'NOT_A_COLUMN']);
    }

    public function test_projecting_columns_shrinks_the_queued_payload(): void
    {
        $this->createJobsTable();

        $this->spawn(sync: false)->handle();
        $wide = DB::connection('testing')->table('jobs')->value('payload');

        DB::connection('testing')->table('jobs')->delete();

        $this->spawn(columns: ['FNAME'], sync: false)->handle();
        $narrow = DB::connection('testing')->table('jobs')->value('payload');

        $this->assertLessThan(strlen($wide), strlen($narrow));
    }

    private function spawn(array $columns = [], array $joins = [], bool $sync = true): MigrationJobSpawner
    {
        return new MigrationJobSpawner(
            RecordingJob::class,
            'migration',
            'USERS',
            MigrationCommand::getTableNameExpression('USERS'),
            [],
            $joins,
            500,
            $sync,
            $columns,
        );
    }
}
