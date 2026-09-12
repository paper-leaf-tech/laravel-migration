<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PaperleafTech\LaravelMigration\Support\BulkWriter;

class BulkWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('testing')->create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->integer('mig_customer_id')->nullable()->index();
        });

        Schema::connection('testing')->create('registrants', function ($table) {
            $table->id();
            $table->integer('user_id');
            $table->string('first_name')->nullable();
        });
    }

    public function test_many_rows_are_written_in_a_single_statement(): void
    {
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ['name' => "N{$i}", 'email' => "u{$i}@example.test", 'mig_customer_id' => $i];
        }

        DB::connection('testing')->flushQueryLog();
        DB::connection('testing')->enableQueryLog();

        $written = $this->writer()->insert('users', $rows);

        $this->assertSame(50, $written);
        $this->assertSame(1, $this->insertCount());
        $this->assertSame(50, DB::connection('testing')->table('users')->count());
    }

    public function test_rows_with_different_keys_are_aligned_rather_than_shifted(): void
    {
        // Laravel's insert() takes its column list from the first row, so a
        // later row with different keys silently lands in the wrong columns.
        $this->writer()->insert('users', [
            ['name' => 'Ada', 'email' => 'ada@example.test', 'mig_customer_id' => 1],
            ['mig_customer_id' => 2, 'name' => 'Grace'],
        ]);

        $grace = DB::connection('testing')->table('users')->where('mig_customer_id', 2)->first();

        $this->assertSame('Grace', $grace->name);
        $this->assertNull($grace->email, 'A key absent from a row must become null, not another row\'s value.');
    }

    public function test_a_large_batch_is_split_to_stay_under_the_placeholder_limit(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25000; $i++) {
            $rows[] = ['name' => "N{$i}", 'email' => null, 'mig_customer_id' => $i];
        }

        DB::connection('testing')->flushQueryLog();
        DB::connection('testing')->enableQueryLog();

        $this->writer()->insert('users', $rows);

        // 4 columns per row, 60,000 placeholders per statement => 15,000 rows each.
        $this->assertSame(2, $this->insertCount());
        $this->assertSame(25000, DB::connection('testing')->table('users')->count());
    }

    public function test_an_id_map_is_read_back_in_one_query(): void
    {
        $this->writer()->insert('users', [
            ['name' => 'Ada', 'mig_customer_id' => 77],
            ['name' => 'Grace', 'mig_customer_id' => 88],
        ]);

        DB::connection('testing')->flushQueryLog();
        DB::connection('testing')->enableQueryLog();

        $map = $this->writer()->idMap('users', 'mig_customer_id', [77, 88]);

        $this->assertSame(1, count(DB::connection('testing')->getQueryLog()));
        $this->assertSame([77, 88], array_keys($map));
        $this->assertSame(
            DB::connection('testing')->table('users')->where('mig_customer_id', 77)->value('id'),
            $map[77]
        );
    }

    public function test_insert_and_map_writes_then_returns_the_new_ids_keyed_by_legacy_id(): void
    {
        $map = $this->writer()->insertAndMap('users', [
            ['name' => 'Ada', 'mig_customer_id' => 5],
            ['name' => 'Grace', 'mig_customer_id' => 6],
        ], 'mig_customer_id');

        $this->assertSame([5, 6], array_keys($map));

        // The ids are usable straight away as a foreign key for the next level.
        $this->writer()->insert('registrants', [
            ['user_id' => $map[5], 'first_name' => 'Ada'],
            ['user_id' => $map[6], 'first_name' => 'Grace'],
        ]);

        $this->assertSame(
            $map[5],
            DB::connection('testing')->table('registrants')->where('first_name', 'Ada')->value('user_id')
        );
    }

    public function test_buffered_rows_are_flushed_per_table_in_the_order_the_tables_were_first_used(): void
    {
        $writer = $this->writer();

        $writer->add('users', ['name' => 'Ada', 'mig_customer_id' => 1]);
        $writer->add('registrants', ['user_id' => 1, 'first_name' => 'Ada']);
        $writer->add('users', ['name' => 'Grace', 'mig_customer_id' => 2]);

        $this->assertSame(2, $writer->pending('users'));
        $this->assertSame(0, DB::connection('testing')->table('users')->count());

        DB::connection('testing')->flushQueryLog();
        DB::connection('testing')->enableQueryLog();

        $writer->flush();

        $tables = array_map(
            fn (array $q): string => str_contains($q['query'], '"users"') ? 'users' : 'registrants',
            array_values(array_filter(
                DB::connection('testing')->getQueryLog(),
                fn (array $q): bool => str_starts_with($q['query'], 'insert')
            ))
        );

        $this->assertSame(['users', 'registrants'], $tables);
        $this->assertSame(2, DB::connection('testing')->table('users')->count());
        $this->assertSame(0, $writer->pending('users'));
    }

    public function test_flushing_a_single_table_leaves_the_others_buffered(): void
    {
        $writer = $this->writer();

        $writer->add('users', ['name' => 'Ada', 'mig_customer_id' => 1]);
        $writer->add('registrants', ['user_id' => 1, 'first_name' => 'Ada']);

        $writer->flush('users');

        $this->assertSame(1, DB::connection('testing')->table('users')->count());
        $this->assertSame(0, DB::connection('testing')->table('registrants')->count());
        $this->assertSame(1, $writer->pending('registrants'));
    }

    public function test_writing_nothing_issues_no_query(): void
    {
        DB::connection('testing')->flushQueryLog();
        DB::connection('testing')->enableQueryLog();

        $this->writer()->insert('users', []);
        $this->writer()->flush();

        $this->assertSame(0, $this->insertCount());
        $this->assertSame([], $this->writer()->idMap('users', 'mig_customer_id', []));
    }

    public function test_a_row_that_is_not_a_key_value_array_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->writer()->insert('users', [['Ada', 'ada@example.test']]);
    }

    private function writer(): BulkWriter
    {
        return new BulkWriter('testing');
    }

    private function insertCount(): int
    {
        return count(array_filter(
            DB::connection('testing')->getQueryLog(),
            fn (array $q): bool => str_starts_with($q['query'], 'insert')
        ));
    }
}
