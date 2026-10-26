<?php

namespace App\Services\DemoData\Modules;

use App\Models\Appointment;
use App\Models\HospitalInvoice;
use App\Models\HospitalPayment;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\Prescription;
use App\Services\DemoData\DemoContext;
use App\Support\HospitalRoles;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo of a specialist clinic (Health Specialist, 2026-10-26), called by
 * HospitalModule for that business type: 7 specialties (general medicine,
 * pediatrics, ophthalmology, cardiology, gynecology / obstetrics, psychology,
 * psychiatry) with their doctors (logins, role Médico especialista),
 * reception / nursing / cashier staff, a lab and imaging catalog, patients
 * (customers) -- children with a growth history, pregnant women --, three
 * weeks of consultations (SOAP, specialty template data, prescriptions,
 * studies, bills as sales, surveys), today's schedule in every state of the
 * flow, the coming week booked (some confirmed / pre-registered) and the
 * follow-ups / referrals reception has to schedule.
 */
class SpecialistDemo
{
    /** [template, dept es, dept en, specialty es, specialty en, fee] */
    private const SPECIALTIES = [
        ['general', 'Medicina general', 'General medicine', 'Médico general', 'General practitioner', 60],
        ['pediatrics', 'Pediatría', 'Pediatrics', 'Pediatra', 'Pediatrician', 80],
        ['ophthalmology', 'Oftalmología', 'Ophthalmology', 'Oftalmólogo', 'Ophthalmologist', 90],
        ['cardiology', 'Cardiología', 'Cardiology', 'Cardiólogo', 'Cardiologist', 120],
        ['gynecology', 'Ginecología y obstetricia', 'Gynecology and obstetrics', 'Ginecólogo-obstetra', 'Obstetrician-gynecologist', 100],
        ['psychology', 'Psicología', 'Psychology', 'Psicólogo', 'Psychologist', 70],
        ['psychiatry', 'Psiquiatría', 'Psychiatry', 'Psiquiatra', 'Psychiatrist', 130],
    ];

    /** template => [[complaint, examination, diagnosis, plan, spec data, [medicine like, dose, every, for], [test codes]] es / en */
    private function cases(string $tpl): array
    {
        $es = $this->es;
        $c = [
            'general' => [
                [$es ? 'Dolor de garganta y fiebre hace 2 días' : 'Sore throat and fever for 2 days', $es ? 'Faringe eritematosa, adenopatías cervicales' : 'Red pharynx, cervical lymph nodes',
                    $es ? 'Faringitis aguda (J02.9)' : 'Acute pharyngitis (J02.9)', $es ? 'Hidratación, reposo 3 días' : 'Fluids, rest 3 days',
                    ['general_state' => 'Good'], [['Amoxic%', '500 mg', $es ? '8 horas' : '8 hours', $es ? '7 días' : '7 days'], ['Paracetamol%', '500 mg', $es ? '8 horas' : '8 hours', $es ? '3 días' : '3 days']], ['CBC']],
                [$es ? 'Control de presión arterial' : 'Blood pressure check', $es ? 'PA elevada, resto normal' : 'High BP, rest normal',
                    $es ? 'Hipertensión arterial (I10)' : 'Hypertension (I10)', $es ? 'Dieta baja en sal, caminar 30 min' : 'Low-salt diet, walk 30 min',
                    ['general_state' => 'Good'], [['Losart%', '50 mg', $es ? '24 horas' : '24 hours', $es ? '30 días' : '30 days']], ['LIP', 'FBG']],
                [$es ? 'Ardor en el estómago después de comer' : 'Burning stomach after meals', $es ? 'Dolor a la palpación en epigastrio' : 'Epigastric tenderness',
                    $es ? 'Gastritis (K29.7)' : 'Gastritis (K29.7)', $es ? 'Evitar irritantes, comidas fraccionadas' : 'Avoid irritants, small meals',
                    ['general_state' => 'Good'], [['Omepra%', '20 mg', $es ? '24 horas' : '24 hours', $es ? '14 días' : '14 days']], []],
            ],
            'pediatrics' => [
                [$es ? 'Control de niño sano' : 'Well-child visit', $es ? 'Desarrollo acorde a la edad' : 'Development appropriate for age',
                    $es ? 'Niño sano (Z00.1)' : 'Healthy child (Z00.1)', $es ? 'Continuar alimentación, vacunas según calendario' : 'Keep feeding, vaccines on schedule',
                    ['feeding' => 'Complementary feeding', 'vaccines' => 'Yes', 'milestones' => $es ? 'Camina solo, dice palabras' : 'Walks alone, says words'], [], []],
                [$es ? 'Tos y fiebre hace 3 días' : 'Cough and fever for 3 days', $es ? 'Sibilancias leves' : 'Mild wheezing',
                    $es ? 'Bronquitis aguda (J20.9)' : 'Acute bronchitis (J20.9)', $es ? 'Nebulizaciones, control en 5 días' : 'Nebulisation, review in 5 days',
                    ['feeding' => 'Family diet', 'vaccines' => 'Yes'], [['Salbut%', $es ? '2 puff' : '2 puffs', $es ? '6 horas' : '6 hours', $es ? '5 días' : '5 days']], []],
            ],
            'ophthalmology' => [
                [$es ? 'Visión borrosa de lejos' : 'Blurred distance vision', $es ? 'Polo anterior normal' : 'Normal anterior segment',
                    $es ? 'Miopía (H52.1)' : 'Myopia (H52.1)', $es ? 'Lentes con la refracción indicada, control anual' : 'Glasses with the given refraction, yearly check',
                    ['va_od_sc' => '20/70', 'va_oi_sc' => '20/50', 'va_od_cc' => '20/20', 'va_oi_cc' => '20/20', 'iop_od' => 15, 'iop_oi' => 16,
                        'rx_od' => '-1.75 / -0.50 x 180', 'rx_oi' => '-1.25 / -0.25 x 175', 'fundus' => $es ? 'Normal' : 'Normal'], [], []],
                [$es ? 'Ojo rojo con secreción' : 'Red eye with discharge', $es ? 'Hiperemia conjuntival' : 'Conjunctival hyperaemia',
                    $es ? 'Conjuntivitis (H10.9)' : 'Conjunctivitis (H10.9)', $es ? 'Higiene, compresas frías' : 'Hygiene, cold compresses',
                    ['va_od_sc' => '20/20', 'va_oi_sc' => '20/25', 'iop_od' => 14, 'iop_oi' => 14, 'slit_lamp' => $es ? 'Secreción mucopurulenta' : 'Mucopurulent discharge'], [], []],
                [$es ? 'Control de presión ocular' : 'Eye pressure check', $es ? 'Excavación papilar 0.5' : 'Cup-to-disc 0.5',
                    $es ? 'Sospecha de glaucoma (H40.0)' : 'Glaucoma suspect (H40.0)', $es ? 'Campo visual y OCT' : 'Visual field and OCT',
                    ['va_od_cc' => '20/25', 'va_oi_cc' => '20/25', 'iop_od' => 24, 'iop_oi' => 23], [], []],
            ],
            'cardiology' => [
                [$es ? 'Dolor torácico al esfuerzo' : 'Chest pain on exertion', $es ? 'Ruidos cardíacos rítmicos, sin soplos' : 'Regular heart sounds, no murmurs',
                    $es ? 'Angina estable (I20.8)' : 'Stable angina (I20.8)', $es ? 'Prueba de esfuerzo, control con estudios' : 'Stress test, review with results',
                    ['chest_pain' => 'Typical angina', 'nyha' => 'II', 'risk' => ['Hypertension', 'Dyslipidemia'], 'chol_total' => 238, 'ldl' => 162, 'hdl' => 38, 'ef' => 58], [], ['ECG', 'ECHO', 'LIP']],
                [$es ? 'Control de hipertensión' : 'Hypertension follow-up', $es ? 'Sin edemas' : 'No edema',
                    $es ? 'Hipertensión arterial (I10)' : 'Hypertension (I10)', $es ? 'Continuar tratamiento, dieta' : 'Keep treatment, diet',
                    ['chest_pain' => 'None', 'nyha' => 'I', 'risk' => ['Hypertension'], 'ef' => 62], [['Losart%', '50 mg', $es ? '24 horas' : '24 hours', $es ? '30 días' : '30 days']], ['ECG']],
            ],
            'gynecology' => [
                [$es ? 'Control prenatal' : 'Prenatal visit', $es ? 'Útero acorde a edad gestacional' : 'Uterus consistent with gestational age',
                    $es ? 'Embarazo normal (Z34.0)' : 'Normal pregnancy (Z34.0)', $es ? 'Ácido fólico, control en 4 semanas' : 'Folic acid, review in 4 weeks',
                    ['pregnant' => 'Yes', 'g' => 2, 'p' => 1, 'a' => 0, 'c' => 0, 'fundal_height' => 24, 'fhr' => 142, 'fetal_movements' => 'Present', 'edema' => 'No'], [], ['OBUS', 'CBC', 'URI']],
                [$es ? 'Control ginecológico anual' : 'Yearly gynecology check', $es ? 'Examen de mamas y pélvico normales' : 'Normal breast and pelvic exam',
                    $es ? 'Control ginecológico normal (Z01.4)' : 'Normal gynecology exam (Z01.4)', $es ? 'Papanicolaou anual' : 'Yearly Pap smear',
                    ['pregnant' => 'No', 'g' => 1, 'p' => 1, 'a' => 0, 'c' => 0, 'contraception' => $es ? 'DIU' : 'IUD'], [], []],
            ],
            'psychology' => [
                [$es ? 'Ansiedad y dificultad para dormir' : 'Anxiety and trouble sleeping', $es ? 'Alerta, ansiosa, colaboradora' : 'Alert, anxious, cooperative',
                    $es ? 'Trastorno de ansiedad generalizada (F41.1)' : 'Generalized anxiety disorder (F41.1)', $es ? 'Terapia cognitivo-conductual semanal' : 'Weekly CBT',
                    ['session_no' => 3, 'modality' => 'Individual', 'mood' => 5, 'gad7' => 13, 'phq9' => 8, 'risk' => 'None', 'techniques' => $es ? 'Respiración, reestructuración cognitiva' : 'Breathing, cognitive restructuring'], [], []],
                [$es ? 'Duelo por pérdida familiar' : 'Grief after a family loss', $es ? 'Ánimo bajo, llanto fácil' : 'Low mood, tearful',
                    $es ? 'Reacción de duelo (Z63.4)' : 'Grief reaction (Z63.4)', $es ? 'Acompañamiento quincenal' : 'Support every two weeks',
                    ['session_no' => 1, 'modality' => 'Individual', 'mood' => 4, 'phq9' => 11, 'gad7' => 6, 'risk' => 'Low'], [], []],
            ],
            'psychiatry' => [
                [$es ? 'Tristeza persistente y falta de energía' : 'Persistent sadness and low energy', $es ? 'Afecto hipotímico, sin ideación suicida' : 'Low affect, no suicidal ideation',
                    $es ? 'Episodio depresivo moderado (F32.1)' : 'Moderate depressive episode (F32.1)', $es ? 'Antidepresivo, psicoterapia, control en 4 semanas' : 'Antidepressant, psychotherapy, review in 4 weeks',
                    ['mood_affect' => $es ? 'Triste / hipotímico' : 'Sad / low', 'insight' => 'Preserved', 'phq9' => 16, 'gad7' => 9, 'suicide_risk' => 'Low', 'adherence' => 'Good'],
                    [[null, $es ? 'Sertralina 50 mg' : 'Sertraline 50 mg', '50 mg', $es ? '24 horas' : '24 hours', $es ? '30 días' : '30 days']], ['TSH']],
                [$es ? 'Insomnio de conciliación' : 'Trouble falling asleep', $es ? 'Orientado, ansioso' : 'Oriented, anxious',
                    $es ? 'Insomnio (F51.0)' : 'Insomnia (F51.0)', $es ? 'Higiene del sueño, control en 2 semanas' : 'Sleep hygiene, review in 2 weeks',
                    ['insight' => 'Preserved', 'phq9' => 7, 'gad7' => 11, 'suicide_risk' => 'None', 'adherence' => 'Partial'], [], []],
            ],
        ];

        return $c[$tpl];
    }

    /** [code, es, en, category es, category en, price, unit, range] */
    private const TESTS = [
        ['CBC', 'Hemograma completo', 'Complete blood count', 'Laboratorio', 'Laboratory', 15, 'x10^9/L', '4.0-11.0'],
        ['FBG', 'Glucosa en ayunas', 'Fasting glucose', 'Laboratorio', 'Laboratory', 8, 'mg/dL', '70-100'],
        ['LIP', 'Perfil lipídico', 'Lipid profile', 'Laboratorio', 'Laboratory', 25, 'mg/dL', '< 200'],
        ['URI', 'Examen de orina', 'Urinalysis', 'Laboratorio', 'Laboratory', 10, null, 'Normal'],
        ['TSH', 'TSH', 'TSH', 'Laboratorio', 'Laboratory', 20, 'mIU/L', '0.4-4.0'],
        ['ECG', 'Electrocardiograma', 'Electrocardiogram', 'Cardiología', 'Cardiology', 35, null, 'Normal'],
        ['ECHO', 'Ecocardiograma', 'Echocardiogram', 'Cardiología', 'Cardiology', 120, null, 'Normal'],
        ['CXR', 'Radiografía de tórax', 'Chest X-ray', 'Imágenes', 'Imaging', 40, null, 'Normal'],
        ['ABUS', 'Ecografía abdominal', 'Abdominal ultrasound', 'Imágenes', 'Imaging', 70, null, 'Normal'],
        ['OBUS', 'Ecografía obstétrica', 'Obstetric ultrasound', 'Imágenes', 'Imaging', 80, null, 'Normal'],
    ];

    private const ES_F = ['María', 'Lucía', 'Valentina', 'Camila', 'Sofía', 'Rosa', 'Carmen', 'Daniela', 'Gabriela', 'Andrea', 'Patricia', 'Elena'];
    private const ES_M = ['Mateo', 'Santiago', 'Diego', 'Alejandro', 'Jorge', 'Luis', 'Carlos', 'Miguel', 'José', 'Juan', 'Ricardo', 'Fernando'];
    private const ES_L = ['García', 'Rodríguez', 'Quispe', 'Flores', 'Sánchez', 'Ramírez', 'Torres', 'Mendoza', 'Rojas', 'Vargas', 'Castillo', 'Chávez', 'Arce', 'Salazar'];

    private array $doctors = [];     // [template => ['id','fee','dept','name','user']]
    private array $tests = [];       // code => ['id','name','price','unit','range']
    private array $medicines = [];   // like => product row
    private int $count = 0;

    public function __construct(private bool $es)
    {
    }

    private function person(DemoContext $ctx, ?string $g = null): array
    {
        $g ??= $ctx->pick(['male', 'female']);
        if ($this->es) {
            return [$ctx->pick($g === 'female' ? self::ES_F : self::ES_M), $ctx->pick(self::ES_L).' '.$ctx->pick(self::ES_L), $g];
        }
        [$first, $last] = $ctx->personName();

        return [$first, $last, $g];
    }

    private function slug(string $s): string
    {
        return preg_replace('/[^a-z]+/', '.', trim(strtolower(Str::ascii($s))));
    }

    public function generate(DemoContext $ctx): array
    {
        $cid = $ctx->companyId;
        $demo = $this->es ? 'Datos de ejemplo' : 'Demo data';

        // ---- HRM frame for the staff
        $deptId = $ctx->insert('departments', ['department' => $this->es ? 'Consultorio' : 'Clinic', 'department_head' => null]);
        $shiftId = $ctx->insert('office_shifts', ['name' => $this->es ? 'Horario del consultorio' : 'Clinic hours']);
        $desig = [];
        $employee = function (string $first, string $last, string $gender, string $title, string $role) use ($ctx, $deptId, $shiftId, &$desig) {
            $desig[$title] ??= $ctx->insert('designations', ['department_id' => $deptId, 'designation' => $title]);
            $id = $ctx->insert('employees', [
                'user_id' => null, 'firstname' => $first, 'lastname' => $last, 'username' => "$first $last",
                'email' => $this->slug("$first $last").'.staff'.random_int(100, 999).'@example.com',
                'phone' => '9'.random_int(10000000, 99999999), 'gender' => $gender, 'avatar' => 'no_avatar.png',
                'joining_date' => now()->subDays(random_int(200, 1500))->toDateString(), 'department_id' => $deptId,
                'designation_id' => $desig[$title], 'office_shift_id' => $shiftId, 'total_leave' => 18, 'remaining_leave' => 18,
                'basic_salary' => 0, 'employment_type' => 'full_time',
            ]);
            $userId = DB::table('employees')->where('id', $id)->value('user_id');
            if ($userId && ($rid = HospitalRoles::roleId($ctx->companyId, $role))) {
                DB::table('role_user')->where('user_id', $userId)->delete();
                DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $rid]);
                DB::table('users')->where('id', $userId)->update(['role_id' => $rid]);
            }

            return $id;
        };

        // ---- Specialties: department + doctor (employee + login)
        foreach (self::SPECIALTIES as $i => [$tpl, $dEs, $dEn, $sEs, $sEn, $fee]) {
            $dept = $ctx->insert('hospital_departments', ['name' => $this->es ? $dEs : $dEn, 'code' => strtoupper(substr($tpl, 0, 3)),
                'location' => ($this->es ? 'Consultorio ' : 'Office ').(101 + $i), 'phone' => '01'.random_int(1000000, 9999999), 'description' => $demo, 'is_active' => 1]);
            [$first, $last, $g] = $this->person($ctx);
            $title = in_array($tpl, ['psychology'], true) ? ($this->es ? 'Ps.' : 'Ps.') : ($this->es ? ($g === 'female' ? 'Dra.' : 'Dr.') : 'Dr.');
            $empId = $employee($first, explode(' ', $last)[0], $g, $this->es ? $sEs : $sEn, 'Specialist');
            $docId = $ctx->insert('doctors', [
                'name' => "$title $first $last", 'code' => 'ESP-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'department_id' => $dept,
                'employee_id' => $empId, 'specialty' => $this->es ? $sEs : $sEn, 'qualification' => 'MD', 'license_no' => ($this->es ? 'CMP-' : 'LIC-').random_int(10000, 99999),
                'phone' => '9'.random_int(10000000, 99999999), 'email' => $this->slug("dr $first $last").random_int(10, 99).'@example.com',
                'consultation_fee' => $fee, 'specialty_template' => $tpl, 'is_active' => 1, 'notes' => $demo,
                'availability' => json_encode(['mon' => ['08:00', '18:00'], 'tue' => ['08:00', '18:00'], 'wed' => ['08:00', '18:00'], 'thu' => ['08:00', '18:00'], 'fri' => ['08:00', '18:00'], 'sat' => ['08:00', '13:00']]),
            ]);
            $this->doctors[$tpl] = ['id' => $docId, 'fee' => $fee, 'dept' => $dept];
        }
        // Reception, nursing, cashier
        foreach ([[2, 'Reception', 'Recepcionista', 'Receptionist'], [2, 'Clinic Nurse', 'Enfermera', 'Nurse'], [1, 'Billing', 'Cajera', 'Cashier']] as [$n, $role, $tEs, $tEn]) {
            for ($k = 0; $k < $n; $k++) {
                [$first, $last, $g] = $this->person($ctx, 'female');
                $employee($first, explode(' ', $last)[0], $g, $this->es ? $tEs : $tEn, $role);
            }
        }

        // ---- Lab / imaging catalog
        foreach (self::TESTS as [$code, $nEs, $nEn, $cEs, $cEn, $price, $unit, $range]) {
            $this->tests[$code] = ['id' => $ctx->insert('lab_tests', ['name' => $this->es ? $nEs : $nEn, 'code' => $code, 'category' => $this->es ? $cEs : $cEn,
                'sample_type' => null, 'unit' => $unit, 'normal_range' => $range, 'price' => $price, 'turnaround_hours' => 24, 'is_active' => 1]),
                'name' => $this->es ? $nEs : $nEn, 'price' => $price, 'unit' => $unit, 'range' => $range];
        }
        foreach (DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')->whereNotNull('generic_name')->get(['id', 'name']) as $p) {
            $this->medicines[] = $p;
        }

        // ---- Patients (customers): adults, children, pregnant women; each with its "own" specialty.
        $patients = [];
        $profiles = array_merge(array_fill(0, 5, 'general'), array_fill(0, 5, 'pediatrics'), array_fill(0, 4, 'ophthalmology'),
            array_fill(0, 4, 'cardiology'), array_fill(0, 4, 'gynecology'), array_fill(0, 3, 'psychology'), array_fill(0, 3, 'psychiatry'));
        $code = (int) (DB::table('clients')->max('code') ?? 0);
        foreach ($profiles as $n => $tpl) {
            $gender = $tpl === 'gynecology' ? 'female' : null;
            [$first, $last, $g] = $this->person($ctx, $gender);
            $age = match ($tpl) { 'pediatrics' => $n === 5 ? 1 : random_int(1, 9), 'gynecology' => random_int(22, 38), 'cardiology' => random_int(48, 75), default => random_int(18, 70) };
            $dob = $tpl === 'pediatrics' && $n === 5 ? now()->subMonths(20)->toDateString() : now()->subYears($age)->subDays(random_int(0, 360))->toDateString();
            $email = $this->slug("$first $last").'.demo'.random_int(1000, 9999).'@example.com';
            $phone = '9'.random_int(10000000, 99999999);
            $client = $ctx->insert('clients', ['code' => ++$code, 'name' => "$first $last", 'firstname' => $first, 'lastname' => $last, 'email' => $email,
                'phone' => $phone, 'city' => $this->es ? 'Lima' : 'Springfield', 'adresse' => ($this->es ? 'Av. Arequipa ' : 'Main St ').random_int(100, 3000),
                'opening_balance' => 0, 'credit_limit' => 0]);
            $patients[] = ['tpl' => $tpl, 'dob' => $dob, 'id' => $ctx->insert('patients', [
                'client_id' => $client, 'mrn' => Patient::generateMrn(), 'name' => "$first $last", 'gender' => $g, 'date_of_birth' => $dob,
                'phone' => $phone, 'email' => $email, 'address' => ($this->es ? 'Av. Arequipa ' : 'Main St ').random_int(100, 3000), 'city' => $this->es ? 'Lima' : 'Springfield',
                'blood_group' => $ctx->pick(['O+', 'O+', 'A+', 'B+', 'AB+', 'O-']), 'allergies' => $n % 7 === 0 ? ($this->es ? 'Penicilina' : 'Penicillin') : null,
                'chronic_conditions' => $tpl === 'cardiology' ? ($this->es ? 'Hipertensión arterial' : 'Hypertension') : null,
                'insurance_provider' => $n % 3 === 0 ? ($this->es ? 'Pacífico Seguros' : 'Health Plus') : null,
                'insurance_number' => $n % 3 === 0 ? 'POL-'.random_int(100000, 999999) : null, 'notes' => $demo, 'status' => 'active',
            ])];
        }

        // ---- Growth history of the 20-month-old (pediatric curve)
        $baby = $patients[5];
        foreach ([2 => [5.6, 57.5], 6 => [7.9, 67.0], 9 => [8.8, 71.5], 12 => [9.5, 75.2], 15 => [10.1, 78.6], 18 => [10.8, 81.9]] as $m => [$w, $h]) {
            $at = Carbon::parse($baby['dob'])->addMonths($m)->setTime(10, 0);
            if ($at < now()->subDays(25)) {
                $this->consultation($ctx, $baby, 'pediatrics', $at, 'completed', 0, ['weight' => $w, 'height' => $h, 'head_circumference' => 38 + $m * 0.45]);
            }
        }

        // ---- Three weeks of consultations (Mon-Sat), 2 per doctor a day
        $byTpl = collect($patients)->groupBy('tpl');
        for ($d = 21; $d >= 1; $d--) {
            $day = now()->subDays($d);
            if ($day->isSunday()) {
                continue;
            }
            foreach (self::SPECIALTIES as [$tpl]) {
                foreach ([9, 11] as $k => $hour) {
                    $p = $byTpl[$tpl]->random();
                    $roll = random_int(1, 100);
                    $status = $roll <= 8 ? 'no_show' : ($roll <= 12 ? 'cancelled' : 'completed');
                    $this->consultation($ctx, $p, $tpl, $day->copy()->setTime($hour + $k, random_int(0, 2) * 20), $status, $k);
                }
            }
        }

        // ---- Today, every state of the flow (per doctor)
        $states = [['finished', -180], ['finished', -120], ['no_show', -150], ['in_consult', -25], ['ready', -10], ['checked_in', 0], ['confirmed', 60], ['booked', 120]];
        foreach (self::SPECIALTIES as $j => [$tpl]) {
            foreach ($states as $k => [$state, $offset]) {
                if ($j % 2 === 1 && $k === 2) {
                    continue; // fewer no-shows
                }
                $at = now()->addMinutes($offset + $j * 5)->second(0);
                $at = $at->isSameDay(now()) ? $at : now()->startOfDay()->setTime(8, 0)->addMinutes($k * 20);
                $this->today($ctx, $byTpl[$tpl]->random(), $tpl, $at, $state);
            }
        }

        // ---- The coming week: booked, some confirmed / pre-registered
        for ($d = 1; $d <= 6; $d++) {
            $day = now()->addDays($d);
            if ($day->isSunday()) {
                continue;
            }
            foreach (self::SPECIALTIES as [$tpl]) {
                foreach ([9, 10, 15] as $hour) {
                    if (random_int(1, 100) > 70) {
                        continue;
                    }
                    $p = $byTpl[$tpl]->random();
                    $id = $this->appointment($ctx, $p, $tpl, $day->copy()->setTime($hour, 0), random_int(1, 100) <= 45 ? 'confirmed' : 'scheduled', random_int(1, 100) <= 30 ? 'web' : 'phone');
                    if (random_int(1, 100) <= 35) {
                        DB::table('appointments')->where('id', $id)->update(['pre_registered_at' => now()->subHours(random_int(1, 40)),
                            'pre_registration' => json_encode(array_filter(['reason' => $this->cases($tpl)[0][0], 'symptoms_since' => $this->es ? '1 semana' : '1 week',
                                'current_medication' => $tpl === 'cardiology' ? 'Losartán 50 mg' : null]))]);
                    }
                }
            }
        }

        return [
            'Specialties' => count(self::SPECIALTIES), 'Patients' => count($patients), 'Appointments and consultations' => $this->count,
            'Staff (with logins)' => count(self::SPECIALTIES) + 5, 'Lab / imaging tests' => count(self::TESTS),
        ];
    }

    /** A plain appointment (booked / confirmed). */
    private function appointment(DemoContext $ctx, array $p, string $tpl, Carbon $at, string $status, string $channel = 'phone'): int
    {
        $doc = $this->doctors[$tpl];
        $first = ! DB::table('patient_visits')->where('patient_id', $p['id'])->whereNull('deleted_at')->exists();
        $this->count++;

        return $ctx->insert('appointments', [
            'reference' => Appointment::nextReference('APT'), 'patient_id' => $p['id'], 'doctor_id' => $doc['id'], 'department_id' => $doc['dept'],
            'scheduled_at' => $at, 'duration_minutes' => 20, 'type' => $first ? 'consultation' : 'follow_up', 'status' => $status,
            'reason' => $this->cases($tpl)[0][0], 'fee' => $doc['fee'], 'channel' => $channel, 'is_first_visit' => $first,
            'confirmed_at' => $status === 'confirmed' ? $at->copy()->subDay() : null, 'created_by' => $ctx->userId,
            'created_at' => $at->copy()->subDays(random_int(2, 10)), 'updated_at' => $at->copy()->subDays(1),
        ]);
    }

    /** Today's appointment in a given state of the flow. */
    private function today(DemoContext $ctx, array $p, string $tpl, Carbon $at, string $state): void
    {
        if (in_array($state, ['booked', 'confirmed'], true)) {
            $this->appointment($ctx, $p, $tpl, $at, $state === 'confirmed' ? 'confirmed' : 'scheduled');

            return;
        }
        if ($state === 'no_show') {
            $this->appointment($ctx, $p, $tpl, $at, 'no_show');

            return;
        }
        $id = $this->consultation($ctx, $p, $tpl, $at, $state === 'finished' ? 'completed' : 'open', 0);
        $checked = $at->copy()->subMinutes(5);
        $upd = ['status' => $state === 'finished' ? 'completed' : 'arrived', 'flow_status' => $state, 'checked_in_at' => $checked,
            'vitals_at' => null, 'ready_at' => null, 'consult_started_at' => null, 'consult_ended_at' => null];
        if ($state !== 'checked_in') {
            $upd['vitals_at'] = $upd['ready_at'] = $checked->copy()->addMinutes(6);
        }
        if (in_array($state, ['in_consult', 'finished'], true)) {
            $upd['consult_started_at'] = $checked->copy()->addMinutes(14);
        }
        if ($state === 'finished') {
            $upd['consult_ended_at'] = $checked->copy()->addMinutes(35);
        }
        DB::table('appointments')->where('id', $id)->update($upd);
        if ($state === 'checked_in') {
            // No vital signs yet: the nursing station has them to take.
            DB::table('patient_visits')->where('appointment_id', $id)->update(['weight' => null, 'height' => null, 'temperature' => null,
                'pulse' => null, 'bp_systolic' => null, 'bp_diastolic' => null, 'spo2' => null, 'complaint' => null, 'examination' => null,
                'diagnosis' => null, 'treatment_plan' => null, 'specialty_data' => null]);
            DB::table('prescriptions')->whereIn('visit_id', DB::table('patient_visits')->where('appointment_id', $id)->pluck('id'))->update(['deleted_at' => now()]);
        } elseif (in_array($state, ['ready', 'in_consult'], true)) {
            // The doctor has not written the note yet.
            DB::table('patient_visits')->where('appointment_id', $id)->update(['examination' => null, 'diagnosis' => null, 'treatment_plan' => null]);
            DB::table('prescriptions')->whereIn('visit_id', DB::table('patient_visits')->where('appointment_id', $id)->pluck('id'))->update(['deleted_at' => now()]);
        }
    }

    /**
     * A consultation: the appointment with its flow times, the visit (vitals,
     * SOAP, specialty data, follow-up), prescription, studies with results, the
     * bill (a sale, usually paid) and the survey. Returns the appointment id.
     */
    private function consultation(DemoContext $ctx, array $p, string $tpl, Carbon $at, string $status, int $variant, array $vitals = []): int
    {
        $doc = $this->doctors[$tpl];
        $cases = $this->cases($tpl);
        [$complaint, $exam, $dx, $plan, $spec, $meds, $tests] = $cases[$variant % count($cases)];
        if (in_array($status, ['no_show', 'cancelled'], true)) {
            return $this->appointment($ctx, $p, $tpl, $at, $status);
        }
        $aId = $this->appointment($ctx, $p, $tpl, $at, $status === 'completed' ? 'completed' : 'arrived');
        $checked = $at->copy()->subMinutes(random_int(0, 10));
        $start = $checked->copy()->addMinutes(random_int(8, 30));
        if ($status === 'completed') {
            DB::table('appointments')->where('id', $aId)->update(['flow_status' => 'finished', 'checked_in_at' => $checked, 'vitals_at' => $checked->copy()->addMinutes(5),
                'ready_at' => $checked->copy()->addMinutes(6), 'consult_started_at' => $start, 'consult_ended_at' => $start->copy()->addMinutes(random_int(12, 35)),
                'payment_status' => 'paid']);
        }
        $ageYears = Carbon::parse($p['dob'])->diffInYears($at);
        $child = $ageYears < 12;
        $v = $vitals + [
            'weight' => $child ? round(3 + $ageYears * 2.6, 1) : random_int(52, 92), 'height' => $child ? round(55 + $ageYears * 9, 1) : random_int(150, 182),
            'temperature' => $variant === 0 && $tpl === 'general' ? 38.2 : 36.6, 'pulse' => random_int(64, 96),
            'bp_systolic' => $tpl === 'cardiology' ? random_int(132, 156) : random_int(105, 128), 'bp_diastolic' => $tpl === 'cardiology' ? random_int(84, 96) : random_int(65, 82),
            'respiratory_rate' => random_int(14, 20), 'spo2' => random_int(96, 99),
        ];
        $hc = $v['head_circumference'] ?? null;
        unset($v['head_circumference']);
        if ($tpl === 'gynecology' && ($spec['pregnant'] ?? '') === 'Yes') {
            $spec['lmp'] = $at->copy()->subWeeks(24)->toDateString();
        }
        if ($hc) {
            $spec['head_circumference'] = round($hc, 1);
        }
        $visitId = $ctx->insert('patient_visits', $v + [
            'reference' => PatientVisit::nextReference('VIS'), 'patient_id' => $p['id'], 'doctor_id' => $doc['id'], 'appointment_id' => $aId,
            'department_id' => $doc['dept'], 'visit_date' => $start, 'type' => 'opd', 'complaint' => $complaint, 'examination' => $exam,
            'diagnosis' => $dx, 'treatment_plan' => $plan, 'status' => $status === 'completed' ? 'completed' : 'open', 'fee' => $doc['fee'],
            'specialty_template' => $tpl, 'specialty_data' => json_encode($spec), 'created_by' => $ctx->userId, 'created_at' => $start, 'updated_at' => $start,
        ]);
        // Prescription
        if ($meds) {
            $rx = $ctx->insert('prescriptions', ['reference' => Prescription::nextReference('RX'), 'visit_id' => $visitId, 'patient_id' => $p['id'],
                'doctor_id' => $doc['id'], 'prescribed_on' => $at->toDateString(), 'created_by' => $ctx->userId]);
            foreach ($meds as $m) {
                [$like, $dose, $every, $for] = count($m) === 5 ? [$m[0], $m[2], $m[3], $m[4]] : $m;
                $name = count($m) === 5 ? $m[1] : null;
                $prod = $like ? collect($this->medicines)->first(fn ($x) => Str::is(str_replace('%', '*', $like), $x->name)) : null;
                $ctx->insert('prescription_items', ['prescription_id' => $rx, 'product_id' => optional($prod)->id, 'medicine' => $prod->name ?? $name ?? $like,
                    'dosage' => $dose, 'frequency' => $every, 'duration' => $for, 'quantity' => 1, 'instructions' => $this->es ? 'Después de las comidas' : 'After meals']);
            }
        }
        // Studies with results (completed consultations)
        if ($tests && $status === 'completed') {
            $order = $ctx->insert('lab_orders', ['reference' => LabOrder::nextReference('LAB'), 'patient_id' => $p['id'], 'doctor_id' => $doc['id'],
                'visit_id' => $visitId, 'ordered_at' => $start, 'completed_at' => $start->copy()->addDay(), 'priority' => 'routine', 'status' => 'completed',
                'total' => array_sum(array_map(fn ($c) => $this->tests[$c]['price'], $tests)), 'created_by' => $ctx->userId]);
            foreach ($tests as $c) {
                $t = $this->tests[$c];
                $result = ['LIP' => '238', 'FBG' => '104', 'CBC' => '7.8', 'TSH' => '2.1'][$c] ?? ($this->es ? 'Normal' : 'Normal');
                $ctx->insert('lab_order_items', ['lab_order_id' => $order, 'lab_test_id' => $t['id'], 'test_name' => $t['name'], 'price' => $t['price'],
                    'result_value' => $result, 'unit' => $t['unit'], 'normal_range' => $t['range'], 'flag' => in_array($c, ['LIP', 'FBG'], true) ? 'high' : 'normal']);
            }
        }
        if ($status !== 'completed') {
            return $aId;
        }
        // Problem list
        if (! DB::table('hms_patient_problems')->where('patient_id', $p['id'])->where('problem', $dx)->exists()) {
            $ctx->insert('hms_patient_problems', ['patient_id' => $p['id'], 'problem' => $dx, 'status' => in_array($tpl, ['cardiology', 'psychiatry', 'ophthalmology'], true) ? 'active' : 'resolved',
                'onset_on' => $at->toDateString(), 'doctor_id' => $doc['id']]);
        }
        // Bill = a sale, usually paid
        $paid = random_int(1, 100) <= 90;
        $inv = $ctx->insert('hospital_invoices', ['reference' => HospitalInvoice::nextReference('INV'), 'patient_id' => $p['id'], 'invoice_date' => $at->toDateString(),
            'subtotal' => $doc['fee'], 'discount' => 0, 'tax' => 0, 'total' => $doc['fee'], 'paid' => 0, 'status' => 'unpaid', 'notes' => 'Appointment', 'created_by' => $ctx->userId]);
        $ctx->insert('hospital_invoice_items', ['invoice_id' => $inv, 'type' => 'consultation', 'description' => ($this->es ? 'Consulta — ' : 'Consultation — ').DB::table('doctors')->where('id', $doc['id'])->value('name'),
            'quantity' => 1, 'unit_price' => $doc['fee'], 'total' => $doc['fee']]);
        DB::table('appointments')->where('id', $aId)->update(['invoice_id' => $inv, 'payment_status' => $paid ? 'paid' : 'pending']);
        $invoice = HospitalInvoice::withoutGlobalScope('company')->find($inv);
        $sale = \App\Services\HospitalSale::sync($invoice, $ctx->userId);
        if ($sale) {
            $ctx->tag('sales', (int) $sale->id);
            foreach (DB::table('sale_details')->where('sale_id', $sale->id)->pluck('id') as $id) {
                $ctx->tag('sale_details', (int) $id);
            }
            DB::table('sales')->where('id', $sale->id)->update(['date' => $at->toDateString()]);
            if ($paid) {
                $pay = $ctx->insert('hospital_payments', ['reference' => HospitalPayment::nextReference('PAY'), 'invoice_id' => $inv, 'patient_id' => $p['id'],
                    'paid_on' => $at->toDateString(), 'amount' => $doc['fee'], 'method' => $ctx->pick(['cash', 'card', 'card', 'transfer']), 'created_by' => $ctx->userId]);
                DB::table('hospital_invoices')->where('id', $inv)->update(['paid' => $doc['fee'], 'status' => 'paid']);
                \App\Services\HospitalSale::syncPayment(HospitalPayment::withoutGlobalScope('company')->find($pay), HospitalInvoice::withoutGlobalScope('company')->find($inv));
                foreach (DB::table('payment_sales')->where('sale_id', $sale->id)->pluck('id') as $id) {
                    $ctx->tag('payment_sales', (int) $id);
                }
            }
        }
        // Survey (most answered, mostly promoters)
        $answered = random_int(1, 100) <= 75 && $at < now()->subHours(3);
        $score = $answered ? $ctx->pick([10, 10, 10, 10, 9, 9, 9, 8, 8, 7, 6, 5, 3]) : null;
        $comments = $this->es
            ? [10 => 'Excelente atención, muy puntuales.', 9 => 'Muy amable el doctor.', 8 => 'Buena atención, algo de espera.', 6 => 'Esperé mucho en la sala.', 5 => 'La recepción fue lenta.', 3 => 'Me cambiaron la hora sin avisar.']
            : [10 => 'Excellent care, very punctual.', 9 => 'The doctor was very kind.', 8 => 'Good care, some waiting.', 6 => 'I waited a long time.', 5 => 'Reception was slow.', 3 => 'They changed my time without telling me.'];
        $ctx->insert('hms_surveys', ['appointment_id' => $aId, 'patient_id' => $p['id'], 'doctor_id' => $doc['id'], 'token' => Str::random(40),
            'sent_at' => $start->copy()->addHour(), 'answered_at' => $answered ? $start->copy()->addHours(random_int(2, 30)) : null,
            'score' => $score, 'comment' => $answered && random_int(1, 100) <= 45 ? ($comments[$score] ?? null) : null]);
        // Follow-ups / referrals: only the last few days' are still waiting for reception; older ones were already scheduled.
        $reqStatus = $at->gte(now()->subDays(4)->startOfDay()) ? 'pending' : 'scheduled';
        if (random_int(1, 100) <= 12) {
            $ctx->insert('hms_appointment_requests', ['patient_id' => $p['id'], 'doctor_id' => $doc['id'], 'from_visit_id' => $visitId, 'from_doctor_id' => $doc['id'],
                'kind' => 'follow_up', 'wanted_on' => now()->addDays(random_int(-2, 20))->toDateString(), 'reason' => $this->es ? 'Control con resultados' : 'Review with results',
                'studies' => $tests ? implode(', ', array_map(fn ($c) => $this->tests[$c]['name'], $tests)) : null, 'status' => $reqStatus, 'created_by' => $ctx->userId]);
        } elseif (random_int(1, 100) <= 4 && $tpl !== 'cardiology') {
            $ctx->insert('hms_appointment_requests', ['patient_id' => $p['id'], 'doctor_id' => $this->doctors['cardiology']['id'], 'from_visit_id' => $visitId,
                'from_doctor_id' => $doc['id'], 'kind' => 'referral', 'wanted_on' => now()->addDays(5)->toDateString(),
                'reason' => $this->es ? 'Evaluación cardiológica' : 'Cardiology assessment', 'status' => $reqStatus, 'created_by' => $ctx->userId]);
        }

        return $aId;
    }
}
