<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Hospital: Emergency Room and Pharmacy (user's request, 2026-10-21).
 *  - er_cases: one row per emergency arrival, from intake (triage, vitals) to
 *    disposition (discharged / admitted / transferred / left without being seen).
 *  - hospital_wards.type gets 'emergency' (the ER's own beds).
 *  - permissions hms_er / hms_pharmacy, given to every role that has
 *    hms_dashboard (by name, never by id).
 *  - the sidebar: "Emergency Room" right after the hospital Dashboard and
 *    "Pharmacy" right after Wards & Beds, in every company's saved menu.
 *  - Spanish for the menu labels / page titles.
 * Safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        'Emergency Room' => 'Emergencias',
        'Pharmacy' => 'Farmacia',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('er_cases')) {
            Schema::create('er_cases', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->nullable()->index();
                $t->string('reference', 32);
                $t->unsignedBigInteger('patient_id')->index();
                $t->unsignedBigInteger('doctor_id')->nullable();
                $t->unsignedBigInteger('bed_id')->nullable();
                $t->unsignedBigInteger('admission_id')->nullable();
                $t->dateTime('arrived_at');
                $t->string('arrival_mode', 20)->default('walk_in');
                $t->unsignedTinyInteger('triage_level')->nullable();
                $t->text('complaint')->nullable();
                $t->decimal('temperature', 5, 2)->nullable();
                $t->unsignedSmallInteger('pulse')->nullable();
                $t->unsignedSmallInteger('bp_systolic')->nullable();
                $t->unsignedSmallInteger('bp_diastolic')->nullable();
                $t->unsignedSmallInteger('respiratory_rate')->nullable();
                $t->unsignedSmallInteger('spo2')->nullable();
                $t->unsignedTinyInteger('pain_score')->nullable();
                $t->string('status', 20)->default('waiting')->index();
                $t->dateTime('treatment_started_at')->nullable();
                $t->text('diagnosis')->nullable();
                $t->text('notes')->nullable();
                $t->string('disposition', 20)->nullable();
                $t->dateTime('closed_at')->nullable();
                $t->text('disposition_notes')->nullable();
                $t->integer('created_by')->nullable();
                $t->timestamps(6);
                $t->softDeletes();
            });
        }

        if (Schema::hasTable('hospital_wards')) {
            DB::statement("ALTER TABLE hospital_wards MODIFY type ENUM('general','private','semi_private','icu','nicu','maternity','isolation','emergency') NOT NULL DEFAULT 'general'");
        }

        $this->permissions();
        $this->menu();
        $this->translations();
    }

    private function permissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $base = DB::table('permissions')->where('name', 'hms_dashboard')->value('id');
        foreach (['hms_er' => 'Emergency Room', 'hms_pharmacy' => 'Pharmacy'] as $name => $label) {
            $pid = DB::table('permissions')->where('name', $name)->value('id');
            if (! $pid) {
                $pid = DB::table('permissions')->insertGetId(['name' => $name, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
            if (! $base) {
                continue;
            }
            foreach (DB::table('permission_role')->where('permission_id', $base)->pluck('role_id') as $rid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }
    }

    /** New entry right after $after (same parent), for every company with a saved layout. */
    private function menu(): void
    {
        if (! Schema::hasTable('menu_positions')) {
            return;
        }
        foreach ([['/app/hospital/er', '/app/hospital/dashboard'], ['/app/hospital/pharmacy', '/app/hospital/wards']] as [$key, $after]) {
            foreach (DB::table('menu_positions')->where('item_key', $after)->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
                $cid = $ref->company_id;
                if (DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->exists()) {
                    continue;
                }
                $sameParent = fn ($q) => $ref->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $ref->parent_key);
                DB::table('menu_positions')->where('company_id', $cid)->where($sameParent)
                    ->where('sort_order', '>', $ref->sort_order)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $cid, 'item_key' => $key, 'parent_key' => $ref->parent_key, 'sort_order' => $ref->sort_order + 1,
                    'external' => 0, 'is_custom' => 0, 'hidden' => (int) $ref->hidden, 'location' => $ref->location ?: 'sidebar',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function translations(): void
    {
        if (! Schema::hasTable('translations') || ! Schema::hasColumn('translations', 'es')) {
            return;
        }
        foreach (self::TEXTS as $key => $es) {
            TranslationKeys::ensure($key, ['es' => $es]);
            $row = DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->first();
            if ($row && trim((string) $row->es) === trim((string) ($row->en ?: $key))) {
                DB::table('translations')->where('id', $row->id)->update(['es' => $es, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('er_cases');
    }
};
