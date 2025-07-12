<?php

namespace PaperleafTech\LaravelMigration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MigrationData extends Model
{
    protected $table = 'migration_data';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'content' => 'json',
        ];
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Save json data for an item.
     *
     * @param  Model  $record
     * @param  object $data
     * @return void
     */
    public function setItem(Model $record, object $data): void
    {
        $lookup_data = [
            'model_id'   => $record->id,
            'model_type' => get_class($record),
        ];
        $migration_data = $this->where($lookup_data)->first();

        if ($migration_data) {
            // Do nothing, we already have data.
        } else {
            $migration_data = (new MigrationData());
        }

        $data = array_merge($lookup_data, [
            'content' => $data,
        ]);

        $migration_data->fill($data);
        $migration_data->save();

        return;
    }

    public static function getTypeById(Model $record)
    {
        $lookup_data = [
            'model_id'   => $record->id,
            'model_type' => get_class($record),
        ];

        return self::where($lookup_data)->first();
    }
}
