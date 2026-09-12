<?php

namespace PaperleafTech\LaravelMigration\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Buffered multi-row inserts, plus the id read-back that lets the next level
 * of a relational migration reference what was just written.
 *
 * A migration job that writes one row at a time so it can use the new id for
 * its children spends a query per row per level. The same work reorders into
 * three steps: plan every row of a level in memory, insert them in one
 * statement, then read back the new ids keyed by their legacy id. Children
 * are planned against the legacy key and resolved through that map.
 *
 *     $map = $writer->insertAndMap('users', $userRows, 'mig_customer_id');
 *
 *     $writer->insert('registrants', collect($planned)->map(fn ($p) => [
 *         'user_id' => $map[$p['customer_id']],
 *         ...
 *     ])->all());
 *
 * This is insert-only. It assumes a migration runs against a fresh
 * destination, which is what makes a legacy id enough to identify a row.
 */
class BulkWriter
{
    /**
     * Placeholder ceiling for one statement. MySQL's hard limit is 65,535
     * bound parameters; the batch size is derived from this and the row's
     * column count.
     */
    protected const MAX_PLACEHOLDERS = 60000;

    /**
     * How many legacy keys go into one id read-back. Keeps the IN () list to
     * a size every engine is comfortable planning.
     */
    protected const MAX_KEYS_PER_LOOKUP = 5000;

    /**
     * Byte budget for one statement's values. The placeholder ceiling alone
     * does not bound payload: a few hundred rows carrying a large text column
     * can exceed max_allowed_packet, which defaults to 16MB on some servers
     * and fails the whole statement. Deliberately well under that, and it only
     * binds on rows carrying large values — ordinary rows hit the placeholder
     * ceiling first.
     */
    protected const MAX_STATEMENT_BYTES = 4194304;

    /**
     * Buffered rows, keyed by table. PHP preserves insertion order, which is
     * the order flush() writes the tables in.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $buffers = [];

    /**
     * @param  string|null  $connection  The destination connection, or null for the default.
     */
    public function __construct(protected ?string $connection = null) {}

    /**
     * Buffer one row for a table.
     *
     * @param  array<string, mixed>  $row
     */
    public function add(string $table, array $row): static
    {
        $this->assertKeyedRow($row);

        $this->buffers[$table][] = $row;

        return $this;
    }

    /**
     * Buffer many rows for a table.
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function addMany(string $table, iterable $rows): static
    {
        foreach ($rows as $row) {
            $this->add($table, $row);
        }

        return $this;
    }

    /**
     * How many rows are waiting to be written for a table.
     */
    public function pending(string $table): int
    {
        return count($this->buffers[$table] ?? []);
    }

    /**
     * Write the buffered rows for one table, or for every table in the order
     * they were first used — which for a levelled migration is parent first,
     * then children.
     *
     * @return int The number of rows written.
     */
    public function flush(?string $table = null): int
    {
        if ($table !== null) {
            $rows = $this->buffers[$table] ?? [];
            unset($this->buffers[$table]);

            return $this->insert($table, $rows);
        }

        $written = 0;

        foreach (array_keys($this->buffers) as $buffered) {
            $written += $this->flush($buffered);
        }

        return $written;
    }

    /**
     * Write rows immediately, splitting into as few statements as the
     * placeholder limit allows.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return int The number of rows written.
     */
    public function insert(string $table, iterable $rows): int
    {
        $rows = $this->normalise($rows);

        if ($rows === []) {
            return 0;
        }

        foreach ($this->batches($rows) as $batch) {
            $this->connection()->table($table)->insert($batch);
        }

        return count($rows);
    }

    /**
     * Split rows into statements that stay under both the placeholder ceiling
     * and the byte budget.
     *
     * A row wider than the budget on its own still goes out alone rather than
     * being dropped; nothing here can make an oversized single row fit.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    protected function batches(array $rows): \Generator
    {
        $perStatement = max(1, intdiv(self::MAX_PLACEHOLDERS, count($rows[0])));

        $batch = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $rowBytes = $this->sizeOf($row);

            if ($batch !== [] && (count($batch) >= $perStatement || $bytes + $rowBytes > self::MAX_STATEMENT_BYTES)) {
                yield $batch;

                $batch = [];
                $bytes = 0;
            }

            $batch[] = $row;
            $bytes += $rowBytes;
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * Roughly how many bytes a row contributes to a statement. Only string
     * lengths matter at this scale; everything else is counted as a small
     * fixed cost.
     *
     * @param  array<string, mixed>  $row
     */
    protected function sizeOf(array $row): int
    {
        $bytes = 0;

        foreach ($row as $value) {
            $bytes += is_string($value) ? strlen($value) + 3 : 8;
        }

        return $bytes;
    }

    /**
     * Write rows, then return their new ids keyed by their legacy id.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<int|string, int> [legacy id => new id]
     */
    public function insertAndMap(string $table, iterable $rows, string $key, string $id = 'id'): array
    {
        $rows = $this->normalise($rows);

        $this->insert($table, $rows);

        return $this->idMap($table, $key, array_column($rows, $key), $id);
    }

    /**
     * Read back the new ids for a set of legacy ids.
     *
     * @param  iterable<int|string|null>  $values
     * @return array<int|string, int> [legacy id => new id]
     */
    public function idMap(string $table, string $key, iterable $values, string $id = 'id'): array
    {
        $values = array_values(array_unique(array_filter(
            is_array($values) ? $values : iterator_to_array($values),
            static fn ($value): bool => $value !== null
        )));

        if ($values === []) {
            return [];
        }

        $map = [];

        foreach (array_chunk($values, self::MAX_KEYS_PER_LOOKUP) as $batch) {
            foreach ($this->connection()->table($table)->whereIn($key, $batch)->get([$key, $id]) as $row) {
                $map[$row->{$key}] = (int) $row->{$id};
            }
        }

        return $map;
    }

    /**
     * Give every row the same columns.
     *
     * Laravel's insert() takes its column list from the first row and sorts
     * each row's values by key, so a row with a different key set lands in
     * the wrong columns without raising anything. Filling the union of keys
     * makes a missing value an explicit null instead.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalise(iterable $rows): array
    {
        $rows = is_array($rows) ? array_values($rows) : iterator_to_array($rows, false);

        if ($rows === []) {
            return [];
        }

        $columns = [];

        foreach ($rows as $row) {
            $this->assertKeyedRow($row);

            foreach ($row as $column => $ignored) {
                $columns[$column] = null;
            }
        }

        return array_map(static fn (array $row): array => array_replace($columns, $row), $rows);
    }

    protected function assertKeyedRow(mixed $row): void
    {
        if (! is_array($row) || ($row !== [] && array_is_list($row))) {
            throw new InvalidArgumentException(
                'Each row must be an array keyed by column name, for example '
                .'["user_id" => 1]. A positional list was given instead.'
            );
        }
    }

    protected function connection(): Connection
    {
        return DB::connection($this->connection);
    }
}
