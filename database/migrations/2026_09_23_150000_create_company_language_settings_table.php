<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Per-company override of a language's enabled/disabled state. The
 * `languages` table itself stays the global catalog (which locales are
 * installed at all -- name/locale/flag), but `is_active` used to be a
 * single global column, so enabling/disabling a language from ANY
 * company's Languages page affected every company identically -- the
 * exact bug default_language had before Setting::forCompany() (see
 * 2026_09_23_120000_add_company_id_to_settings_table.php).
 *
 * Same template pattern as that fix: company 999 (System -- the
 * SuperAdmin's own company_id, so "SuperAdmin acting with no company
 * selected" naturally edits this row) is the fallback a company with no
 * explicit override inherits from. LanguageController resolves the
 * effective is_active per (company, language) as: this company's row, else
 * company 999's row, else the language's own legacy is_active column.
 *
 * companies.id is a plain signed int(11) -- company_id below matches that
 * (mismatched types fail the FK, errno 150, same gotcha noted in every
 * other company_id migration this session).
 */
return new class extends Migration
{
    private const TEMPLATE_COMPANY_ID = 999;

    public function up(): void
    {
        Schema::create('company_language_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('language_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'language_id']);
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('language_id')->references('id')->on('languages')->onDelete('cascade');
        });

        // Backfill: every existing company (999 System template + every
        // real company today) starts from the CURRENT global is_active
        // values, so nothing visibly changes for anyone immediately after
        // this migration -- only future per-company edits diverge.
        $companyIds = DB::table('companies')->pluck('id')->push(self::TEMPLATE_COMPANY_ID)->unique();
        $languages = DB::table('languages')->get(['id', 'is_active']);
        $now = now();

        $rows = [];
        foreach ($companyIds as $companyId) {
            foreach ($languages as $language) {
                $rows[] = [
                    'company_id' => $companyId,
                    'language_id' => $language->id,
                    'is_active' => (bool) $language->is_active,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if ($rows) {
            DB::table('company_language_settings')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_language_settings');
    }
};
