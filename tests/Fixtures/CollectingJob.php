<?php

namespace PaperleafTech\LaravelMigration\Tests\Fixtures;

use PaperleafTech\LaravelMigration\Jobs\BaseMigrationJob;

/** Materialises the whole chunk, the shape of a bulk-upsert migration job. */
class CollectingJob extends BaseMigrationJob
{
    public static int $seen = 0;

    public static function reset(): void
    {
        static::$seen = 0;
    }

    public function handleChunk(iterable $items): void
    {
        static::$seen = count(collect($items)->all());
    }
}
