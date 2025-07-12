<?php

namespace PaperleafTech\LaravelMigration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use PaperleafTech\LaravelMigration\Models\MigrationData;
use PaperleafTech\LaravelMigration\Models\MigrationMapping;

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
    public function getMappingKey(object $item): string
    {
        return '';
    }

    /**
     * This function is implemented in child class.
     */
    public function handleItem(object $item): void
    {
    }

    /**
     * Lookup the old record from mapping.
     * 
     * @return Model
     */
    public function lookupRecordFromMapping(object $item, string $model_class): Model
    {
        $lookup = (new MigrationMapping)->getItem(
            $this->getMappingKey($item),
            $this->table,
            $model_class
        );

        $record = (new $model_class());

        // Check if we have a record to update
        if ($lookup && $lookup->recordExists()) {
            $record = $lookup->getRecord();
        } else {
            // New record.
        }

        return $record;
    }

    /**
     * Save a mapping data to connect an old row and new record.
     */
    public function saveMappingData(Model $record, object $item): void
    {
        (new MigrationMapping)->setItem(
            $this->getMappingKey($item),
            $this->table,
            get_class($record),
            $record->id
        );
    }

    /**
     * Save a migration data json for a record.
     */
    public function saveMigrationData(Model $record, object $data): void
    {
        (new MigrationData)->setItem($record, $data);
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
