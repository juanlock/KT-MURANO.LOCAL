<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * User's rules, 2026-10-21 (safe to run twice):
 *  1. Documents (archive) and Accounting budgets / tax reports separated by
 *     company, like School and Hospital: company_id on documents,
 *     document_folders, document_versions, acc_budgets, acc_budget_lines,
 *     acc_tax_reports (models use BelongsToActingCompany). Filled from
 *     demo_records, then the uploader / creator's company, lines and versions
 *     from their parent. Anything still unknown stays empty (SuperAdmin only).
 *  2. Documents is a base module: switched ON for every company (AdminSystem
 *     999 not touched); new companies get it from BusinessTypes::BASE_MODULES.
 */
return new class extends Migration
{
    private const TABLES = ['documents', 'document_folders', 'document_versions', 'acc_budgets', 'acc_budget_lines', 'acc_tax_reports'];

    public function up(): void
    {
        $tables = array_values(array_filter(self::TABLES, fn ($t) => Schema::hasTable($t)));
        foreach ($tables as $t) {
            if (! Schema::hasColumn($t, 'company_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedInteger('company_id')->nullable()->after('id')->index();
                });
            }
        }

        // 1. Demo rows -> their company.
        if (Schema::hasTable('demo_records')) {
            foreach ($tables as $t) {
                foreach (DB::table('demo_records')->where('record_type', $t)->select('company_id', 'record_id')->get()->groupBy('company_id') as $cid => $rows) {
                    foreach ($rows->pluck('record_id')->chunk(500) as $ids) {
                        DB::table($t)->whereIn('id', $ids->all())->whereNull('company_id')->update(['company_id' => (int) $cid]);
                    }
                }
            }
        }
        // 2. The person who made them.
        foreach ([['documents', 'uploaded_by'], ['document_folders', 'created_by'], ['document_versions', 'uploaded_by']] as [$t, $col]) {
            if (in_array($t, $tables, true) && Schema::hasColumn($t, $col)) {
                DB::statement("UPDATE `$t` x JOIN `users` u ON u.id = x.`$col` SET x.company_id = u.company_id WHERE x.company_id IS NULL AND u.company_id IS NOT NULL");
            }
        }
        // 3. Children follow their parent.
        foreach ([['document_versions', 'document_id', 'documents'], ['acc_budget_lines', 'budget_id', 'acc_budgets'], ['documents', 'folder_id', 'document_folders']] as [$t, $col, $parent]) {
            if (in_array($t, $tables, true) && in_array($parent, $tables, true)) {
                DB::statement("UPDATE `$t` c JOIN `$parent` p ON p.id = c.`$col` SET c.company_id = p.company_id WHERE c.company_id IS NULL AND p.company_id IS NOT NULL");
            }
        }

        // Documents module ON for every company.
        $template = \App\Models\Setting::TEMPLATE_COMPANY_ID;
        foreach (DB::table('settings')->whereNull('deleted_at')->where('company_id', '!=', $template)->get(['id', 'module_flags']) as $row) {
            $flags = json_decode((string) $row->module_flags, true) ?: [];
            if (($flags['documents'] ?? null) !== true) {
                $flags['documents'] = true;
                DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'company_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropIndex(['company_id']);
                    $table->dropColumn('company_id');
                });
            }
        }
    }
};
