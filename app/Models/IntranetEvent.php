<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Intranet -> Events (per company), shown on the intranet calendar. */
class IntranetEvent extends Model
{
    protected $fillable = ['company_id', 'user_id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'color'];

    protected $casts = ['all_day' => 'boolean', 'starts_at' => 'datetime:Y-m-d H:i:s', 'ends_at' => 'datetime:Y-m-d H:i:s'];

    /** Events overlapping [from, to]. */
    public function scopeOverlapping($q, $from, $to)
    {
        return $q->where('starts_at', '<=', $to)
            ->whereRaw('COALESCE(ends_at, starts_at) >= ?', [$from]);
    }
}
