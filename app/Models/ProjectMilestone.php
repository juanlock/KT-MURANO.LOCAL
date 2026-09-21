<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectMilestone extends Model
{
    use SoftDeletes;

    protected $table = 'project_milestones';

    protected $fillable = [
        'project_id', 'title', 'description', 'due_date', 'completed_on',
        'status', 'progress', 'budget', 'position', 'created_by',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'due_date' => 'date',
        'completed_on' => 'date',
        'progress' => 'integer',
        'budget' => 'decimal:2',
        'position' => 'integer',
        'created_by' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    /**
     * Days until the due date; negative once it has passed. Null when there is
     * no date, or once the milestone is done — a completed milestone cannot be
     * late, however long it took.
     */
    public function getDaysToDueAttribute()
    {
        if (! $this->due_date || $this->status === 'completed') {
            return null;
        }

        return Carbon::today()->diffInDays(Carbon::parse($this->due_date), false);
    }

    public function getIsOverdueAttribute()
    {
        $days = $this->days_to_due;

        return $days !== null && $days < 0;
    }
}
