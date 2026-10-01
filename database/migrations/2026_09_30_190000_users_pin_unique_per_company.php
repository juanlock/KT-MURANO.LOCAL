<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.pin: the cashier PIN that confirms who opens / closes a POS cash
 * register. Stored as the plain digits (the company hands them out and
 * admins see them on the user form) and unique per company -- two users of
 * the same company can never share one. NULLs don't collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A PIN a soft-deleted user still holds would block it forever.
        DB::table('users')->whereNotNull('deleted_at')->update(['pin' => null]);

        // Should an older duplicate exist inside one company, keep the first
        // user's PIN and clear the others (they get a new one on their form).
        $dupes = DB::table('users')->select('company_id', 'pin')->whereNotNull('pin')
            ->groupBy('company_id', 'pin')->havingRaw('COUNT(*) > 1')->get();
        foreach ($dupes as $d) {
            $keep = DB::table('users')->where('company_id', $d->company_id)->where('pin', $d->pin)->min('id');
            DB::table('users')->where('company_id', $d->company_id)->where('pin', $d->pin)->where('id', '<>', $keep)->update(['pin' => null]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('pin', 20)->nullable()->change();
            $table->unique(['company_id', 'pin'], 'users_company_pin_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_company_pin_unique');
        });
    }
};
