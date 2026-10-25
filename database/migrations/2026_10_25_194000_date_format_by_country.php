<?php

use App\Support\DateFormats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dates follow the company's country (user's rule, 2026-10-25): Peru (and the
 * other Spanish-speaking countries) DD-MM-YYYY, the United States MM-DD-YYYY.
 * Companies with an unknown country keep their format. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->whereNull('deleted_at')->get(['id', 'country']) as $c) {
            DateFormats::applyCountry((int) $c->id, $c->country);
        }
    }

    public function down(): void
    {
        // Formats stay.
    }
};
