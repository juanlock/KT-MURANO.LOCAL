<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task says what we seek and why in two fields (user, 2026-11-22): tasks.requirement ("Requerimiento / Qué buscamos")
 * and tasks.reason ("Por qué"). Descriptions already written as "Qué buscamos: ... Por qué: ..." are split into them;
 * anything after the reason's paragraph (e.g. a list of questions) stays with the requirement. The description column is
 * kept (other screens read it) and rebuilt from both on save. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'requirement')) {
            Schema::table('tasks', function (Blueprint $t) {
                $t->text('requirement')->nullable()->after('description');
                $t->text('reason')->nullable()->after('requirement');
            });
        }
        foreach (DB::table('tasks')->whereNull('requirement')->where('description', 'like', 'Qué buscamos:%')->get(['id', 'description']) as $t) {
            $d = str_replace("\r", '', $t->description);
            if (! preg_match('/^Qué buscamos:\s*(.*?)\n\s*\nPor qué:\s*(.*)$/su', $d, $m)) {
                continue;
            }
            $req = trim($m[1]);
            $parts = preg_split("/\n\s*\n/", trim($m[2]), 2);
            $why = trim($parts[0]);
            if (! empty($parts[1])) {
                $req .= "\n\n".trim($parts[1]);
            }
            DB::table('tasks')->where('id', $t->id)->update(['requirement' => $req, 'reason' => $why]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'requirement')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn(['requirement', 'reason']));
        }
    }
};
