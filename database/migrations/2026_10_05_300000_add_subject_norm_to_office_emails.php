<?php

use App\Services\EmailOffice\EmailOffice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * office_emails.subject_norm: the subject without "Re: / Fwd: ..." prefixes,
 * lower case (EmailOffice::normSubject) -- the Email Office "Subject" filter
 * groups and filters by it, so an email and its replies are one subject.
 * Filled for the existing emails; new ones get it when recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_emails')) {
            return;
        }
        if (! Schema::hasColumn('office_emails', 'subject_norm')) {
            Schema::table('office_emails', function (Blueprint $table) {
                $table->string('subject_norm', 255)->nullable()->after('subject');
                $table->index(['company_id', 'subject_norm']);
            });
        }
        DB::table('office_emails')->whereNull('subject_norm')->orderBy('id')->select(['id', 'subject'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $r) {
                    DB::table('office_emails')->where('id', $r->id)->update(['subject_norm' => EmailOffice::normSubject($r->subject)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('office_emails', 'subject_norm')) {
            Schema::table('office_emails', function (Blueprint $table) {
                $table->dropIndex(['company_id', 'subject_norm']);
                $table->dropColumn('subject_norm');
            });
        }
    }
};
