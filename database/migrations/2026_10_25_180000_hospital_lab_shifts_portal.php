<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital phase 4 (user's analysis, 2026-10-25): lab, shift planning, patient portal.
 *  - lab_orders.admission_id: the stay an order belongs to (an inpatient's
 *    order is urgent by default); lab_order_items.critical_seen_at / _by: who
 *    acknowledged a critical value (the alert stays until then).
 *  - hms_lab_files: images / result files of an order (private CompanyFiles).
 *  - hms_shift_plans + hms_staff_shifts.plan_id: a roster generated for a
 *    month / semester from a model week with rotation (undo = delete the plan).
 *  - admissions.portal_status / portal_note: what the family sees in the portal
 *    (no clinical data); hms_discharge_plans.home_*: prescriptions and home care
 *    given at discharge; hms_care_videos: the company's home-care videos.
 *  - settings.hms_visit_hours; appointments.reminder_sent_at.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lab_orders', 'admission_id')) {
            Schema::table('lab_orders', fn (Blueprint $t) => $t->unsignedBigInteger('admission_id')->nullable()->index()->after('visit_id'));
        }
        if (! Schema::hasColumn('lab_order_items', 'critical_seen_at')) {
            Schema::table('lab_order_items', function (Blueprint $t) {
                $t->dateTime('critical_seen_at')->nullable()->after('remarks');
                $t->unsignedBigInteger('critical_seen_by')->nullable()->after('critical_seen_at');
            });
            // Critical values entered before this existed are not news any more.
            DB::table('lab_order_items')->where('flag', 'critical')->update(['critical_seen_at' => now()]);
        }
        if (! Schema::hasTable('hms_lab_files')) {
            Schema::create('hms_lab_files', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('lab_order_id')->index();
                $t->string('path', 255);
                $t->string('name', 191);
                $t->string('mime', 100)->nullable();
                $t->unsignedInteger('size')->default(0);
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('hms_shift_plans')) {
            Schema::create('hms_shift_plans', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('name', 120);
                $t->date('from_date');
                $t->date('to_date');
                $t->date('model_from');                       // the model week starts here (7 days)
                $t->unsignedTinyInteger('rotate_weeks')->default(0); // 0 = no rotation
                $t->unsignedInteger('rows_created')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('hms_staff_shifts', 'plan_id')) {
            Schema::table('hms_staff_shifts', fn (Blueprint $t) => $t->unsignedBigInteger('plan_id')->nullable()->index()->after('notes'));
        }

        if (! Schema::hasColumn('admissions', 'portal_status')) {
            Schema::table('admissions', function (Blueprint $t) {
                $t->string('portal_status', 20)->nullable()->after('left_at');   // stable, improving, observation, resting, preparing_discharge
                $t->string('portal_note', 255)->nullable()->after('portal_status');
                $t->dateTime('portal_status_at')->nullable()->after('portal_note');
            });
        }
        if (! Schema::hasColumn('hms_discharge_plans', 'home_prescriptions')) {
            Schema::table('hms_discharge_plans', function (Blueprint $t) {
                $t->text('home_prescriptions')->nullable()->after('items');
                $t->text('home_care')->nullable()->after('home_prescriptions');
                $t->json('video_ids')->nullable()->after('home_care');
                $t->dateTime('follow_up_at')->nullable()->after('video_ids');
            });
        }
        if (! Schema::hasTable('hms_care_videos')) {
            Schema::create('hms_care_videos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('title', 150);
                $t->string('url', 500);
                $t->string('topic', 80)->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('settings', 'hms_visit_hours')) {
            Schema::table('settings', fn (Blueprint $t) => $t->string('hms_visit_hours', 500)->nullable());
        }
        if (Schema::hasTable('appointments') && ! Schema::hasColumn('appointments', 'reminder_sent_at')) {
            Schema::table('appointments', fn (Blueprint $t) => $t->dateTime('reminder_sent_at')->nullable()->after('notes'));
        }

        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            foreach ([
                'Hms_shift_gaps' => ['Shifts not fully covered (next 7 days)', 'Turnos sin cobertura completa (próximos 7 días)'],
            ] as $key => [$en, $es]) {
                TranslationKeys::ensure($key, ['en' => $en, 'es' => $es]);
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->update(['en' => $en, 'es' => $es]);
            }
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hms_lab_files');
        Schema::dropIfExists('hms_shift_plans');
        Schema::dropIfExists('hms_care_videos');
        foreach ([
            'lab_orders' => ['admission_id'], 'lab_order_items' => ['critical_seen_at', 'critical_seen_by'],
            'hms_staff_shifts' => ['plan_id'], 'admissions' => ['portal_status', 'portal_note', 'portal_status_at'],
            'hms_discharge_plans' => ['home_prescriptions', 'home_care', 'video_ids', 'follow_up_at'],
            'settings' => ['hms_visit_hours'], 'appointments' => ['reminder_sent_at'],
        ] as $table => $cols) {
            $cols = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
            if ($cols) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($cols));
            }
        }
    }
};
