<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Real Estate module (properties, property_categories, property_inquiries) had
 * no company_id anywhere -- RealEstateController's index/store/update/destroy
 * methods for all three tables were company-blind. Follows the same pattern as
 * 2026_09_21_130100_add_company_id_to_core_business_tables.php: signed integer
 * (matches companies.id's signed int(11) -- a mismatched type fails the FK with
 * errno 150), default(1) directly in the schema (Murano's live data is tiny),
 * FK added in a second idempotent pass.
 *
 * Note: the public storefront (RealEstateStoreController) is intentionally left
 * untouched by this migration/task -- it reads via StoreSetting::firstOrFail()
 * as a single public store, not through the admin's company boundary. Whether
 * it should eventually be filtered to one company's listings is a product
 * decision outside this task's scope.
 */
return new class extends Migration
{
    private array $tables = ['properties', 'property_categories', 'property_inquiries'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->integer('company_id')->default(1)->after('id');
            });
        }

        foreach ($this->tables as $table) {
            $fk = $table.'_company_id_foreign';
            $exists = DB::selectOne("
                SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
            ", [$table, $fk]);
            if ($exists->c > 0) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('company_id')->references('id')->on('companies');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign($table.'_company_id_foreign');
                $blueprint->dropColumn('company_id');
            });
        }
    }
};
