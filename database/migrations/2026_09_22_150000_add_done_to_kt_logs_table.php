<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `done` -- the verbatim closing message sent to the user for this request
 * (what Protocol Kelsy reports back at the end of a turn), not a re-summary
 * of it. Distinct from `summary` (one line) and `chat_transcript` (a
 * condensed third-person narrative) -- this is the actual text the user read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kt_logs', 'done')) {
            Schema::table('kt_logs', function (Blueprint $table) {
                $table->longText('done')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('kt_logs', 'done')) {
            Schema::table('kt_logs', function (Blueprint $table) {
                $table->dropColumn('done');
            });
        }
    }
};
