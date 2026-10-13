<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Companies -> Add / Edit: the owner's name (App\Support\CompanyProfile, 2026-10-12). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'owner_name')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->string('owner_name', 190)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'owner_name')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropColumn('owner_name');
            });
        }
    }
};
