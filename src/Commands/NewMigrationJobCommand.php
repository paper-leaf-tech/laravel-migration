<?php

namespace PaperleafTech\LaravelMigration\Commands;

use Illuminate\Console\Command;

class NewMigrationJobCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migration:new-job {name : The class name for the migration job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a new migration job from a stub.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $stub_path = __DIR__. '/../../stubs/DummyJob.php.stub';
        $base_destination = app_path('Jobs/Migration');

        $name             = $this->argument('name');
        $class_name       = str($name)->studly(); // Converts to StudlyCase
        if ( str_contains($class_name, "{") ) {
            $this->error("Invalid job name. Please provide your own class name for the job.");
            return Command::FAILURE;
        }

        $destination_path = $base_destination . '/' . $class_name . '.php';

        if (! file_exists($stub_path)) {
            $this->error("Stub file not found at: {$stub_path}");
            return Command::FAILURE;
        }

        if (!is_dir($base_destination)) {
            mkdir($base_destination, 0755, true);
        }

        if (file_exists($destination_path)) {
            $this->error("Job already exists at: {$destination_path}");
            return Command::FAILURE;
        }

        $stub = file_get_contents($stub_path);
        $stub = str_replace('DummyJob', $class_name, $stub);

        file_put_contents($destination_path, $stub);

        $this->info("Migration job created: {$destination_path}");

        return Command::SUCCESS;
    }
}
