<?php

namespace App\Traits;

/**
 * Human-readable document numbers for the hospital module: PREFIX-YYYYMMDD-NNNN
 * (e.g. APT-20260729-0007), sequential within the day.
 *
 * Trashed rows are included when finding the last number so a deleted record
 * never frees its reference for reuse — an invoice number that reappears is a
 * genuine accounting problem, not a tidy-up.
 */
trait GeneratesHospitalReference
{
    public static function nextReference($prefix, $column = 'reference')
    {
        $stem = $prefix . '-' . date('Ymd') . '-';

        $last = static::withTrashed()
            ->where($column, 'like', $stem . '%')
            ->orderBy('id', 'desc')
            ->first();

        $seq = $last ? ((int) substr($last->{$column}, strlen($stem))) + 1 : 1;

        return $stem . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
