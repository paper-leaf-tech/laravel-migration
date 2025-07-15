<?php

use App\Jobs\Migration;

return [

    /*
     * The database which data is migrated from. Create this
     * connection in config/database.php under the 'connections' key.
     * By default it will use the 'migration' connection.
     */
    'database_connection' => env('MIGRATION_SOURCE_CONNECTION', 'migration'),

    /**
     * The queue connection to use for migration jobs. Can either be database, which 
     * requires the jobs table, or it can be redis.
     */
    'queue_connection' => env('MIGRATION_QUEUE_CONNECTION', 'database'),

    /**
     * The default chunk size for migration jobs. This value represents how many
     * records will be processed per job.
     */
    'default_chunk_size' => env('MIGRATION_CHUNK_SIZE', 500),

    /**
     * The mapping for old table names to migration job classes. You can either provide
     * a value of a single migration job or an array containing a required job key, and
     * optional exclude_wheres, and chunk_size keys.
     */
    'table_job_mapping' => [
        // 'USERS' => Migration\UsersMigrationJob::class
        // 'COMPANIES' => [
        //     // The job class for this migration job.
        //     'job' => Migration\CompaniesMigrationJob::class,

        //     // Optionally provide WHERE conditions for the source database query.
        //     'exclude_wheres' => [
        //         'WHERE deleted = true',
        //         'WHERE created_at < 2020-01-01',
        //     ],

        //     // Optionally provide an override chunk size if the job performs a lot of work.
        //     'chunk_size' => 500,
        // ]
    ],

    /**
     * Define groups of migration items which are dependant on one another.
     * Migration jobs within the same group are independent of each other.
     * Migration jobs in later groups depend on jobs in previous groups running first.
     */
    'table_dependency_groups' => [
        // // Group 0
        // [
        //     'USERS',
        //     'COMPANIES',
        // ],
        // // Group 1
        // [
        //     'USER_COMPANY', // Dependant on USERS and COMPANIES
        // ]
    ],

    /**
     * Optional jobs to run after migrating all tables has been completed.
     */
    'after_jobs' => [
        // Migration/AfterJobExample::class,
    ]
];
