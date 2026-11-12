<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * "Mi agencia" (Turismo Cautivo, user's request 2026-11-11): the agency supervisor's summary, parcels, complaints and
 * incidents for their own agency. Permission transport_agency_view for the Supervisor, IT Admin and Manager roles;
 * menu item after "Daily departures", wherever each company has it. Safe to run twice.
 */
return new class extends Migration
{
    private const PERM = 'transport_agency_view';

    private const ITEM = '/app/transport/agency';

    private const TEXTS = [
        'Transport_MyAgency' => ['My agency', 'Mi agencia'],
        'transport_agency_view' => ['Transport: my agency (summary, parcels, complaints, incidents)', 'Transporte: mi agencia (resumen, encomiendas, reclamos, incidencias)'],
        'Perm_Transport_Agency' => ['My agency: summary, parcels, complaints and incidents', 'Mi agencia: resumen, encomiendas, reclamos e incidencias'],
    ];

    public function up(): void
    {
        if (! DB::table('permissions')->where('name', self::PERM)->exists()) {
            DB::table('permissions')->insert(['name' => self::PERM, 'label' => self::TEXTS[self::PERM][0], 'created_at' => now(), 'updated_at' => now()]);
        }
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        foreach (DB::table('roles')->whereIn('name', ['IT Admin', 'Manager', \App\Support\TransportAgencies::SUPERVISOR_ROLE])->whereNull('deleted_at')->pluck('id') as $rid) {
            if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }

        // The supervisor starts the day on "Mi agencia" (user's rule, 2026-11-11), not on Today's departures.
        DB::table('roles')->where('name', \App\Support\TransportAgencies::SUPERVISOR_ROLE)->where('home_path', '/transport/today')
            ->update(['home_path' => '/transport/agency', 'updated_at' => now()]);

        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', '/app/transport/daily')->get(['company_id', 'parent_key', 'sort_order']) as $p) {
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
        DB::table('roles')->where('name', \App\Support\TransportAgencies::SUPERVISOR_ROLE)->where('home_path', '/transport/agency')
            ->update(['home_path' => '/transport/today', 'updated_at' => now()]);
        DB::table('menu_positions')->where('item_key', self::ITEM)->delete();
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        if ($pid) {
            DB::table('permission_role')->where('permission_id', $pid)->delete();
            DB::table('permissions')->where('id', $pid)->delete();
        }
    }
};
