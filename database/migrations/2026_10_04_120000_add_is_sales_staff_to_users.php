<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.is_sales_staff: who the Seller / Salesperson picker (sale form + POS
 * "Change Salesperson") offers. Set from the Employee form's "Sales Staff"
 * button. Before this the sale form listed every active user of the company
 * (customers included) and the POS every active non-customer user -- existing
 * staff users are flagged so the POS picker keeps exactly who it had.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_sales_staff')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_sales_staff')->default(false)->after('type');
            });
            DB::table('users')->whereNull('deleted_at')->where('statut', 1)
                ->whereNotIn('type', ['customer', 'Online_store', 'supplier'])
                ->update(['is_sales_staff' => 1]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'is_sales_staff')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_sales_staff');
            });
        }
    }
};
