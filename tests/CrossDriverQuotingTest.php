<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Jobs\MigrationJobSpawner;
use PaperleafTech\LaravelMigration\Tests\Fixtures\RecordingJob;
use ReflectionClass;

/**
 * The source connection's own grammar quotes every identifier in the chunk
 * SQL, so the package reads from any driver, not only MySQL.
 */
class CrossDriverQuotingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingJob::reset();
        cache()->flush();

        // Connections are lazy: these only lend their grammar to the tests
        // that need one, and never open a socket.
        config()->set('database.connections.my', ['driver' => 'mysql', 'database' => 'x', 'prefix' => '']);
        config()->set('database.connections.maria', ['driver' => 'mariadb', 'database' => 'x', 'prefix' => '']);
        config()->set('database.connections.pg', ['driver' => 'pgsql', 'database' => 'x', 'prefix' => '']);

        // A second schema on the SQLite source, standing in for a PostgreSQL
        // schema such as legacy.
        DB::connection('migration')->statement("attach database ':memory:' as legacy");

        Schema::connection('migration')->create('legacy.user_account', function ($table) {
            $table->integer('user_id')->primary();
            $table->string('user_nm');
            $table->integer('region_id')->nullable();
        });

        Schema::connection('migration')->create('legacy.region', function ($table) {
            $table->integer('region_id')->primary();
            $table->string('region_nm');
        });

        DB::connection('migration')->table('legacy.region')->insert([
            ['region_id' => 1, 'region_nm' => 'Canada'],
        ]);

        for ($i = 1; $i <= 7; $i++) {
            DB::connection('migration')->table('legacy.user_account')->insert([
                'user_id' => $i, 'user_nm' => "User {$i}", 'region_id' => 1,
            ]);
        }
    }

    public function test_no_source_query_is_backtick_quoted_on_a_non_mysql_source(): void
    {
        DB::connection('migration')->flushQueryLog();
        DB::connection('migration')->enableQueryLog();

        $this->spawn('legacy.user_account', chunkSize: 3)->handle();

        $queries = array_column(DB::connection('migration')->getQueryLog(), 'query');

        $this->assertNotEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'row_number()')));
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, '`'))));
    }

    public function test_a_schema_qualified_table_is_read_as_schema_and_table(): void
    {
        $this->assertSame(
            '"legacy"."user_account"',
            MigrationCommand::getTableNameExpression('legacy.user_account', 'migration')
                ->getValue(DB::connection('migration')->getQueryGrammar())
        );

        $spawner = $this->spawn('legacy.user_account', chunkSize: 3);

        // The primary key was found through the schema-qualified name, so
        // keyset chunking engaged: three range chunks over seven keys.
        $this->assertSame(7, $spawner->totalCount);
        $this->assertSame(3, $spawner->jobCount);

        $spawner->handle();

        $ids = array_map(fn (object $item): int => $item->u_a_user_id, RecordingJob::$items);
        sort($ids);

        $this->assertSame(range(1, 7), $ids);
    }

    public function test_a_join_to_another_schema_qualified_table(): void
    {
        $this->spawn('legacy.user_account', joins: [[
            'table' => 'legacy.region',
            'first' => 'legacy.user_account.region_id',
            'second' => 'legacy.region.region_id',
        ]], columns: ['user_nm', 'legacy.region.region_nm'])->handle();

        $this->assertCount(7, RecordingJob::$items);
        $this->assertSame(
            ['u_a_user_id', 'u_a_user_nm', 'r_region_nm'],
            array_keys((array) RecordingJob::$items[0])
        );
        $this->assertSame('Canada', RecordingJob::$items[0]->r_region_nm);
    }

    public function test_aliases_can_be_upper_cased_so_lower_case_sources_keep_legacy_property_names(): void
    {
        config()->set('laravel-migration.alias_case', 'upper');

        $this->spawn('legacy.user_account')->handle();

        $this->assertSame(1, RecordingJob::$items[0]->U_A_USER_ID);
        $this->assertSame('User 1', RecordingJob::$items[0]->U_A_USER_NM);
    }

    public function test_an_unknown_alias_case_is_rejected_before_anything_is_dispatched(): void
    {
        config()->set('laravel-migration.alias_case', 'title');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('alias_case');

        $this->spawn('legacy.user_account');
    }

    public function test_postgres_splits_schema_and_table_and_upper_cases_aliases_by_default(): void
    {
        $spawner = $this->bareSpawner('pg', 'legacy.user_account');

        $this->assertSame(
            '"legacy"."user_account"',
            MigrationCommand::getTableNameExpression('legacy.user_account', 'pg')
                ->getValue(DB::connection('pg')->getQueryGrammar())
        );
        $this->assertSame(
            '"legacy"."user_account"."user_id"',
            $this->invoke($spawner, 'qualifyColumn', 'legacy.user_account', 'user_id')
        );
        $this->assertSame('u_a', $this->invoke($spawner, 'aliasPrefix', 'legacy.user_account'));
        $this->assertSame(
            ['"legacy"."user_account"."user_id" as "U_A_USER_ID"'],
            $this->selectSql($spawner, 'pg', ['legacy.user_account', 'user_id'])
        );
    }

    public function test_mysql_keeps_a_dotted_table_name_as_one_identifier_and_preserves_alias_case(): void
    {
        foreach (['my', 'maria'] as $connection) {
            $spawner = $this->bareSpawner($connection, 'LEGACY.USERS');

            $this->assertSame(
                '`LEGACY.USERS`',
                MigrationCommand::getTableNameExpression('LEGACY.USERS', $connection)
                    ->getValue(DB::connection($connection)->getQueryGrammar())
            );
            $this->assertSame('`LEGACY.USERS`.`FNAME`', $this->invoke($spawner, 'qualifyColumn', 'LEGACY.USERS', 'FNAME'));
            $this->assertSame(
                ['`users`.`fname` as `u_fname`'],
                $this->selectSql($spawner, $connection, ['users', 'fname'])
            );
        }
    }

    public function test_the_configured_source_connection_is_the_default_for_the_table_expression(): void
    {
        config()->set('laravel-migration.database_connection', 'my');

        $this->assertSame(
            '`USERS`',
            MigrationCommand::getTableNameExpression('USERS')->getValue(DB::connection('my')->getQueryGrammar())
        );
    }

    public function test_window_function_support_is_judged_per_driver(): void
    {
        $this->assertTrue($this->invoke($this->bareSpawner('pg', 't'), 'supportsWindowFunctions'));
        $this->assertTrue($this->invoke($this->bareSpawner('migration', 't'), 'supportsWindowFunctions'), 'SQLite 3.25+');
    }

    private function spawn(string $table, array $joins = [], int $chunkSize = 500, array $columns = []): MigrationJobSpawner
    {
        return new MigrationJobSpawner(
            RecordingJob::class,
            'migration',
            $table,
            MigrationCommand::getTableNameExpression($table, 'migration'),
            [],
            $joins,
            $chunkSize,
            true,
            $columns,
        );
    }

    /**
     * A spawner for a connection that has no database behind it, for the
     * parts that only need its grammar.
     */
    private function bareSpawner(string $connection, string $table): MigrationJobSpawner
    {
        $spawner = (new ReflectionClass(MigrationJobSpawner::class))->newInstanceWithoutConstructor();

        (function () use ($connection, $table) {
            $this->conn = $connection;
            $this->table = $table;
        })->call($spawner);

        return $spawner;
    }

    private function invoke(MigrationJobSpawner $spawner, string $method, mixed ...$arguments): mixed
    {
        return (fn () => $this->{$method}(...$arguments))->call($spawner);
    }

    /**
     * The select list getPrefixedColumns() builds for the given [table, column].
     *
     * @return array<int, string>
     */
    private function selectSql(MigrationJobSpawner $spawner, string $connection, array $column): array
    {
        $grammar = DB::connection($connection)->getQueryGrammar();

        $spawner = new class($spawner, $column) extends MigrationJobSpawner
        {
            public function __construct(MigrationJobSpawner $from, private array $only)
            {
                $this->conn = (fn () => $this->conn)->call($from);
                $this->table = (fn () => $this->table)->call($from);
            }

            protected function selectedColumns(): array
            {
                return [$this->only];
            }
        };

        return array_map(fn ($expression) => $expression->getValue($grammar), $this->invoke($spawner, 'getPrefixedColumns'));
    }
}
