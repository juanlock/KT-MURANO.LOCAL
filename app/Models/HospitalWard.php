<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HospitalWard extends Model
{
    use SoftDeletes;

    protected $table = 'hospital_wards';

    protected $fillable = ['name', 'department_id', 'type', 'floor', 'daily_rate', 'is_active', 'notes'];

    protected $casts = [
        'department_id' => 'integer',
        'daily_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function department()
    {
        return $this->belongsTo(HospitalDepartment::class, 'department_id', 'id');
    }

    public function beds()
    {
        return $this->hasMany(HospitalBed::class, 'ward_id', 'id');
    }
}
