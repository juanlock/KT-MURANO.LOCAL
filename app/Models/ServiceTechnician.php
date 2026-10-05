<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceTechnician extends Model
{
    use HasFactory;

    protected $table = 'service_technicians';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id',
        // Set when the technician is one of the company's employees (Employee form).
        'employee_id',
        'name',
        'phone',
        'email',
        'notes',
        'is_active',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function jobs()
    {
        return $this->hasMany(ServiceJob::class, 'technician_id');
    }
}

















