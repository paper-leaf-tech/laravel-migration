<?php

namespace PaperleafTech\LaravelMigration\Tests\Benchmark;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared measurement helpers for the benchmark suite.
 *
 * Numbers here are query counts, byte counts and peak memory rather than wall
 * clock, because the suite runs against in-memory SQLite: round-trip counts
 * translate to a real database, microseconds do not.
 */
trait Harness
{
    protected int $rowCount = 5000;

    protected int $columnCount = 60;

    protected function buildSourceTable(string $table = 'CUSTOMER_MASTER'): void
    {
        Schema::connection('migration')->create($table, function ($t) {
            $t->integer('ID')->primary();
            $t->string('FNAME');
            $t->string('LNAME');
            $t->string('EMAIL_ADDR');
            for ($c = 1; $c <= $this->columnCount - 4; $c++) {
                $t->string("LEGACY_FIELD_{$c}")->nullable();
            }
        });

        $filler = array_fill_keys(
            array_map(fn ($c) => "LEGACY_FIELD_{$c}", range(1, $this->columnCount - 4)),
            'x'
        );

        $rows = [];
        for ($i = 1; $i <= $this->rowCount; $i++) {
            $rows[] = $filler + [
                'ID' => $i, 'FNAME' => "First{$i}", 'LNAME' => "Last{$i}",
                'EMAIL_ADDR' => "user{$i}@example.test",
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('migration')->table($table)->insert($chunk);
        }
    }

    protected function startMeasuring(): void
    {
        foreach (['migration', 'testing'] as $connection) {
            DB::connection($connection)->flushQueryLog();
            DB::connection($connection)->enableQueryLog();
        }

        gc_collect_cycles();
        memory_reset_peak_usage();
    }

    /**
     * @return array<string, int|string>
     */
    protected function measurements(): array
    {
        $jobs = DB::connection('testing')->getSchemaBuilder()->hasTable('jobs')
            ? DB::connection('testing')->table('jobs')->get()
            : collect();

        return [
            'source queries' => count(DB::connection('migration')->getQueryLog()),
            'queue writes' => count(array_filter(
                DB::connection('testing')->getQueryLog(),
                fn ($q) => str_starts_with($q['query'], 'insert')
            )),
            'queued jobs' => $jobs->count(),
            'queue payload bytes' => $jobs->sum(fn ($j) => strlen($j->payload)),
            'peak memory bytes' => memory_get_peak_usage(false),
        ];
    }

    protected function report(string $scenario, array $measurements): void
    {
        fwrite(STDERR, "\n  ── {$scenario}\n");
        foreach ($measurements as $label => $value) {
            fwrite(STDERR, sprintf("     %-20s %s\n", $label, number_format((float) $value)));
        }
    }
}
