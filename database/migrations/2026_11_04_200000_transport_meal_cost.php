<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The snack and drink of a ticket (user's rule, 2026-11-04): standard, about S/ 10 a
 * passenger at Turismo Cautivo; a cost in Profit by route (App\Services\TransportProfit).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'transport_meal_cost')) {
            Schema::table('settings', function (Blueprint $t) {
                $t->decimal('transport_meal_cost', 8, 2)->nullable(); // per ticket sold
            });
        }
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if ($cid) {
            DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->whereNull('transport_meal_cost')->update(['transport_meal_cost' => 10]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'transport_meal_cost')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('transport_meal_cost'));
        }
    }
};
