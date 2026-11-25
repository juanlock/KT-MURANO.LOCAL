<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Two more AuraSoft files through the same upload button (user's yes, 2026-11-24):
 *  - "Liquidación Detallada" (kind detail): one row per document / cash movement -- module (PAS_ING tickets, VEN_ENC parcels,
 *    COB_DES collected at destination, ENC_PAG parcels to be paid at destination, EGR_CPA cash expenses, VEN_TAR / ENC_TAR /
 *    EXC_TAR cards...), the customer's DNI / RUC and name, bank and card, income / expense, status (ACTIVO / ANULADO) ->
 *    transport_aurasoft_movements. Only new and changed rows are stored (a row already held with the same figures is
 *    counted as "same"); a changed one keeps the earlier version (is_current 0).
 *  - "Kardex de encomiendas por entregar" (kind parcels): the parcels not delivered yet, one file per branch ->
 *    transport_aurasoft_parcels; the latest file of a branch is current (a snapshot), earlier ones kept.
 * Dashboard tabs "Caja y clientes" (transport_cashbook) and "Encomiendas AuraSoft" (transport_aura_parcels) added to
 * Turismo's Manager list after "Ventas por punto de venta". Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_aurasoft_movements')) {
            Schema::create('transport_aurasoft_movements', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->unsignedBigInteger('import_id')->index();
                $t->unsignedInteger('row_number');
                $t->string('row_key', 64);                      // sha1 of the row's identity (+ #n when repeated in a file)
                $t->date('sale_date');
                $t->string('branch', 80)->nullable();
                $t->string('point', 80)->nullable();
                $t->string('cashier', 80)->nullable();
                $t->string('module', 12);
                $t->string('doc_type', 4)->nullable();          // E electronic, B boleta, F factura, M manual
                $t->string('series', 10)->nullable();
                $t->string('number', 20)->nullable();
                $t->string('client_doc', 20)->nullable();       // DNI (8) / RUC (11)
                $t->string('client', 160)->nullable();
                $t->string('bank', 40)->nullable();
                $t->string('card_type', 40)->nullable();
                $t->string('card_number', 40)->nullable();
                $t->decimal('income', 12, 2)->default(0);
                $t->decimal('outflow', 12, 2)->default(0);
                $t->decimal('total', 12, 2)->default(0);
                $t->string('status', 12)->nullable();
                $t->string('action', 10);
                $t->boolean('is_current')->default(true);
                $t->timestamps();
                $t->index(['company_id', 'row_key', 'is_current'], 'tam_company_key_current');
                $t->index(['company_id', 'is_current', 'sale_date', 'module'], 'tam_company_date_module');
            });
        }
        if (! Schema::hasTable('transport_aurasoft_parcels')) {
            Schema::create('transport_aurasoft_parcels', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->unsignedBigInteger('import_id')->index();
                $t->unsignedInteger('row_number');
                $t->string('branch', 80)->nullable();           // SUCURSAL of the file
                $t->string('origin', 80)->nullable();           // the group line of the kardex (origin agency)
                $t->date('emitted_on')->nullable();
                $t->string('doc_type', 4)->nullable();
                $t->string('document', 30)->nullable();
                $t->string('consignee', 160)->nullable();
                $t->string('detail', 250)->nullable();
                $t->decimal('amount', 12, 2)->default(0);
                $t->string('pay', 6)->nullable();               // C.P: PP paid / PD to be paid at destination
                $t->string('sender', 160)->nullable();
                $t->string('point', 80)->nullable();
                $t->string('status', 40)->nullable();           // EN ALMACEN ORIGEN, EN DESTINO...
                $t->boolean('is_current')->default(true);
                $t->timestamps();
                $t->index(['company_id', 'is_current', 'branch'], 'tap_company_current');
            });
        }

        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        foreach ($cid ? DB::table('roles')->where('company_id', $cid)->whereIn('name', ['Manager', 'Director'])->whereNull('deleted_at')->get() : [] as $role) {
            $tabs = json_decode((string) $role->dashboard_tabs, true) ?: [];
            if (! $tabs) {
                continue;   // null = every tab its permissions allow
            }
            $changed = false;
            foreach (['transport_aura_parcels', 'transport_cashbook'] as $tab) {
                if (! in_array($tab, $tabs, true)) {
                    $at = array_search('transport_pointsales', $tabs, true);
                    array_splice($tabs, $at === false ? count($tabs) : $at + 1, 0, [$tab]);
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('roles')->where('id', $role->id)->update(['dashboard_tabs' => json_encode(array_values($tabs)), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_aurasoft_movements');
        Schema::dropIfExists('transport_aurasoft_parcels');
    }
};
