<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Forms -> Submissions (2026-11-01): "Attended" with who, when and a note. Safe to run twice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $t) {
            if (! Schema::hasColumn('form_submissions', 'attended_at')) {
                $t->timestamp('attended_at')->nullable()->after('is_read');
            }
            if (! Schema::hasColumn('form_submissions', 'attended_by')) {
                $t->unsignedInteger('attended_by')->nullable()->after('attended_at');
            }
            if (! Schema::hasColumn('form_submissions', 'attended_note')) {
                $t->text('attended_note')->nullable()->after('attended_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $t) {
            foreach (['attended_note', 'attended_by', 'attended_at'] as $c) {
                if (Schema::hasColumn('form_submissions', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
