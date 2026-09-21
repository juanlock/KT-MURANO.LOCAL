<?php

namespace App\Traits;

/**
 * Human-readable document numbers for the manufacturing module:
 * PREFIX-YYYYMMDD-NNNN (e.g. MO-20260801-0007), sequential within the day.
 *
 * Unlike {@see GeneratesHospitalReference} this does not call withTrashed():
 * the MRP models follow this codebase's manual `deleted_at` convention rather
 * than Laravel's SoftDeletes trait, so there is no global scope to escape and a
 * plain query already sees deleted rows. That matters — a reference freed by a
 * deletion and handed to a second order is a genuine traceability problem, not
 * a tidy-up.
 */
trait GeneratesMrpReference
{
    public static function nextReference(string $prefix, string $column = 'reference'): string
    {
        $stem = $prefix.'-'.date('Ymd').'-';

        $last = static::where($column, 'like', $stem.'%')
            ->orderByDesc('id')
            ->value($column);

        $seq = $last ? ((int) substr($last, strlen($stem))) + 1 : 1;

        return $stem.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
