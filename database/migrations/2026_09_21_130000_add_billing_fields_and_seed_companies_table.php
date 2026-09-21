<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Brings Murano's `companies` table up to parity with KT-Billing's shape
 * (slug/logo/logo_form/company_note/company_terms/company_signature) and
 * seeds it with Billing's actual company rows, same ids, so both apps agree
 * on which company_id means what. Murano's `companies` table was empty
 * before this -- multi-company was schema-present but unused.
 *
 * company 4 (HTD) and 999 (System/SuperAdmin sentinel) are copied as
 * soft-deleted, matching Billing -- they exist for id parity but stay
 * invisible to normal queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'slug')) {
                $table->string('slug')->nullable()->after('name');
            }
            if (!Schema::hasColumn('companies', 'logo')) {
                $table->string('logo', 200)->nullable()->after('country');
            }
            if (!Schema::hasColumn('companies', 'logo_form')) {
                $table->string('logo_form', 200)->nullable()->after('logo');
            }
            if (!Schema::hasColumn('companies', 'company_note')) {
                $table->string('company_note', 500)->nullable()->after('logo_form');
            }
            if (!Schema::hasColumn('companies', 'company_terms')) {
                $table->string('company_terms', 500)->nullable()->after('company_note');
            }
            if (!Schema::hasColumn('companies', 'company_signature')) {
                $table->string('company_signature', 500)->nullable()->after('company_terms');
            }
        });

        if (!Schema::hasIndex('companies', 'companies_slug_unique')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->unique('slug', 'companies_slug_unique');
            });
        }

        $rows = [
            ['id' => 1, 'name' => 'KcolTech', 'slug' => 'kcoltech', 'email' => 'info@kcoltech.com', 'phone' => '7325475393', 'country' => 'United States', 'created_at' => '2026-02-27 14:45:28', 'updated_at' => '2026-09-17 15:19:45', 'deleted_at' => null],
            ['id' => 2, 'name' => 'Ruba Garden LandScape', 'slug' => 'ruba-garden-landscape', 'email' => 'info@rubagardenlandscape.com', 'phone' => '(973)934-7606', 'country' => 'PO Box 52 Haskell, New Jersey, US - 07420', 'created_at' => '2026-02-27 14:46:02', 'updated_at' => '2026-09-17 15:19:45', 'deleted_at' => null],
            ['id' => 3, 'name' => 'NewEraUnisexSalon-', 'slug' => 'neweraunisexsalon', 'email' => 'info@neweraunisexsalon.com', 'phone' => '7325475393', 'country' => 'United States', 'created_at' => '2026-02-27 14:48:16', 'updated_at' => '2026-09-17 15:19:45', 'deleted_at' => null],
            ['id' => 4, 'name' => 'HTD', 'slug' => null, 'email' => 'infohtd@kcoltech.com', 'phone' => '7325475393', 'country' => 'USA', 'created_at' => '2026-09-18 10:39:33', 'updated_at' => '2026-09-18 11:07:22', 'deleted_at' => '2026-09-18 11:07:22'],
            ['id' => 999, 'name' => 'System (SuperAdmin)', 'slug' => 'system-superadmin', 'email' => null, 'phone' => null, 'country' => null, 'created_at' => '2026-09-18 14:59:26', 'updated_at' => '2026-09-18 14:59:26', 'deleted_at' => '2026-09-18 14:59:26'],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('companies')->where('id', $row['id'])->exists();
            if (!$exists) {
                DB::table('companies')->insert($row);
            }
        }
    }

    public function down(): void
    {
        DB::table('companies')->whereIn('id', [1, 2, 3, 4, 999])->delete();

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique('companies_slug_unique');
            $table->dropColumn(['slug', 'logo', 'logo_form', 'company_note', 'company_terms', 'company_signature']);
        });
    }
};
