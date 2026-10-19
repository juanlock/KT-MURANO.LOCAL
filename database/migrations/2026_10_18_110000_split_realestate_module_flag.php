<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Business Modules: Real Estate gets its own switch (it used to follow the
 * Store / E-commerce one and couldn't be turned on / off alone). A company that
 * has the Store switched off keeps Real Estate off too, as it was; everyone
 * else keeps it on (a missing key = enabled). Only rows without the new key.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('settings')->whereNotNull('module_flags')->get(['id', 'module_flags']) as $s) {
            $flags = json_decode((string) $s->module_flags, true);
            if (! is_array($flags) || array_key_exists('realestate', $flags) || ($flags['Store'] ?? true) !== false) {
                continue;
            }
            $flags['realestate'] = false;
            DB::table('settings')->where('id', $s->id)->update(['module_flags' => json_encode($flags)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('settings')->whereNotNull('module_flags')->get(['id', 'module_flags']) as $s) {
            $flags = json_decode((string) $s->module_flags, true);
            if (is_array($flags) && array_key_exists('realestate', $flags)) {
                unset($flags['realestate']);
                DB::table('settings')->where('id', $s->id)->update(['module_flags' => json_encode($flags)]);
            }
        }
    }
};
