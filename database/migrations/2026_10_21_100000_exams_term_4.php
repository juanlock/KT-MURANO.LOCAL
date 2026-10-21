<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Four terms (bimestres, Peru; 2026-10-21): exams.term accepts term_4. Exams
 * saved before with an empty term and "IV bimestre" / "Term 4" in their name
 * get it. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exams') || ! Schema::hasColumn('exams', 'term')) {
            return;
        }
        $type = collect(DB::select('SHOW COLUMNS FROM exams'))->firstWhere('Field', 'term')->Type ?? '';
        if (str_starts_with($type, 'enum(') && ! str_contains($type, "'term_4'")) {
            DB::statement("ALTER TABLE exams MODIFY term ENUM('term_1','term_2','term_3','term_4','final','other') NOT NULL DEFAULT 'term_1'");
        }
        DB::table('exams')->where(fn ($q) => $q->where('term', '')->orWhereNull('term'))
            ->where(fn ($q) => $q->where('name', 'like', '%IV bimestre%')->orWhere('name', 'like', '%Term 4%'))
            ->update(['term' => 'term_4']);
    }

    public function down(): void
    {
    }
};
