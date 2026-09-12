<?php

namespace PaperleafTech\LaravelMigration\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

class PackageManifestTest extends BaseTestCase
{
    /**
     * The package builds queries with the Laravel 11 schema inspection API
     * (getIndexes/getColumns). Without a declared Illuminate constraint,
     * Composer will happily install it beside Laravel 10, where those calls
     * throw and the package silently degrades to unordered chunking.
     */
    public function test_the_package_declares_its_illuminate_dependency(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        $illuminate = array_filter(
            array_keys($composer['require']),
            fn (string $package): bool => str_starts_with($package, 'illuminate/'),
        );

        $this->assertNotEmpty($illuminate, 'composer.json must require an illuminate/* package.');

        foreach ($illuminate as $package) {
            $this->assertStringNotContainsString(
                '^10',
                $composer['require'][$package],
                "{$package} must not allow Laravel 10, which lacks getIndexes()/getColumns()."
            );
        }
    }

    /**
     * The constraint has to keep pace with the applications using the package.
     * A constraint that stops at the previous major does not fail loudly — it
     * makes `composer update` quietly refuse to take the new version.
     */
    public function test_the_illuminate_constraint_covers_every_supported_laravel(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        foreach ($composer['require'] as $package => $constraint) {
            if (! str_starts_with($package, 'illuminate/')) {
                continue;
            }

            foreach (['^11.0', '^12.0', '^13.0'] as $supported) {
                $this->assertStringContainsString(
                    $supported,
                    $constraint,
                    "{$package} must allow Laravel {$supported}; the test suite runs against it."
                );
            }
        }
    }
}
