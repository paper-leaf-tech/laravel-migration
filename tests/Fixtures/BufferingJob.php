<?php

namespace PaperleafTech\LaravelMigration\Tests\Fixtures;

use PaperleafTech\LaravelMigration\Jobs\BaseMigrationJob;

/**
 * Buffers rows across per-row calls, the way a job split into traits would,
 * and never flushes explicitly.
 */
class BufferingJob extends BaseMigrationJob
{
    public function handleItem(object $item): void
    {
        $this->writer()->add('users', [
            'name' => $item->U_FNAME,
            'mig_customer_id' => $item->U_ID,
        ]);
    }

    public function exposedWriter(): object
    {
        return $this->writer();
    }
}
