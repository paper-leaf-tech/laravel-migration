<?php

namespace PaperleafTech\LaravelMigration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class BaseMigrationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The query which we can use to fetch the current set of data from.
     */
    protected string $query;

    /**
     * The name of the connection we are migrating data from.
     * Named "conn" due to property conflict
     */
    protected string $conn;

    /**
     * The table which we are migrating.
     */
    protected string $table;

    public function setQuery(string $query): static
    {
        $this->query = $query;
        return $this;
    }

    public function setConnection(string $conn): static
    {
        $this->conn = $conn;
        return $this;
    }

    public function setTable(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->handleChunk($this->fetchChunk());
    }

    /**
     * Handle every row in this chunk.
     *
     * Override this instead of handleItem() when the destination writes can be
     * batched — one upsert of 500 rows rather than 500 saves is typically the
     * largest single speed-up available to a migration job:
     *
     *     public function handleChunk(iterable $items): void
     *     {
     *         User::upsert(
     *             collect($items)->map($this->toRow(...))->all(),
     *             ['legacy_id'],
     *         );
     *     }
     *
     * @param  iterable<object>  $items
     */
    public function handleChunk(iterable $items): void
    {
        foreach ($items as $item) {
            $this->handleItem($item);
        }
    }

    /**
     * Stream this chunk's rows from the source connection.
     *
     * A lazy collection rather than an array, so a job that only implements
     * handleItem() never holds the whole chunk in memory at once, and a job
     * that wants the whole chunk can still collect() it.
     *
     * @return \Illuminate\Support\LazyCollection<int, object>
     */
    protected function fetchChunk(): LazyCollection
    {
        return LazyCollection::make(fn () => yield from DB::connection($this->conn)->cursor($this->query));
    }

    /**
     * This function is implemented in child class.
     */
    public function handleItem(object $item): void
    {
    }

    /**
     * Sanitize a text string from the old database.
     */
    public function sanitizeText(?string $text): string
    {
        if (! is_string($text)) {
            return '';
        }

        return trim(strip_tags(htmlspecialchars_decode($text)));
    }
}
