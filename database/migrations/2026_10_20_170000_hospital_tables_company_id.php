<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Hospital Management multicompany (2026-10-20): only `patients` had a
 * company; doctors, departments, wards, beds, lab tests, appointments,
 * consultations, prescriptions, admissions, lab orders, invoices and payments
 * were shared by every company. Adds company_id to each (the models filter by
 * the acting company: App\Models\Concerns\BelongsToActingCompany) and fills it:
 *  1. rows tagged in demo_records -> that demo's company;
 *  2. rows of a patient -> the patient's company;
 *  3. doctors / departments / wards / beds / lab tests -> the company of the
 *     rows that use them;
 * anything still unknown stays empty (only the SuperAdmin sees it).
 * Child lines (prescription / lab order / invoice items) follow their parent.
 * Safe to run twice.
 */
return new class extends Migration
{
    private const TABLES = [
        'doctors', 'hospital_departments', 'hospital_wards', 'hospital_beds', 'lab_tests',
        'appointments', 'patient_visits', 'prescriptions', 'admissions', 'lab_orders', 'hospital_invoices', 'hospital_payments',
    ];

    /** Rows that belong to a patient. */
    private const BY_PATIENT = ['appointments', 'patient_visits', 'prescriptions', 'admissions', 'lab_orders', 'hospital_invoices', 'hospital_payments'];

    public function up(): void
    {
        $tables = array_values(array_filter(self::TABLES, fn ($t) => Schema::hasTable($t)));
        foreach ($tables as $t) {
            if (! Schema::hasColumn($t, 'company_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedInteger('company_id')->nullable()->after('id')->index();
                });
            }
        }

        // 1. Demo rows.
        if (Schema::hasTable('demo_records')) {
            foreach ($tables as $t) {
                foreach (DB::table('demo_records')->where('record_type', $t)->select('company_id', 'record_id')->get()->groupBy('company_id') as $cid => $rows) {
                    foreach ($rows->pluck('record_id')->chunk(500) as $ids) {
                        DB::table($t)->whereIn('id', $ids->all())->whereNull('company_id')->update(['company_id' => (int) $cid]);
                    }
                }
            }
        }

        // 2. A patient's rows.
        if (Schema::hasColumn('patients', 'company_id')) {
            foreach (array_intersect(self::BY_PATIENT, $tables) as $t) {
                if (Schema::hasColumn($t, 'patient_id')) {
                    DB::statement("UPDATE `$t` x JOIN `patients` p ON p.id = x.patient_id SET x.company_id = p.company_id WHERE x.company_id IS NULL AND p.company_id IS NOT NULL");
                }
            }
        }

        // 3. Masters, from the rows that use them (several passes: beds follow wards, wards departments...).
        $uses = [
            'doctors' => [['appointments', 'doctor_id'], ['patient_visits', 'doctor_id'], ['admissions', 'doctor_id'], ['lab_orders', 'doctor_id'], ['prescriptions', 'doctor_id']],
            'hospital_departments' => [['doctors', 'department_id'], ['appointments', 'department_id'], ['patient_visits', 'department_id'], ['admissions', 'department_id'], ['hospital_wards', 'department_id']],
            'hospital_wards' => [['hospital_beds', 'ward_id'], ['admissions', 'ward_id']],
            'hospital_beds' => [['admissions', 'bed_id']],
        ];
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($uses as $master => $refs) {
                if (! in_array($master, $tables, true)) {
                    continue;
                }
                foreach ($refs as [$table, $col]) {
                    if (in_array($table, $tables, true) && Schema::hasColumn($table, $col)) {
                        DB::statement("UPDATE `$master` m JOIN `$table` u ON u.`$col` = m.id SET m.company_id = u.company_id WHERE m.company_id IS NULL AND u.company_id IS NOT NULL");
                    }
                }
            }
            // A bed belongs to its ward's company; a ward to its department's.
            if (in_array('hospital_beds', $tables, true) && in_array('hospital_wards', $tables, true)) {
                DB::statement('UPDATE `hospital_beds` b JOIN `hospital_wards` w ON w.id = b.ward_id SET b.company_id = w.company_id WHERE b.company_id IS NULL AND w.company_id IS NOT NULL');
            }
        }
        if (in_array('lab_tests', $tables, true) && Schema::hasTable('lab_order_items') && in_array('lab_orders', $tables, true)) {
            DB::statement('UPDATE `lab_tests` t JOIN `lab_order_items` i ON i.lab_test_id = t.id JOIN `lab_orders` o ON o.id = i.lab_order_id SET t.company_id = o.company_id WHERE t.company_id IS NULL AND o.company_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            if (Schema::hasColumn($t, 'company_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropIndex(['company_id']);
                    $table->dropColumn('company_id');
                });
            }
        }
    }
};
