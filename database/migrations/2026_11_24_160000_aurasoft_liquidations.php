<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * AuraSoft's "Liquidación General" (user's yes, 2026-11-24): one row per day x point of sale x cashier (tickets, parcels,
 * excess, cards, web, other channels, cash expenses, net to deposit). Uploaded through the same Integración -> AuraSoft
 * button as the production report (the file says which one it is): transport_aurasoft_imports.kind = production |
 * liquidation; its rows in transport_aurasoft_liquidations with the same rule (every file kept, a row = date + point of
 * sale + cashier, the latest file's figures current, earlier ones kept). Dashboard tab "Ventas por punto de venta"
 * (transport_pointsales) added to Turismo's Manager list. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transport_aurasoft_imports', 'kind')) {
            Schema::table('transport_aurasoft_imports', function (Blueprint $t) {
                $t->string('kind', 20)->default('production')->after('company_id');
            });
        }
        if (! Schema::hasTable('transport_aurasoft_liquidations')) {
            Schema::create('transport_aurasoft_liquidations', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->unsignedBigInteger('import_id')->index();
                $t->unsignedInteger('row_number');
                $t->string('row_key', 160);                     // date|branch code|point code|cashier code
                $t->date('sale_date');
                $t->string('branch', 80)->nullable();           // SUCURSAL
                $t->string('point', 80)->nullable();            // PUNTO VENTA
                $t->string('point_code', 20)->nullable();
                $t->string('cashier', 80)->nullable();          // USUARIO
                $t->string('cashier_code', 20)->nullable();
                foreach (['tickets', 'parcels', 'excess', 'collected', 'income', 'card', 'web', 'channel', 'delivery', 'cash_out', 'expenses', 'net'] as $c) {
                    $t->decimal($c, 12, 2)->default(0);
                }
                $t->json('values');
                $t->string('action', 10);
                $t->boolean('is_current')->default(true);
                $t->timestamps();
                $t->index(['company_id', 'row_key', 'is_current'], 'tal_company_key_current');
                $t->index(['company_id', 'sale_date'], 'tal_company_date');
            });
        }

        // Turismo's Manager (Mayte): the new tab right after "Producción por bus".
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        $role = $cid ? DB::table('roles')->where('company_id', $cid)->where('name', 'Manager')->whereNull('deleted_at')->first() : null;
        $tabs = $role ? (json_decode((string) $role->dashboard_tabs, true) ?: []) : [];
        if ($tabs && ! in_array('transport_pointsales', $tabs, true)) {
            $at = array_search('transport_production', $tabs, true);
            array_splice($tabs, $at === false ? count($tabs) : $at + 1, 0, ['transport_pointsales']);
            DB::table('roles')->where('id', $role->id)->update(['dashboard_tabs' => json_encode(array_values($tabs)), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_aurasoft_liquidations');
        if (Schema::hasColumn('transport_aurasoft_imports', 'kind')) {
            Schema::table('transport_aurasoft_imports', fn (Blueprint $t) => $t->dropColumn('kind'));
        }
    }
};
