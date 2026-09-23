<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Brings `settings` into the per-company model. This table was a single
 * global row for the whole install (no company_id at all), which is the
 * root cause of the bug where every company's SPA boot read the same
 * default_language/branding regardless of which company was acting.
 *
 * company_id 1 (KcolTech) is a LIVE TENANT, not a neutral template, so the
 * existing row is re-homed to company_id 999 (System) and then cloned into
 * fresh rows for companies 1, 2, 3, 4 -- nothing changes for any company
 * immediately after this migration runs; only future edits diverge.
 *
 * companies.id is a plain (signed) int(11) -- company_id below matches that
 * exactly, same gotcha as the core-business-tables migration (mismatched
 * types fail the FK, errno 150).
 */
return new class extends Migration
{
    private string $table = 'settings';

    private array $companyIds = [1, 2, 3, 4];

    private const TEMPLATE_COMPANY_ID = 999;

    public function up(): void
    {
        if (! Schema::hasColumn($this->table, 'company_id')) {
            Schema::table($this->table, function (Blueprint $blueprint) {
                $blueprint->integer('company_id')->nullable()->after('id');
            });
        }

        DB::table($this->table)->whereNull('company_id')->update(['company_id' => self::TEMPLATE_COMPANY_ID]);

        $template = DB::table($this->table)->where('company_id', self::TEMPLATE_COMPANY_ID)->first();
        if ($template) {
            $templateAttrs = (array) $template;
            unset($templateAttrs['id'], $templateAttrs['company_id'], $templateAttrs['created_at'], $templateAttrs['updated_at']);
            if (array_key_exists('deleted_at', $templateAttrs)) {
                $templateAttrs['deleted_at'] = null;
            }

            foreach ($this->companyIds as $companyId) {
                if (DB::table($this->table)->where('company_id', $companyId)->exists()) {
                    continue;
                }
                DB::table($this->table)->insert($templateAttrs + [
                    'company_id' => $companyId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $uniqueExists = DB::selectOne("
            SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
        ", [$this->table, $this->table.'_company_id_unique']);
        if ($uniqueExists->c == 0) {
            Schema::table($this->table, function (Blueprint $blueprint) {
                $blueprint->unique('company_id', $this->table.'_company_id_unique');
            });
        }

        $fk = $this->table.'_company_id_foreign';
        $fkExists = DB::selectOne("
            SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
        ", [$this->table, $fk]);
        if ($fkExists->c == 0) {
            Schema::table($this->table, function (Blueprint $blueprint) {
                $blueprint->foreign('company_id')->references('id')->on('companies');
            });
        }
    }

    public function down(): void
    {
        Schema::table($this->table, function (Blueprint $blueprint) {
            $blueprint->dropForeign($this->table.'_company_id_foreign');
            $blueprint->dropUnique($this->table.'_company_id_unique');
        });

        DB::table($this->table)->whereIn('company_id', $this->companyIds)->delete();

        Schema::table($this->table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('company_id');
        });
    }
};
