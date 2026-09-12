<?php

namespace PaperleafTech\LaravelMigration\Tests\Fixtures;

use PaperleafTech\LaravelMigration\Interfaces\MigrationJobInterface;
use PaperleafTech\LaravelMigration\Jobs\BaseMigrationJob;

/**
 * A migration job that records the rows handed to it, so tests can assert on
 * what the chunk queries actually returned.
 */
class RecordingJob extends BaseMigrationJob implements MigrationJobInterface
{
    /** @var array<int, object> */
    public static array $items = [];

    public static function reset(): void
    {
        static::$items = [];
    }

    public function handleItem(object $item): void
    {
        static::$items[] = $item;
    }
}
