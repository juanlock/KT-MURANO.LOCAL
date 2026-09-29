<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mail Settings (servers) and Email Templates (email_messages) were both
 * completely unscoped -- one global SMTP server and one global set of email
 * templates shared by every company, with real cross-company data leakage/
 * collision risk (any admin editing "their" mail settings was silently
 * editing everyone's).
 *
 * servers: gets a real company_id (default 1 -- the existing single row's
 * real KcolTech credentials clearly belong to company 1, confirmed by its
 * sender_name). No "999 template" row for servers -- SMTP credentials are
 * secrets; a brand-new company should start blank, not inherit another
 * company's real password (see Server::forCompany()).
 *
 * email_messages: existing 168 rows become the shared TEMPLATE pool
 * (company_id = Setting::TEMPLATE_COMPANY_ID = 999), which every company
 * falls back to until it saves its own customized copy (see
 * EmailMessage::forCompanyLocale()) -- mirrors Setting::forCompany()'s own
 * clone/fallback convention, appropriate here since template *content*
 * (unlike an SMTP password) is safe and useful to share as a starting point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'company_id')) {
                $table->unsignedBigInteger('company_id')->default(1)->after('id');
            }
        });
        DB::table('servers')->whereNull('company_id')->orWhere('company_id', 0)->update(['company_id' => 1]);

        Schema::table('email_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('email_messages', 'company_id')) {
                $table->unsignedBigInteger('company_id')->default(999)->after('id');
            }
        });
        DB::table('email_messages')->update(['company_id' => 999]);
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'company_id')) {
                $table->dropColumn('company_id');
            }
        });
        Schema::table('email_messages', function (Blueprint $table) {
            if (Schema::hasColumn('email_messages', 'company_id')) {
                $table->dropColumn('company_id');
            }
        });
    }
};
