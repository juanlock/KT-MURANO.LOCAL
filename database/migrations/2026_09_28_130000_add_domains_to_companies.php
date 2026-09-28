<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each company's own website domain(s) (comma-separated, "www." optional):
 * when a request arrives on one of them, Murano serves that company's
 * website -- its Store -> Pages "Home" page at "/", and its own pages under
 * the store's /pages/{slug} (App\Support\CompanyDomain).
 *
 * Seeds the domains given on 2026-09-28: kcoltech.com -> company 1,
 * rubagardenlandscape.com -> company 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'domains')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('domains', 255)->nullable()->after('country');
            });
        }

        foreach ([1 => 'kcoltech.com', 2 => 'rubagardenlandscape.com'] as $id => $domain) {
            DB::table('companies')->where('id', $id)->whereNull('domains')->update(['domains' => $domain]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'domains')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('domains');
            });
        }
    }
};
