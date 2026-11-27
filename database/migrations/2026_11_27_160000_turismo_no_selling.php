<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Turismo Cautivo does not sell in Murano (the user, 2026-11-27: "pos de murano desactívalo, nosotros no vamos a vender --
 * pasajes ni encomiendas, solo haremos tableros"): tickets and parcels are sold in AuraSoft (Jelaf) and Murano only shows
 * boards built from its reports. Off: the POS and Sales modules, the header's POS button, the screen to register parcels
 * (and its menu group); the Agencia role no longer opens on the ticket office / boarding screen. Safe to run twice; down()
 * turns them back on.
 */
return new class extends Migration
{
    private function company(): int
    {
        return (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
    }

    private function apply(bool $sell): void
    {
        $cid = $this->company();
        if (! $cid) {
            return;
        }
        foreach (DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->get(['id', 'module_flags']) as $s) {
            $flags = json_decode((string) $s->module_flags, true) ?: [];
            $flags['pos'] = $sell;
            $flags['sales'] = $sell;
            DB::table('settings')->where('id', $s->id)->update(['module_flags' => json_encode($flags)]);
        }
        if (Schema::hasTable('pos_settings') && Schema::hasColumn('pos_settings', 'show_pos_button')) {
            DB::table('pos_settings')->where('company_id', $cid)->update(['show_pos_button' => $sell ? 1 : 0]);
        }
        DB::table('menu_positions')->where('company_id', $cid)
            ->where(fn ($q) => $q->where('item_key', '/app/transport/parcels')->orWhere('item_key', 'custom_tc_encomiendas'))
            ->update(['hidden' => $sell ? 0 : 1]);
        DB::table('roles')->where('company_id', $cid)->where('name', 'Agencia')->whereNull('deleted_at')
            ->update(['home_path' => $sell ? '/transport/stop' : null]);
    }

    public function up(): void
    {
        $this->apply(false);
    }

    public function down(): void
    {
        $this->apply(true);
    }
};
