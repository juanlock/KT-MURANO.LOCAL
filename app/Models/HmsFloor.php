<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A floor of the hospital building, with its map grid (Hospital -> Configuration). */
class HmsFloor extends Model
{
    use SoftDeletes, \App\Models\Concerns\BelongsToActingCompany;

    protected $table = 'hms_floors';

    protected $fillable = ['company_id', 'name', 'level', 'map_cols', 'map_rows', 'sort_order', 'notes'];

    protected $casts = ['level' => 'integer', 'map_cols' => 'integer', 'map_rows' => 'integer', 'sort_order' => 'integer'];

    public function rooms()
    {
        return $this->hasMany(HmsRoom::class, 'floor_id');
    }
}
