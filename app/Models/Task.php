<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'title', 'project_id', 'start_date', 'end_date', 'company_id', 'description', 'status',
        // Real columns that were never mass-assignable, so nothing could write
        // them — the board's priority chips and the delivery report's estimate
        // column depend on these.
        'priority', 'estimated_hour', 'task_progress', 'summary', 'note',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'company_id' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo('App\Models\Project');
    }

    public function company()
    {
        return $this->hasOne('App\Models\Company', 'id', 'company_id');
    }

    public function assignedEmployees()
    {
        return $this->belongsToMany('App\Models\Employee');
    }
}
