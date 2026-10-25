<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A space on a floor map: a patient room (holds beds, may belong to a ward) or
 * an office, the ER, the nursing station, an operating room... Position and
 * size in grid units of the floor's map.
 */
class HmsRoom extends Model
{
    use SoftDeletes, \App\Models\Concerns\BelongsToActingCompany;

    public const KINDS = ['room', 'office', 'er', 'nursing', 'or', 'lab', 'imaging', 'pharmacy', 'reception', 'waiting', 'bath', 'stairs', 'other'];

    protected $table = 'hms_rooms';

    protected $fillable = ['company_id', 'floor_id', 'ward_id', 'name', 'kind', 'map_x', 'map_y', 'map_w', 'map_h', 'color', 'notes'];

    protected $casts = ['floor_id' => 'integer', 'ward_id' => 'integer', 'map_x' => 'integer', 'map_y' => 'integer', 'map_w' => 'integer', 'map_h' => 'integer'];

    public function floor()
    {
        return $this->belongsTo(HmsFloor::class, 'floor_id');
    }

    public function ward()
    {
        return $this->belongsTo(HospitalWard::class, 'ward_id');
    }

    public function beds()
    {
        return $this->hasMany(HospitalBed::class, 'room_id');
    }
}
