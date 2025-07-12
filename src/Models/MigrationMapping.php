<?php

namespace PaperleafTech\LaravelMigration\Models;

use Illuminate\Database\Eloquent\Model;

class MigrationMapping extends Model
{
    protected $table = 'migration_mapping';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Get an item from the database, possibly returning null if it is not found.
     *
     * @param integer|string $old_id - Sometimes a compound index.
     * @param string $old_tablename - The name of the old database table
     * @param string $model_type - The Model::class of the new record
     * @return MigrationMapping|null
     */
    public function getItem(int|string $old_id, string $old_tablename, string $model_type): ?MigrationMapping
    {
        return $this->where([
            'old_id'        => $old_id,
            'old_tablename' => $old_tablename,
            'model_type'    => $model_type,
        ])->first();
    }

    /**
     * Persist a new database mapping row to the database.
     *
     * @param integer|string $old_id - Sometimes a compound index.
     * @param string $old_tablename - The name of the old database table
     * @param string $model_type - The Model::class of the new record
     * @param integer $model_id - The id of the new record
     * @return void
     */
    public function setItem(int|string $old_id, string $old_tablename, string $model_type, int $model_id): void
    {
        $this->firstOrCreate([
            'old_id'        => $old_id,
            'old_tablename' => $old_tablename,
            'model_type'    => $model_type,
            'model_id'      => $model_id
        ]);
    }

    /**
     * Determine if the database mapping record actually has a connected record in our db.
     *
     * @return bool
     */
    public function recordExists(): bool
    {
        if (!$this->exists) {
            return false;
        }

        return (new $this->model_type)->newQuery()->whereKey($this->model_id)->exists();
    }

    /**
     * Access the final record in our database through the MigrationMapping record.
     *
     * @return Model
     */
    public function getRecord(): Model
    {
        return (new $this->model_type)->find($this->model_id);
    }
}
