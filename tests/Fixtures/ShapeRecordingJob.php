<?php

namespace PaperleafTech\LaravelMigration\Tests\Fixtures;

use PaperleafTech\LaravelMigration\Jobs\BaseMigrationJob;

/** Counts rows and keeps only the first row's property names, for large tables. */
class ShapeRecordingJob extends BaseMigrationJob
{
    public static int $seen = 0;

    /** @var array<int, string> */
    public static array $properties = [];

    public static function reset(): void
    {
        static::$seen = 0;
        static::$properties = [];
    }

    public function handleItem(object $item): void
    {
        if (static::$seen++ === 0) {
            static::$properties = array_keys((array) $item);
        }
    }
}
