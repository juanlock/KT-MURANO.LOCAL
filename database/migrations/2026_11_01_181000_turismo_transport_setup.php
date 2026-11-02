<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo (user's request, 2026-11-01): Fleet, Transport, Suppliers and HRM on;
 * its locations -- the 4 terminals of turismocautivo.com's origin / destination lists
 * and the agencies of its complaints book --, the routes between the terminals, and the
 * complaints book asks the trip's origin and destination (so the dashboard shows which
 * routes have complaints). Every company's Admin Compañía role gets the Transport
 * permissions (the module switch decides who sees it). By name, safe to run twice.
 */
return new class extends Migration
{
    private const TERMINALS = ['LIMA', 'CHICLAYO', 'CHEPEN', 'TRUJILLO'];

    private const ONLINE = ['WEBSITE', 'QUIOSCO WEB', 'REDBUS'];

    public function up(): void
    {
        $permIds = DB::table('permissions')->whereIn('name', ['transport_view', 'transport_manage'])->pluck('id');
        foreach (DB::table('roles')->where('name', 'Admin Compañía')->whereNull('deleted_at')->pluck('id') as $rid) {
            foreach ($permIds as $pid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }

        $cid = DB::table('companies')->where('name', 'Turismo Cautivo')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $now = now();

        // Modules.
        foreach (DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->get(['id', 'module_flags']) as $s) {
            $flags = json_decode((string) $s->module_flags, true) ?: [];
            foreach (['fleet', 'transport', 'suppliers', 'hrm'] as $m) {
                $flags[$m] = true;
            }
            DB::table('settings')->where('id', $s->id)->update(['module_flags' => json_encode($flags)]);
        }

        // Locations: the terminals, then the complaints book's agencies.
        $form = DB::table('forms')->where('company_id', $cid)->whereNull('deleted_at')->where('name', 'like', '%Reclamos%')->first();
        $fields = $form ? (json_decode((string) $form->fields, true) ?: []) : [];
        $agencyField = collect($fields)->first(fn ($f) => in_array($f['type'] ?? '', ['select', 'radio'], true) && preg_match('/agencia/i', (string) ($f['label'] ?? '')));
        $names = array_merge(self::TERMINALS, $agencyField['options'] ?? []);
        foreach (array_unique(array_map(fn ($n) => mb_strtoupper(trim($n)), $names)) as $name) {
            if ($name === '' || DB::table('transport_locations')->where('company_id', $cid)->where('name', $name)->whereNull('deleted_at')->exists()) {
                continue;
            }
            $kind = in_array($name, self::TERMINALS, true) ? 'terminal' : (in_array($name, self::ONLINE, true) ? 'online' : 'agency');
            DB::table('transport_locations')->insert(['company_id' => $cid, 'name' => $name, 'city' => in_array($name, self::TERMINALS, true) ? ucfirst(mb_strtolower($name)) : null,
                'kind' => $kind, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Routes: every terminal to every other one (the website's origin x destination).
        $ids = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->whereIn('name', self::TERMINALS)->pluck('id', 'name');
        foreach ($ids as $o => $oid) {
            foreach ($ids as $d => $did) {
                if ($oid !== $did && ! DB::table('transport_routes')->where('company_id', $cid)->where('origin_id', $oid)->where('destination_id', $did)->whereNull('deleted_at')->exists()) {
                    DB::table('transport_routes')->insert(['company_id' => $cid, 'origin_id' => $oid, 'destination_id' => $did, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        // The complaints book asks the trip's origin and destination, right after the agency.
        if ($form && ! collect($fields)->contains(fn ($f) => ($f['key'] ?? '') === 'origen')) {
            $new = [];
            $mk = fn ($key, $label) => ['key' => $key, 'type' => 'select', 'label' => $label, 'width' => 'half', 'required' => false,
                'placeholder' => '', 'help' => '', 'default' => '', 'options' => self::TERMINALS, 'multiple' => false];
            $added = false;
            foreach ($fields as $f) {
                $new[] = $f;
                if (! $added && $agencyField && ($f['key'] ?? '') === $agencyField['key']) {
                    $new[] = $mk('origen', 'Origen del viaje:');
                    $new[] = $mk('destino', 'Destino del viaje:');
                    $added = true;
                }
            }
            if (! $added) {
                $new[] = $mk('origen', 'Origen del viaje:');
                $new[] = $mk('destino', 'Destino del viaje:');
            }
            DB::table('forms')->where('id', $form->id)->update(['fields' => json_encode($new, JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
    }
};
