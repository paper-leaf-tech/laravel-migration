# Laravel Migration Utilities

A Laravel package to simplify the process of running repeatable data migrations and tracking migrated records. This package provides developer-friendly commands and utilities to help manage migration logic with safety and traceability.

## ✨ Features

- Create and scaffold migration jobs easily.
- Run a migration on a single table, or all tables in one command.
- Prevent duplicate inserts when re-running migrations.
- Handle migration scale. From 100 rows to millions, jobs will be chunked and processed in a memory-safe way.

## 📦 Requirements

- Laravel 11+
- PHP 8.2+
- If using a database queue the [jobs table](https://laravel.com/docs/12.x/queues#driver-prerequisites) must be present. A redis queue can ignore this requirement.

## 🚀 Installation

Add the repository to your `composer.json` file:
```json
"repositories": [
    {
        "type": "github",
        "url": "git@github.com:paper-leaf-tech/laravel-migration.git"
    }
],
```

Install via Composer:
```bash
composer require paper-leaf-tech/laravel-migration
```

Publish the configuration and migration files, and migrate tables:
```bash
php artisan migration:install
php artisan migrate
```

## 🛠 Usage

1. Examine the laravel-migration config file. You will need to add entries into the `table_job_mapping` array as you add migration jobs.

2. run `php artisan migration:new-job {MigrationJobClassName}`

Create a new migration job class with boilerplate code. Each job will migrate old database data into the new laravel database table.

Update the `getItemKey()` method in this job with the primary key of the old database table. Often this is simple, but if there is no primary key (pivot table) you can provide a compound key in a string format.

Update the `handleItem()` method to process the old database row into the new database. A "Mapping" table entry will be created to associate the old record with new, so that if the migration were to be run again, no duplicate data would be created.

3. run `php artisan migration:run {OldTableName} --sync`

While developing your migration job, it is helpful to run a single migration job ignoring the job queue system. The above command will run the single job, synchronously (not as a background job).

4. run `php artisan migration:run --all`

This command will utilize the table_dependency_groups array to run migration jobs in a specific order. Keep in mind that you will need to run the job queue in a separate command line for jobs to be processed.

## 🗃 Migration Mapping Table

The package uses a migration_mapping table to track which records from the old system have already been migrated.

Each mapping includes:
- *old_id* - an integer or string, the primary key of the old record
- *old_tablename* - a string, the name of the old database table
- *model_type* - a string, the class name of the new Laravel model
- *model_id* - a integer, the id value of the new Laravel model

How It Works
- When a record is migrated, its original ID and table name are stored in migration_mapping, alongside the new model type and ID.
- If the data in the original source changes and you re-run the migration, the system will update the existing record instead of inserting a duplicate.
- This allows migrations to be idempotent — safely re-run without side effects.

## ✅ Example

Here's how a typical handleItem function in a migration job might look:

```php
public function handleItem($item): void
{
    // Lookup or get a fresh model to store data in.
    $record = $this->lookupRecordFromMapping($item, DailyIndex::class);

    // Utilize a helper function to execute commonly used lookups
    $oldGasPeriodId = $this->getGasPeriodId($item->UIDGASPERIOD);

    // Skip migrating the record if we encounter invalid data
    if ( ! $oldGasPeriodId ) {
        Log::error('DailyIndexPriceJobImport. Missing gas period.', [
            'UIDGASDAILYINDEXPRICE' => $item->UIDGASDAILYINDEXPRICE,
            'UIDGASPERIOD'          => $item->UIDGASPERIOD,
        ]);
        return;
    }

    // Prepare the data to be stored into the Laravel model
    $data = [
        'gas_period_id' => $oldGasPeriodId,
        'price_type'    => $item->PRICETYPE,
        'price'         => (float) $item->PRICE,
        'locked'        => true,
        'updated_at'    => $item->EDTIME ? Carbon::parse($item->EDTIME) : now(),
    ];

    // Store the data, saving quietly so that any model observers don't trigger.
    $record->fill($data);
    $record->saveQuietly();

    // Save mapping data, and migration data to associate this record with the old data in case we need it in the future.
    $this->saveMappingData($record, $item);
    $this->saveMigrationData($record, $item);
}
```

## ⁉️ Common Issues

#### Running out of memory
Jobs are by default chunked to process 500 rows of data per job. If the job performs too much logic, or too many queries it may be helpful to lower this value for this job. You can do that by updating the migration job item under the laravel-migration configuration file's `table_job_mapping` key.

#### Property not fillable
The current structure requires that the model allows mass assignment of properties. You can add `protected $guarded = [];` to your model to allow all properties to be mass assigned.

#### Source table has no Primary Key
This can arise when migrating pivot tables. You can use a compound key, such as a string utilizing values from multiple different columns which will then represent a unique value.