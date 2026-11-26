<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Dashboard board "Programación" (user's request, 2026-11-26): the week's bus programming at a glance and programming by the
 * tickets already sold. transport_schedule_changes = what is decided for ONE date on top of the plan (base timetable or
 * season): add a departure (refuerzo), remove one, change its bus or time -- who, when and why. Jelaf's sold seats for the
 * next 14 days come with the nightly import (transport_boardings, future travel dates).
 * The tab "transport_programming" is added to every Turismo role that has its own tab list (user: "todos"). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_schedule_changes')) {
            Schema::create('transport_schedule_changes', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->date('day');
                $t->string('action', 10);                 // add | remove | change
                $t->unsignedBigInteger('slot_id')->nullable();   // the plan's departure removed / changed
                $t->string('side', 10)->nullable();       // lima | chiclayo
                $t->string('terminal', 40)->nullable();
                $t->time('time')->nullable();
                $t->string('tag', 20)->nullable();
                $t->unsignedInteger('vehicle_id')->nullable();
                $t->string('note', 250)->nullable();
                $t->unsignedInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'day']);
            });
        }

        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        foreach ($cid ? DB::table('roles')->where('company_id', $cid)->whereNull('deleted_at')->whereNotNull('dashboard_tabs')->get() : [] as $role) {
            $tabs = json_decode((string) $role->dashboard_tabs, true) ?: [];
            if (! $tabs || in_array('transport_programming', $tabs, true)) {
                continue;   // null / empty = every tab its permissions allow
            }
            $tabs[] = 'transport_programming';
            DB::table('roles')->where('id', $role->id)->update(['dashboard_tabs' => json_encode(array_values($tabs)), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_schedule_changes');
    }
};
