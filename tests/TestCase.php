<?php

namespace PaperleafTech\LaravelMigration\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PaperleafTech\LaravelMigration\LaravelMigrationServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelMigrationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The destination (default) connection and the source ("migration")
        // connection are separate in-memory SQLite databases, mirroring the
        // real split between the legacy database and the Laravel one.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('database.connections.migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('laravel-migration.database_connection', 'migration');
        $app['config']->set('laravel-migration.queue_connection', 'database');
        $app['config']->set('laravel-migration.queue_name', 'migrations');
        $app['config']->set('queue.connections.database.driver', 'database');
        $app['config']->set('queue.connections.database.table', 'jobs');
        $app['config']->set('queue.connections.database.connection', 'testing');
        $app['config']->set('queue.failed.database', 'testing');
        $app['config']->set('queue.failed.table', 'failed_jobs');
    }

    /**
     * Create the queue's jobs table on the destination connection.
     */
    protected function createJobsTable(string $table = 'jobs', string $connection = 'testing'): void
    {
        Schema::connection($connection)->create($table, function ($blueprint) {
            $blueprint->id();
            $blueprint->string('queue')->index();
            $blueprint->longText('payload');
            $blueprint->unsignedTinyInteger('attempts');
            $blueprint->unsignedInteger('reserved_at')->nullable();
            $blueprint->unsignedInteger('available_at');
            $blueprint->unsignedInteger('created_at');
        });
    }

    /**
     * Insert a placeholder row onto a queue in the jobs table.
     */
    protected function queueRow(string $queue, string $table = 'jobs', string $connection = 'testing'): void
    {
        DB::connection($connection)->table($table)->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }
}
