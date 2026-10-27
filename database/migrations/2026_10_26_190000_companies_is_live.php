<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Companies "in production" (user's rule, 2026-10-26): production owns their data and the
 * dev -> production push (database/scripts/protocol_prod_push.php) never sends anything of
 * theirs. Every other company's dev data may go up. Starts with Ruba (sales), New Era
 * (appointments) and Turismo Cautivo (forms) -- by slug / name, never by id; afterwards
 * superadmin/AdminSystem switches it in Companies -> Edit -> Website.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'is_live')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('is_live')->default(false)->after('business_type');
            });
        }
        DB::table('companies')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('slug', ['ruba-garden-landscape', 'neweraunisexsalon'])->orWhere('name', 'Turismo Cautivo'))
            ->update(['is_live' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'is_live')) {
            Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('is_live'));
        }
    }
};
