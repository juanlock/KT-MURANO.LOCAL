<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One department + designation an employee holds. An employee can hold
 * several (one person = one employee = one login); the primary one is
 * mirrored on employees.department_id / designation_id.
 */
class EmployeePosition extends Model
{
    protected $table = 'employee_positions';

    protected $fillable = ['employee_id', 'department_id', 'designation_id', 'is_primary'];

    protected $casts = [
        'employee_id' => 'integer',
        'department_id' => 'integer',
        'designation_id' => 'integer',
        'is_primary' => 'boolean',
    ];
}
