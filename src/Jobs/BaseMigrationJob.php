<?php

namespace PaperleafTech\LaravelMigration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
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
        $items = DB::connection($this->conn)
            ->select($this->query);

        foreach ($items as $item) {
            $this->handleItem($item);
        }
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
