<?php

namespace Paperleaf\LaravelMigration;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MediaLibraryServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-migration')
            ->hasConfigFile()
            ->hasCommand(LaravelMigrationCommand::class);
            // ->hasInstallCommand(function(InstallCommand $command) {
            //     $command
            //         ->publishConfigFile()
            //         ->publishAssets()
            //         ->publishMigrations()
            //         ->copyAndRegisterServiceProviderInApp()
            //         ->askToStarRepoOnGitHub();
            // });
    }
}
