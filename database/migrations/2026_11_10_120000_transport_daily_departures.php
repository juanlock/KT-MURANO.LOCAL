<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Daily departures report (Turismo Cautivo, user's yes 2026-11-10): each agency's day is planned,
 * completed and closed in Murano instead of the client's Excel. Closing keeps the day's figures
 * (transport_day_closures), makes the PDF and emails it; a Manager can reopen it.
 * Permissions view / manage / reopen; the Supervisor role gets view + manage (TransportAgencies),
 * IT Admin and Manager all three. Menu item next to "Today's departures". Safe to run twice.
 */
return new class extends Migration
{
    private const PERMS = [
        'transport_departures_view' => 'Transport: see the daily departures report',
        'transport_departures_manage' => 'Transport: plan and close the agency\'s day',
        'transport_departures_reopen' => 'Transport: reopen a closed day',
    ];

    private const TEXTS = [
        'Transport_Daily' => ['Daily departures', 'Salidas diarias'],
        'transport_departures_view' => ['Transport: see the daily departures report', 'Transporte: ver el reporte de salidas diarias'],
        'transport_departures_manage' => ['Transport: plan and close the agency\'s day', 'Transporte: planificar y cerrar el día de la agencia'],
        'transport_departures_reopen' => ['Transport: reopen a closed day', 'Transporte: reabrir un día cerrado'],
        'Perm_Transport_View' => ['See trips, routes and crew', 'Ver viajes, rutas y tripulación'],
        'Perm_Transport_Manage' => ['Plan trips, routes and locations', 'Planificar viajes, rutas y lugares'],
        'Perm_Transport_Supervise' => ['Decide today\'s departures', 'Decidir las salidas de hoy'],
        'Perm_Transport_Parcels' => ['Register parcels', 'Registrar encomiendas'],
        'Perm_Transport_Tolls' => ['Register tolls', 'Registrar peajes'],
        'Perm_Transport_Profit' => ['See each trip\'s profit', 'Ver la ganancia de cada viaje'],
        'Perm_Transport_Boarding' => ['Board passengers', 'Embarcar pasajeros'],
        'Perm_Transport_Daily_View' => ['Daily departures: see', 'Salidas diarias: ver'],
        'Perm_Transport_Daily_Manage' => ['Daily departures: plan and close', 'Salidas diarias: planificar y cerrar'],
        'Perm_Transport_Daily_Reopen' => ['Daily departures: reopen a day', 'Salidas diarias: reabrir un día'],
    ];

    private const ITEM = '/app/transport/daily';

    public function up(): void
    {
        if (! Schema::hasTable('transport_day_closures')) {
            Schema::create('transport_day_closures', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('location_id'); // the agency
                $t->date('day');
                $t->string('status', 10)->default('closed'); // closed | open (reopened)
                $t->unsignedInteger('closed_by')->nullable();
                $t->dateTime('closed_at')->nullable();
                $t->json('totals')->nullable();
                $t->longText('rows')->nullable(); // the day's rows as closed (JSON)
                $t->string('pdf', 255)->nullable();
                $t->string('emailed_to', 1000)->nullable();
                $t->unsignedInteger('reopened_by')->nullable();
                $t->dateTime('reopened_at')->nullable();
                $t->string('reopen_note', 500)->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'location_id', 'day']);
            });
        }
        if (! Schema::hasColumn('settings', 'transport_daily_emails')) {
            Schema::table('settings', fn (Blueprint $t) => $t->string('transport_daily_emails', 1000)->nullable());
        }

        foreach (self::PERMS as $name => $label) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $grant = function (array $roleNames, array $perms) {
            $permIds = DB::table('permissions')->whereIn('name', $perms)->pluck('id');
            foreach (DB::table('roles')->whereIn('name', $roleNames)->whereNull('deleted_at')->pluck('id') as $rid) {
                foreach ($permIds as $pid) {
                    if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                        DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                    }
                }
            }
        };
        $grant(['IT Admin', 'Manager'], array_keys(self::PERMS));
        $grant([\App\Support\TransportAgencies::SUPERVISOR_ROLE], ['transport_departures_view', 'transport_departures_manage']);

        // Menu: next to "Today's departures", wherever each company has it.
        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', '/app/transport/today')->get(['company_id', 'parent_key', 'sort_order']) as $p) {
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
        // The SuperAdmin's role gets them from SuperAdminAccess::sync() after migrate.
    }

    public function down(): void
    {
    }
};
