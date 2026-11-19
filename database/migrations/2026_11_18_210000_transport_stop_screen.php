<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "Mi parada" (Turismo Cautivo, 2026-11-18): the work screen of the one person of a pass-through agency (Chepén, Trujillo).
 *  - transport_tickets.no_show_at / no_show_by: the passenger did not show up at their boarding stop; the seat is free again
 *    for the whole of their stretch (TransportLegs::takenSeats ignores it). alighted_at / alighted_by: got off here.
 *  - permission transport_stop + page /transport/stop, for "Agente de agencia" (its home page), "Operaciones", IT Admin, Manager.
 * Safe to run twice; by name.
 */
return new class extends Migration
{
    private const PERM = 'transport_stop';

    private const ITEM = '/app/transport/stop';

    private const TEXTS = [
        'Transport_Stop' => ['My stop', 'Mi parada'],
        'transport_stop' => ['Transport: my stop (the buses passing through the agency: passengers, parcels, last-minute sales)', 'Transporte: mi parada (los buses que pasan por la agencia: pasajeros, encomiendas, venta de última hora)'],
        'Perm_Transport_Stop' => ['My stop: receive and dispatch the buses passing through the agency', 'Mi parada: recibir y despachar los buses que pasan por la agencia'],
    ];

    public function up(): void
    {
        Schema::table('transport_tickets', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_tickets', 'no_show_at')) {
                $t->dateTime('no_show_at')->nullable()->after('boarded_by');
                $t->unsignedInteger('no_show_by')->nullable()->after('no_show_at');
            }
            if (! Schema::hasColumn('transport_tickets', 'alighted_at')) {
                $t->dateTime('alighted_at')->nullable()->after('no_show_by');
                $t->unsignedInteger('alighted_by')->nullable()->after('alighted_at');
            }
        });

        if (! DB::table('permissions')->where('name', self::PERM)->exists()) {
            DB::table('permissions')->insert(['name' => self::PERM, 'label' => self::TEXTS[self::PERM][0], 'created_at' => now(), 'updated_at' => now()]);
        }
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        foreach (DB::table('roles')->whereIn('name', ['Agente de agencia', 'Operaciones', 'IT Admin', 'Manager'])->whereNull('deleted_at')->pluck('id') as $rid) {
            if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
        DB::table('roles')->where('name', 'Agente de agencia')->whereNull('deleted_at')->update(['home_path' => '/transport/stop', 'updated_at' => now()]);

        // Menu: right after Boarding, wherever each company has it.
        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', '/app/transport/boarding')->get(['company_id', 'parent_key', 'sort_order']) as $p) {
            if (DB::table('menu_positions')->where('company_id', $p->company_id)->where('item_key', self::ITEM)->exists()) {
                continue;
            }
            DB::table('menu_positions')->where('company_id', $p->company_id)->where('parent_key', $p->parent_key)->where('sort_order', '>', $p->sort_order)->increment('sort_order');
            DB::table('menu_positions')->insert([
                'company_id' => $p->company_id, 'item_key' => self::ITEM, 'parent_key' => $p->parent_key, 'sort_order' => (int) $p->sort_order + 1,
                'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        DB::table('menu_positions')->where('item_key', self::ITEM)->delete();
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        if ($pid) {
            DB::table('permission_role')->where('permission_id', $pid)->delete();
            DB::table('permissions')->where('id', $pid)->delete();
        }
        DB::table('roles')->where('name', 'Agente de agencia')->where('home_path', '/transport/stop')->update(['home_path' => '/pos']);
        Schema::table('transport_tickets', function (Blueprint $t) {
            foreach (['no_show_at', 'no_show_by', 'alighted_at', 'alighted_by'] as $c) {
                if (Schema::hasColumn('transport_tickets', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
