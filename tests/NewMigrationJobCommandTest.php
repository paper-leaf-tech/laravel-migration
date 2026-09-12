<?php

namespace PaperleafTech\LaravelMigration\Tests;

use PaperleafTech\LaravelMigration\Interfaces\MigrationJobInterface;

class NewMigrationJobCommandTest extends TestCase
{
    private string $generated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generated = app_path('Jobs/Migration/ExampleMigrationJob.php');

        if (file_exists($this->generated)) {
            unlink($this->generated);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->generated)) {
            unlink($this->generated);
        }

        parent::tearDown();
    }

    public function test_a_generated_job_runs_without_error(): void
    {
        $this->artisan('migration:new-job', ['name' => 'ExampleMigrationJob'])->assertExitCode(0);

        require_once $this->generated;

        $class = 'App\\Jobs\\Migration\\ExampleMigrationJob';
        $job = new $class;

        $job->handleItem((object) ['U_ID' => 1]);

        $this->assertTrue(true, 'The generated stub must not reference undefined variables.');
    }

    public function test_a_generated_job_implements_the_migration_job_interface(): void
    {
        $this->artisan('migration:new-job', ['name' => 'ExampleMigrationJob'])->assertExitCode(0);

        require_once $this->generated;

        $this->assertInstanceOf(
            MigrationJobInterface::class,
            new ('App\\Jobs\\Migration\\ExampleMigrationJob')
        );
    }
}
