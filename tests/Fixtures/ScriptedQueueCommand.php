<?php

namespace PaperleafTech\LaravelMigration\Tests\Fixtures;

use PaperleafTech\LaravelMigration\Commands\MigrationCommand;

/**
 * A migration command whose queue depth is scripted, so the wait loop can be
 * driven end to end without a running queue worker.
 */
class ScriptedQueueCommand extends MigrationCommand
{
    /** @var array<int, int> */
    public static array $queueCounts = [];

    /** @var \Closure|null Runs after each poll, to simulate jobs failing mid-run. */
    public static $onPoll = null;

    /** @var array<int, int> */
    public array $observedProgress = [];

    protected function getQueueCount(): int
    {
        $count = array_shift(static::$queueCounts) ?? 0;

        if (static::$onPoll !== null) {
            (static::$onPoll)($count);
        }

        return $count;
    }
}
