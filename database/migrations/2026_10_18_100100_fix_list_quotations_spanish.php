<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales menu showed "List Quotations" in Spanish: the ListQuotations key had
 * the English text in its `es` column. Only when it still has that value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('translations')->where('key', 'ListQuotations')->where('es', 'List Quotations')
            ->update(['es' => 'Lista de cotizaciones', 'updated_at' => now()]);
        forget_db_translations();
    }

    public function down(): void
    {
        // the wrong value isn't put back
    }
};
