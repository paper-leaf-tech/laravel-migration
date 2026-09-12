<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;
use ReflectionMethod;

class SpawnerQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::reset();
        cache()->flush();

        Schema::connection('migration')->create('USERS', function ($table) {
            $table->integer('ID')->primary();
            $table->string('FNAME');
            $table->integer('COMPANY_ID')->nullable();
        });

        Schema::connection('migration')->create('COMPANIES', function ($table) {
            $table->integer('ID')->primary();
            $table->string('NAME');
        });

        DB::connection('migration')->table('COMPANIES')->insert([
            ['ID' => 10, 'NAME' => 'Acme'],
        ]);

        for ($i = 1; $i <= 7; $i++) {
            DB::connection('migration')->table('USERS')->insert([
                'ID' => $i, 'FNAME' => "Name {$i}", 'COMPANY_ID' => 10,
            ]);
        }
    }

    public function test_a_join_without_an_explicit_operator_or_type_defaults_to_an_inner_equality_join(): void
    {
        $joins = [[
            'table' => 'COMPANIES',
            'first' => 'USERS.COMPANY_ID',
            'second' => 'COMPANIES.ID',
            // 'operator' and 'type' are documented as optional.
        ]];

        $this->spawn(joins: $joins)->handle();

        $this->assertCount(7, RecordingJob::$items);
        $this->assertSame('Acme', RecordingJob::$items[0]->C_NAME);
    }

    public function test_a_table_name_containing_a_dot_is_quoted_as_a_single_identifier(): void
    {
        $method = new ReflectionMethod(MigrationJobSpawner::class, 'qualifyColumn');
        $method->setAccessible(true);

        $this->assertSame(
            '`LEGACY.USERS`.`FNAME`',
            $method->invoke(null, 'LEGACY.USERS', 'FNAME')
        );
    }

    public function test_selected_columns_are_quoted_and_aliased_by_table_initials(): void
    {
        $method = new ReflectionMethod(MigrationJobSpawner::class, 'getPrefixedColumns');
        $method->setAccessible(true);

        $columns = array_map(
            fn ($column): string => $column instanceof Expression
                ? $column->getValue(DB::connection('migration')->getQueryGrammar())
                : (string) $column,
            $method->invoke($this->spawn())
        );

        $this->assertContains('`USERS`.`FNAME` as `U_FNAME`', $columns);
    }

    public function test_every_source_row_reaches_the_job_exactly_once_when_chunked(): void
    {
        $this->spawn(chunkSize: 3)->handle();

        $ids = array_map(fn (object $item): int => $item->U_ID, RecordingJob::$items);
        sort($ids);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $ids);
    }

    public function test_configured_wheres_are_applied_to_every_chunk(): void
    {
        $this->spawn(wheres: ['`USERS`.`ID` > 4'], chunkSize: 2)->handle();

        $ids = array_map(fn (object $item): int => $item->U_ID, RecordingJob::$items);
        sort($ids);

        $this->assertSame([5, 6, 7], $ids);
    }

    private function spawn(array $wheres = [], array $joins = [], int $chunkSize = 500): MigrationJobSpawner
    {
        return new MigrationJobSpawner(
            RecordingJob::class,
            'migration',
            'USERS',
            MigrationCommand::getTableNameExpression('USERS'),
            $wheres,
            $joins,
            $chunkSize,
            true,
        );
    }
}
