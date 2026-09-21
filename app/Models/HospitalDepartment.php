<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HospitalDepartment extends Model
{
    use SoftDeletes;

    protected $table = 'hospital_departments';

    protected $fillable = ['name', 'code', 'description', 'location', 'phone', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function doctors()
    {
        return $this->hasMany(Doctor::class, 'department_id', 'id');
    }

    public function wards()
    {
        return $this->hasMany(HospitalWard::class, 'department_id', 'id');
    }
}
