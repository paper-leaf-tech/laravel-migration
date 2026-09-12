<?php

namespace PaperleafTech\LaravelMigration\Tests\Fixtures;

use PaperleafTech\LaravelMigration\Jobs\BaseMigrationJob;

/**
 * A migration job that overrides the chunk hook instead of the per-row hook,
 * which is what a job doing a bulk upsert would do.
 */
class BulkRecordingJob extends BaseMigrationJob
{
    /** @var array<int, int> */
    public static array $batchSizes = [];

    /** @var array<int, object> */
    public static array $items = [];

    public static function reset(): void
    {
        static::$batchSizes = [];
        static::$items = [];
    }

    public function handleChunk(iterable $items): void
    {
        $rows = collect($items)->all();

        static::$batchSizes[] = count($rows);
        static::$items = array_merge(static::$items, $rows);
    }
}
