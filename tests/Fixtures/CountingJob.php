<?php

namespace PaperleafTech\LaravelMigration\Tests\Fixtures;

use PaperleafTech\LaravelMigration\Jobs\BaseMigrationJob;

/** Touches each row and keeps nothing, the shape of a streaming migration job. */
class CountingJob extends BaseMigrationJob
{
    public static int $seen = 0;

    public static function reset(): void
    {
        static::$seen = 0;
    }

    public function handleItem(object $item): void
    {
        static::$seen++;
    }
}
