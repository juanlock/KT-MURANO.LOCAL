<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Forms: answers saved as progress (user's request, 2026-11-11). With the form's "save_draft" setting a visitor can press
 * "Guardar" (status draft, nobody notified) and later "Guardar y enviar" (status sent, notified); both update the same
 * submission, found by the token their browser keeps (resume_token). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $t) {
            if (! Schema::hasColumn('form_submissions', 'status')) {
                $t->string('status', 10)->default('sent')->after('data');
            }
            if (! Schema::hasColumn('form_submissions', 'resume_token')) {
                $t->string('resume_token', 64)->nullable()->after('status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $t) {
            if (Schema::hasColumn('form_submissions', 'resume_token')) {
                $t->dropIndex(['resume_token']);
                $t->dropColumn('resume_token');
            }
            if (Schema::hasColumn('form_submissions', 'status')) {
                $t->dropColumn('status');
            }
        });
    }
};
