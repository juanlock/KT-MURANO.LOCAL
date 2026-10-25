<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Only today's doses can be recorded (user's rule, 2026-10-25). Doses that
 * were recorded BEFORE their day (e.g. tomorrow's marked as given today) go
 * back to pending; past days' unrecorded ones become "missed". Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hms_medication_doses')) {
            return;
        }
        DB::table('hms_medication_doses')->whereIn('status', ['given', 'omitted', 'refused'])
            ->whereNotNull('given_at')->whereRaw('DATE(given_at) < DATE(scheduled_at)')
            ->update(['status' => 'pending', 'given_at' => null, 'given_by' => null, 'updated_at' => now()]);
        DB::table('hms_medication_doses')->where('status', 'pending')->where('scheduled_at', '<', now()->startOfDay())
            ->update(['status' => 'missed', 'notes' => 'Not recorded by nursing', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Records stay as they are.
    }
};
