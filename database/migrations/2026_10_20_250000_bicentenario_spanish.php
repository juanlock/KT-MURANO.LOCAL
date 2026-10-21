<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * User's request, 2026-10-20: Colegio Bicentenario is a Spanish school (it was
 * created with AdminSystem's English pre-selected). By name, safe to run twice;
 * only while it is still English.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('companies')->where('name', 'Colegio Bicentenario')->whereNull('deleted_at')->value('id');
        if (! $id || ! DB::table('languages')->where('locale', 'es')->exists()) {
            return;
        }
        DB::table('settings')->where('company_id', $id)->whereNull('deleted_at')->where('default_language', 'en')
            ->update(['default_language' => 'es', 'updated_at' => now()]);
        Cache::forget('settings.default_language.'.$id);
    }

    public function down(): void
    {
    }
};
