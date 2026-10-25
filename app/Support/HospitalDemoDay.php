<?php

namespace App\Support;

use App\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * A hospital demo that is alive every day (user's request, 2026-10-25): the
 * doctors, nurses, pharmacy and lab of a demo company always have something
 * to work with today.
 *  - roster(): yesterday's and today's shifts, copied from the last roster day
 *    when missing, and the patients in the hospital shared out among the
 *    doctors and nurses on them (My patients, My round);
 *  - inpatients(): four example stays (pneumonia, heart failure in ICU,
 *    decompensated diabetes with a critical glucose, post-op appendectomy)
 *    with treatment and today's doses, diet, vitals, progress, lab results and
 *    an interconsultation -- added once, skipped when they are there.
 * Run by `php artisan hospital:demo-day` (daily) and the Vitalis migration.
 */
class HospitalDemoDay
{
    public const MARK = 'Demo day';

    public static function run(int $companyId): array
    {
        return ['inpatients' => self::inpatients($companyId), 'roster_rows' => self::roster($companyId), 'assigned' => self::assign($companyId)];
    }

    /** Yesterday and today get the roster of the last day that has one. */
    public static function roster(int $cid): int
    {
        $made = 0;
        foreach ([now()->subDay()->toDateString(), now()->toDateString()] as $date) {
            if (DB::table('hms_staff_shifts')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('shift_date', $date)->exists()) {
                continue;
            }
            $model = DB::table('hms_staff_shifts')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('shift_date', '<', $date)->max('shift_date');
            if (! $model) {
                continue;
            }
            foreach (DB::table('hms_staff_shifts')->where('company_id', $cid)->whereNull('deleted_at')->whereDate('shift_date', $model)->get() as $r) {
                DB::table('hms_staff_shifts')->insert([
                    'company_id' => $cid, 'shift_date' => $date, 'shift' => $r->shift, 'position' => $r->position,
                    'doctor_id' => $r->doctor_id, 'employee_id' => $r->employee_id, 'ward_id' => $r->ward_id, 'notes' => $r->notes,
                    'created_by' => $r->created_by, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $made++;
            }
        }

        return $made;
    }

    /**
     * The patients in the hospital shared out on today's rows (and last
     * night's): a doctor gets the inpatients they attend + others round-robin
     * (3 each), a nurse 3, the ER doctor the ER patients. Rows that already
     * have patients are left alone.
     */
    public static function assign(int $cid): int
    {
        $admitted = DB::table('admissions')->where('company_id', $cid)->where('status', 'admitted')->whereNull('deleted_at')->get(['patient_id', 'doctor_id']);
        $er = DB::table('er_cases')->where('company_id', $cid)->where('status', '!=', 'closed')->whereNull('deleted_at')->whereNotNull('bed_id')->pluck('patient_id');
        $ward = $admitted->pluck('patient_id')->values()->all();
        $all = array_values(array_unique(array_merge($ward, $er->all())));
        if (! $all) {
            return 0;
        }
        $rows = DB::table('hms_staff_shifts')->where('company_id', $cid)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereDate('shift_date', now()->toDateString())
                ->orWhere(fn ($w) => $w->whereDate('shift_date', now()->subDay()->toDateString())->where('shift', 'night')))
            ->orderBy('shift_date')->orderBy('shift')->orderBy('id')->get();
        $n = 0;
        $i = 0;
        foreach ($rows as $r) {
            if (DB::table('hms_shift_patients')->where('staff_shift_id', $r->id)->exists() || $r->position === 'nurse_supervisor') {
                continue;
            }
            if ($r->position === 'er_doctor') {
                $pick = $er->all();
            } else {
                $own = $r->doctor_id ? $admitted->where('doctor_id', $r->doctor_id)->pluck('patient_id')->all() : [];
                $pick = $own;
                $pool = $r->position === 'nurse' ? $all : $ward;
                while (count($pick) < 3 && $pool) {
                    $pid = $pool[$i++ % count($pool)];
                    if (! in_array($pid, $pick, true)) {
                        $pick[] = $pid;
                    }
                    if ($i > 50) {
                        break;
                    }
                }
            }
            foreach (array_unique($pick) as $pid) {
                DB::table('hms_shift_patients')->insert(['company_id' => $cid, 'staff_shift_id' => $r->id, 'patient_id' => $pid, 'created_at' => now(), 'updated_at' => now()]);
                $n++;
            }
        }

        return $n;
    }

    /** The four example stays (once). Returns how many were added. */
    public static function inpatients(int $cid): int
    {
        $doctors = DB::table('doctors')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
        if (count($doctors) < 2) {
            return 0;
        }
        $doc = fn (int $i) => $doctors[min($i, count($doctors) - 1)];
        $nurse = DB::table('users as u')->join('role_user as ru', 'ru.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('u.company_id', $cid)->where('r.name', 'Nurse')->value('u.id');
        $tests = DB::table('lab_tests')->where('company_id', $cid)->whereNull('deleted_at')->pluck('id', 'name');

        $examples = [
            // name, gender, age, bed type, doctor idx, reason, diagnosis, payer, meds [medicine, dose, route, every h], diet, labs [test, value, flag], consult [doctor idx, reason] | null
            ['Teresa Mamani Condori', 'female', 67, 'general', 1, 'Fiebre y tos con flema desde hace 4 días', 'Neumonía adquirida en la comunidad',
                ['payer_type' => 'private', 'insurer' => 'Rímac Seguros', 'policy_number' => 'RX-310045', 'coverage_percent' => 90],
                [['Ceftriaxona 1 g', '1 g', 'iv', 24], ['Paracetamol 500 mg', '500 mg', 'oral', 8]], 'Dieta blanda, abundantes líquidos',
                [['Hemograma completo', '14.8', 'high'], ['Glucosa en ayunas', '108', 'high']], null],
            ['Víctor Chávez Ramos', 'male', 72, 'icu', 3, 'Falta de aire al acostarse y piernas hinchadas', 'Insuficiencia cardiaca descompensada',
                ['payer_type' => 'public', 'insurer' => 'EsSalud', 'policy_number' => 'ES-7781230'],
                [['Furosemida 40 mg', '40 mg', 'iv', 12], ['Enalapril 10 mg', '10 mg', 'oral', 12]], 'Hiposódica, restricción de líquidos 1.5 L',
                [['Hemograma completo', '9.2', 'normal']], [9, 'Valorar derrame pleural derecho en la radiografía']],
            ['Lucía Paredes Soto', 'female', 45, 'general', 0, 'Mareos, mucha sed y orina frecuente', 'Diabetes tipo 2 descompensada',
                ['payer_type' => 'public', 'insurer' => 'SIS', 'policy_number' => 'SIS-4410922'],
                [['Insulina NPH', '10 UI', 'sc', 12], ['Metformina 850 mg', '850 mg', 'oral', 12]], 'Diabética 1800 kcal',
                [['Glucosa en ayunas', '38', 'critical'], ['Hemoglobina glicosilada (HbA1c)', '9.8', 'high']], null],
            ['Pedro Ccahuana Luna', 'male', 35, 'general', 6, 'Dolor abdominal en fosa ilíaca derecha', 'Postoperatorio de apendicectomía (día 1)',
                ['payer_type' => 'self', 'card_type' => 'Visa', 'card_last4' => '4242'],
                [['Ketorolaco 30 mg', '30 mg', 'iv', 8], ['Cefazolina 1 g', '1 g', 'iv', 8]], 'Líquidos claros, progresar según tolerancia',
                [['Hemograma completo', '12.5', 'high']], [1, 'Control de glucosa y presión en el postoperatorio']],
        ];

        $added = 0;
        foreach ($examples as [$name, $gender, $age, $bedType, $di, $reason, $dx, $payer, $meds, $diet, $labs, $consult]) {
            if (DB::table('patients')->where('company_id', $cid)->where('name', $name)->exists()) {
                continue;
            }
            $bed = DB::table('hospital_beds as b')->join('hospital_wards as w', 'w.id', '=', 'b.ward_id')
                ->where('b.company_id', $cid)->whereNull('b.deleted_at')->where('b.status', 'available')
                ->when($bedType === 'icu', fn ($q) => $q->where('w.type', 'icu'), fn ($q) => $q->whereNotIn('w.type', ['emergency', 'icu']))
                ->orderBy('b.id')->first(['b.id', 'b.ward_id', 'w.department_id', 'b.daily_rate as bed_rate', 'w.daily_rate as ward_rate']);
            if (! $bed) {
                continue;
            }
            DB::transaction(function () use ($cid, $name, $gender, $age, $bed, $doc, $di, $reason, $dx, $payer, $meds, $diet, $labs, $consult, $nurse, $tests) {
                $p = new Patient(['mrn' => Patient::generateMrn(), 'name' => $name, 'gender' => $gender,
                    'date_of_birth' => now()->subYears($age)->subDays(70)->toDateString(), 'phone' => '9'.random_int(10000000, 99999999),
                    'insurance_provider' => $payer['insurer'] ?? null, 'insurance_number' => $payer['policy_number'] ?? null,
                    'notes' => self::MARK, 'status' => 'active']);
                $p->company_id = $cid;
                $p->save();
                $admitted = now()->subDay()->setTime(10, 0);
                $doctorId = $doc($di);
                $aid = DB::table('admissions')->insertGetId([
                    'company_id' => $cid, 'reference' => self::ref('admissions', 'ADM'), 'patient_id' => $p->id, 'doctor_id' => $doctorId,
                    'ward_id' => $bed->ward_id, 'bed_id' => $bed->id, 'department_id' => $bed->department_id, 'admitted_at' => $admitted,
                    'daily_rate' => $bed->bed_rate ?: ($bed->ward_rate ?: 0), 'reason' => $reason, 'diagnosis' => $dx, 'status' => 'admitted',
                    'origin' => 'er', 'authorization_status' => isset($payer['insurer']) ? 'approved' : null,
                    'created_at' => $admitted, 'updated_at' => now(),
                ] + $payer);
                DB::table('hospital_beds')->where('id', $bed->id)->update(['status' => 'occupied', 'status_changed_at' => $admitted]);

                // Treatment + medication with its doses (yesterday's given, today's: earlier given, the last one late, the rest due).
                $tid = DB::table('hms_treatments')->insertGetId(['company_id' => $cid, 'reference' => self::ref('hms_treatments', 'TRT'), 'patient_id' => $p->id,
                    'admission_id' => $aid, 'lead_doctor_id' => $doctorId, 'diagnosis' => $dx, 'plan' => 'Tratamiento según evolución',
                    'status' => 'active', 'started_at' => $admitted, 'created_at' => $admitted, 'updated_at' => now()]);
                DB::table('hms_treatment_doctors')->insert(['treatment_id' => $tid, 'doctor_id' => $doctorId, 'role' => 'lead', 'created_at' => now(), 'updated_at' => now()]);
                foreach ($meds as [$med, $dose, $route, $every]) {
                    $oid = DB::table('hms_medication_orders')->insertGetId(['company_id' => $cid, 'treatment_id' => $tid, 'medicine' => $med, 'dose' => $dose,
                        'route' => $route, 'every_hours' => $every, 'starts_at' => $admitted, 'days' => 5, 'units_per_dose' => 1, 'status' => 'active',
                        'doctor_id' => $doctorId, 'created_at' => $admitted, 'updated_at' => now()]);
                    $lastPast = null;
                    for ($t = $admitted->copy(); $t < $admitted->copy()->addDays(5); $t->addHours($every)) {
                        $past = $t < now();
                        $row = ['company_id' => $cid, 'order_id' => $oid, 'treatment_id' => $tid, 'patient_id' => $p->id, 'scheduled_at' => $t->copy(),
                            'status' => $past ? 'given' : 'pending', 'given_at' => $past ? $t->copy()->addMinutes(random_int(5, 25)) : null,
                            'given_by' => $past ? $nurse : null, 'dispensed' => $t < now()->endOfDay(), 'created_at' => $admitted, 'updated_at' => now()];
                        $id = DB::table('hms_medication_doses')->insertGetId($row);
                        if ($past && $t->isToday()) {
                            $lastPast = $id;
                        }
                    }
                    // Today's last dose is still waiting for the nurse (late).
                    if ($lastPast) {
                        DB::table('hms_medication_doses')->where('id', $lastPast)->update(['status' => 'pending', 'given_at' => null, 'given_by' => null]);
                    }
                }
                // Diet, vital signs, progress.
                DB::table('hms_care_orders')->insert(['company_id' => $cid, 'admission_id' => $aid, 'patient_id' => $p->id, 'kind' => 'diet', 'description' => $diet,
                    'status' => 'active', 'starts_at' => $admitted, 'doctor_id' => $doctorId, 'created_at' => $admitted, 'updated_at' => now()]);
                DB::table('hms_care_orders')->insert(['company_id' => $cid, 'admission_id' => $aid, 'patient_id' => $p->id, 'kind' => 'vitals',
                    'description' => 'Control de signos vitales', 'every_hours' => 6, 'status' => 'active', 'starts_at' => $admitted,
                    'doctor_id' => $doctorId, 'created_at' => $admitted, 'updated_at' => now()]);
                foreach ([[now()->subHours(9), 38.2, 102], [now()->subHours(3), 37.4, 92]] as [$at, $temp, $pulse]) {
                    DB::table('hms_vitals')->insert(['company_id' => $cid, 'admission_id' => $aid, 'patient_id' => $p->id, 'taken_at' => $at, 'temperature' => $temp,
                        'pulse' => $pulse, 'bp_systolic' => random_int(110, 145), 'bp_diastolic' => random_int(65, 90), 'respiratory_rate' => random_int(16, 24),
                        'spo2' => random_int(91, 98), 'pain' => random_int(1, 5), 'user_id' => $nurse, 'created_at' => $at, 'updated_at' => $at]);
                }
                DB::table('hms_progress_notes')->insert(['company_id' => $cid, 'admission_id' => $aid, 'patient_id' => $p->id, 'kind' => 'doctor', 'doctor_id' => $doctorId,
                    'noted_at' => now()->startOfDay()->addHours(8), 'subjective' => 'Refiere que se siente algo mejor que ayer.', 'objective' => 'Afebril en la mañana, hemodinámicamente estable.',
                    'assessment' => 'Evolución favorable: '.$dx.'.', 'plan' => 'Continuar tratamiento, control de laboratorio mañana.',
                    'created_at' => now(), 'updated_at' => now()]);
                // Lab results of today (a critical one stays unseen: the doctor's alert).
                $lo = DB::table('lab_orders')->insertGetId(['company_id' => $cid, 'reference' => self::ref('lab_orders', 'LAB'), 'patient_id' => $p->id, 'doctor_id' => $doctorId,
                    'admission_id' => $aid, 'ordered_at' => now()->startOfDay()->addHours(6), 'completed_at' => now()->startOfDay()->addHours(9),
                    'priority' => 'urgent', 'status' => 'completed', 'total' => 0, 'created_at' => now(), 'updated_at' => now()]);
                foreach ($labs as [$test, $value, $flag]) {
                    $lt = DB::table('lab_tests')->where('id', $tests[$test] ?? 0)->first(['id', 'name', 'unit', 'normal_range', 'price']);
                    DB::table('lab_order_items')->insert(['lab_order_id' => $lo, 'lab_test_id' => $lt->id ?? null, 'test_name' => $lt->name ?? $test, 'price' => $lt->price ?? 0,
                        'result_value' => $value, 'unit' => $lt->unit ?? null, 'normal_range' => $lt->normal_range ?? null, 'flag' => $flag,
                        'critical_seen_at' => null, 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($consult) {
                    DB::table('hms_consults')->insert(['company_id' => $cid, 'admission_id' => $aid, 'patient_id' => $p->id, 'requested_by' => $doctorId,
                        'to_doctor_id' => $doc($consult[0]), 'reason' => $consult[1], 'priority' => 'normal', 'status' => 'requested',
                        'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
                }
            });
            $added++;
        }

        return $added;
    }

    /** PREFIX-YYYYMMDD-NNNN, unique across the install. */
    private static function ref(string $table, string $prefix): string
    {
        $stem = $prefix.'-'.now()->format('Ymd').'-';
        $last = DB::table($table)->where('reference', 'like', $stem.'%')->orderByDesc('reference')->value('reference');

        return $stem.str_pad((string) ($last ? ((int) substr($last, -4)) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }
}
