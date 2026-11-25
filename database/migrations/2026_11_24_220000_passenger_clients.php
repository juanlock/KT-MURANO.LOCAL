<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Customers of type "Pasajero" (user's rule, 2026-11-24: "créalos sin acceso al portal.. para estos tipos de personas ponle
 * tipo Pasajero, así ya sabemos que no tienen portal"): clients.client_type -- null = a regular customer, 'passenger' =
 * a bus passenger / sender brought from AuraSoft, identified by DNI / RUC (tax_number) within the company, never a
 * portal login (LinkedUserService::ensureForClient and the portal admin refuse it). Dashboard tab "Clientes"
 * (transport_customers) added to Turismo's Manager / Director lists. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'client_type')) {
            Schema::table('clients', function (Blueprint $t) {
                $t->string('client_type', 20)->nullable()->after('company_id');
                $t->index(['company_id', 'client_type'], 'clients_company_type');
                $t->index(['company_id', 'tax_number'], 'clients_company_tax');
            });
        }
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        foreach ($cid ? DB::table('roles')->where('company_id', $cid)->whereIn('name', ['Manager', 'Director'])->whereNull('deleted_at')->get() : [] as $role) {
            $tabs = json_decode((string) $role->dashboard_tabs, true) ?: [];
            if (! $tabs || in_array('transport_customers', $tabs, true)) {
                continue;
            }
            $at = array_search('transport_cashbook', $tabs, true);
            array_splice($tabs, $at === false ? count($tabs) : $at + 1, 0, ['transport_customers']);
            DB::table('roles')->where('id', $role->id)->update(['dashboard_tabs' => json_encode(array_values($tabs)), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'client_type')) {
            Schema::table('clients', function (Blueprint $t) {
                $t->dropIndex('clients_company_type');
                $t->dropIndex('clients_company_tax');
                $t->dropColumn('client_type');
            });
        }
    }
};
