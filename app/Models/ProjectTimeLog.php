<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectTimeLog extends Model
{
    use SoftDeletes;

    protected $table = 'project_time_logs';

    protected $fillable = [
        'project_id', 'task_id', 'employee_id', 'log_date', 'hours',
        'billable', 'hourly_rate', 'amount', 'description', 'created_by',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'task_id' => 'integer',
        'employee_id' => 'integer',
        'log_date' => 'date',
        'hours' => 'decimal:2',
        'billable' => 'boolean',
        'hourly_rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id', 'id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }
}
