<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One custody spell: an asset in someone's hands from a date until they hand
 * it back. Rows are never edited into "returned" by hand — the check-in action
 * closes them, so the register and the asset's assigned_to_id cannot disagree.
 */
class AssetAssignment extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'asset_id',
        'user_id',
        'assigned_on',
        'due_back_on',
        'returned_on',
        'condition_out',
        'condition_in',
        'notes',
        'status',
        'created_by',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'assigned_on' => 'date',
        'due_back_on' => 'date',
        'returned_on' => 'date',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'assigned';
    }

    /** Still out and past the date it was promised back. */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_back_on
            && $this->due_back_on->lt(Carbon::today());
    }

    /** How long the asset has been out — to date, or until it came back. */
    public function daysHeld(): int
    {
        $end = $this->returned_on ?: Carbon::today();

        return max(0, (int) Carbon::parse($this->assigned_on)->diffInDays($end));
    }
}
