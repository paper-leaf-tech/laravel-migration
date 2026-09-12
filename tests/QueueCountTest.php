<?php

namespace PaperleafTech\LaravelMigration\Tests;

use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use ReflectionMethod;

class QueueCountTest extends TestCase
{
    public function test_the_queue_count_ignores_jobs_on_other_queues(): void
    {
        $this->createJobsTable();

        $this->queueRow('migrations');
        $this->queueRow('migrations');
        $this->queueRow('default'); // unrelated application work

        $this->assertSame(2, $this->queueCount());
    }

    public function test_the_queue_count_reads_the_queue_connections_configured_table(): void
    {
        config(['queue.connections.database.table' => 'legacy_jobs']);

        $this->createJobsTable('legacy_jobs');
        $this->queueRow('migrations', 'legacy_jobs');

        $this->assertSame(1, $this->queueCount());
    }

    public function test_the_queue_count_reads_the_queue_connections_database(): void
    {
        config([
            'database.connections.queue_store' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'queue.connections.database.connection' => 'queue_store',
        ]);

        $this->createJobsTable('jobs', 'queue_store');
        $this->queueRow('migrations', 'jobs', 'queue_store');

        $this->assertSame(1, $this->queueCount());
    }

    public function test_environment_verification_looks_for_the_queue_connections_table(): void
    {
        config(['queue.connections.database.table' => 'legacy_jobs']);
        $this->createJobsTable('legacy_jobs');

        $this->artisan('migration:run', ['--all' => true])->assertExitCode(0);
    }

    public function test_environment_verification_rejects_an_unsupported_queue_connection(): void
    {
        config(['laravel-migration.queue_connection' => 'sync']);

        $this->artisan('migration:run', ['--all' => true])
            ->expectsOutputToContain('sync')
            ->assertExitCode(1);
    }

    private function queueCount(): int
    {
        $command = new MigrationCommand;
        $command->setLaravel($this->app);

        $method = new ReflectionMethod($command, 'getQueueCount');
        $method->setAccessible(true);

        return $method->invoke($command);
    }
}
