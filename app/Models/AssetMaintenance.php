<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A service, repair or inspection booked against an asset. The running total
 * of these is what turns a purchase price into a cost of ownership.
 */
class AssetMaintenance extends Model
{
    use HasFactory;

    protected $table = 'asset_maintenances';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'asset_id',
        'type',
        'title',
        'vendor',
        'scheduled_date',
        'completed_date',
        'cost',
        'next_due_date',
        'status',
        'notes',
        'created_by',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'cost' => 'double',
        'scheduled_date' => 'date',
        'completed_date' => 'date',
        'next_due_date' => 'date',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    /** Booked, not done, and the date has passed. */
    public function isOverdue(): bool
    {
        return in_array($this->status, ['scheduled', 'in_progress'], true)
            && $this->scheduled_date
            && $this->scheduled_date->lt(Carbon::today());
    }

    /** Days the asset was off the road; null while the job is still open. */
    public function downtimeDays(): ?int
    {
        if ($this->status !== 'completed' || ! $this->completed_date) {
            return null;
        }

        return max(0, (int) Carbon::parse($this->scheduled_date)->diffInDays($this->completed_date));
    }
}
