<?php

namespace PaperleafTech\LaravelMigration\Tests;

class ShippedConfigTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Boot with exactly what a user gets after `migration:install`.
        foreach (require __DIR__.'/../config/laravel-migration.php' as $key => $value) {
            $app['config']->set("laravel-migration.{$key}", $value);
        }

        $app['config']->set('laravel-migration.database_connection', 'migration');
        $app['config']->set('laravel-migration.queue_connection', 'database');
        $app['config']->set('laravel-migration.queue_name', 'migrations');
    }

    public function test_the_shipped_config_maps_no_tables(): void
    {
        $config = require __DIR__.'/../config/laravel-migration.php';

        $this->assertSame(
            [],
            $config['table_job_mapping'],
            'The published config must not map tables to job classes that do not exist in the host app.'
        );
    }

    public function test_a_freshly_published_config_passes_environment_verification(): void
    {
        $this->createJobsTable();

        $this->artisan('migration:run', ['--all' => true])
            ->doesntExpectOutputToContain('is not a valid class')
            ->assertExitCode(0);
    }
}
