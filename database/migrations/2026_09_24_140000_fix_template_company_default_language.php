<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reserved template company's (999) Setting row had default_language =
 * 'es' -- since app\Http\Middleware\SetLocale.php resolves an unauthenticated
 * guest's locale via Setting::forCompany(null), which defaults to this exact
 * template row, every visitor landed on the login page in Spanish regardless
 * of their browser/no prior choice. One-time correction to 'en'; now also
 * editable going forward from the new Login Page Settings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('company_id', 999)
            ->where('default_language', 'es')
            ->update(['default_language' => 'en']);
    }

    public function down(): void
    {
        // Not reversible -- the prior value was an unintentional default, not
        // a deliberate setting worth restoring.
    }
};
