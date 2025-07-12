<?php

namespace PaperleafTech\LaravelMigration;

use PaperleafTech\LaravelMigration\Commands\MigrationCommand;
use PaperleafTech\LaravelMigration\Commands\NewMigrationJobCommand;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelMigrationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-migration')
            ->hasConfigFile('laravel-migration')
            ->hasMigrations([
                'create_migration_data_table',
                'create_migration_mapping_table'
            ])
            ->hasCommands([
                MigrationCommand::class,
                NewMigrationJobCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command->publishConfigFile()
                    ->publishMigrations();
            });
    }
}
