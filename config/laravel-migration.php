<?php

use App\Jobs\Migration;

return [

    /*
     * The database which data is migrated into.
     */
    'database_connection' => env('MIGRATION_DESTINATION_CONNECTION', env('DB_CONNECTION')),

    /**
     * The database which data is migrated from.
     */
    'migration_connection' => env('MIGRATION_SOURCE_CONNECTION', 'migration'),

    /**
     * The default chunk size for migration jobs. This value represents how many
     * records will be processed per job.
     */
    'default_chunk_size' => env('MIGRATION_CHUNK_SIZE', 500),

    /**
     * The mapping for old table names to migration jobs. You can either provide 
     * a single migration job or an array containing job, exclude, and chunk_size keys.
     */
    'table_job_mapping' => [
        // 'OLD_TABLE_NAME' => Migration\MigrationJob::class,
        // 'OLD_TABLE_NAME_2' => [
        //     // The job class for this migration job.
        //     'job' => Migration\AnotherMigrationJob::class,

        //     // Optionally provide WHERE conditions for the source database query.
        //     'exclude' => [
        //         'WHERE deleted = true',
        //         'WHERE created_at < 2020-01-01',
        //     ],

        //     // Optionally provide a specific chunk size if the job performs a lot of work.
        //     'chunk_size' => 500,
        // ]
    ],
];
