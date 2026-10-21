<?php

namespace App\Services\DemoData\Modules;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\ErCase;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital examples: departments, doctors (with weekly clinic hours), wards
 * and beds (+ the Emergency Room's beds), a lab test catalogue, patients,
 * appointments past and upcoming, OPD visits for the completed ones, lab
 * orders with results, one current in-patient admission (its bed marked
 * occupied) and Emergency Room cases -- some on the board now, some closed.
 *
 * Texts follow the company's language (Spanish or English), like the school
 * demo (2026-10-21). The examples are only added while the hospital is empty
 * (dataTables()).
 */
class HospitalModule extends DemoModule
{
    private bool $es = false;

    // [es, en, code, location]
    private const DEPARTMENTS = [
        ['Medicina general', 'General Medicine', 'GEN', 'Bloque A, planta baja', 'Block A, Ground floor'],
        ['Pediatría', 'Pediatrics', 'PED', 'Bloque B, 1er piso', 'Block B, 1st floor'],
        ['Cardiología', 'Cardiology', 'CAR', 'Bloque A, 2do piso', 'Block A, 2nd floor'],
        ['Traumatología', 'Orthopedics', 'ORT', 'Bloque C, 1er piso', 'Block C, 1st floor'],
        ['Emergencias', 'Emergency', 'EME', 'Bloque A, planta baja (entrada de ambulancias)', 'Block A, Ground floor (ambulance entrance)'],
    ];
    // [department index, specialty es, specialty en, qualification, fee]
    private const DOCTORS = [
        [0, 'Médico general', 'General Physician', 'MBBS', 40],
        [0, 'Medicina interna', 'Internal Medicine', 'MBBS, MD', 55],
        [1, 'Pediatra', 'Pediatrician', 'MBBS, DCH', 50],
        [2, 'Cardiólogo', 'Cardiologist', 'MBBS, MD, DM (Cardiology)', 90],
        [3, 'Traumatólogo', 'Orthopedic Surgeon', 'MBBS, MS (Ortho)', 80],
        [4, 'Emergenciólogo', 'Emergency Physician', 'MBBS, MD (Emergency Medicine)', 70],
    ];
    // [es, en, code, category es, category en, sample es, sample en, unit, normal range, price, turnaround h]
    private const LAB_TESTS = [
        ['Hemograma completo', 'Complete Blood Count', 'CBC', 'Hematología', 'Hematology', 'Sangre', 'Blood', 'x10^9/L', '4.0-11.0', 15, 4],
        ['Glucosa en ayunas', 'Fasting Blood Glucose', 'FBG', 'Bioquímica', 'Biochemistry', 'Sangre', 'Blood', 'mg/dL', '70-100', 8, 2],
        ['Perfil lipídico', 'Lipid Profile', 'LIP', 'Bioquímica', 'Biochemistry', 'Sangre', 'Blood', 'mg/dL', '< 200', 25, 6],
        ['Examen de orina', 'Urinalysis', 'URI', 'Patología clínica', 'Clinical Pathology', 'Orina', 'Urine', null, 'Normal', 10, 3],
        ['Hormona estimulante de tiroides (TSH)', 'Thyroid Stimulating Hormone', 'TSH', 'Endocrinología', 'Endocrinology', 'Sangre', 'Blood', 'mIU/L', '0.4-4.0', 20, 24],
        ['Hemoglobina glicosilada (HbA1c)', 'HbA1c', 'HBA', 'Bioquímica', 'Biochemistry', 'Sangre', 'Blood', '%', '4.0-5.6', 18, 24],
    ];
    // [complaint, diagnosis, treatment] es / en
    private const COMPLAINTS = [
        [['Fiebre y dolor de garganta hace 3 días', 'Faringitis aguda', 'Paracetamol, líquidos, reposo'],
            ['Fever and sore throat for 3 days', 'Acute pharyngitis', 'Paracetamol, fluids, rest']],
        [['Molestia en el pecho al esfuerzo', 'Angina estable - descartar', 'ECG, perfil lipídico, control en 1 semana'],
            ['Chest discomfort on exertion', 'Stable angina - rule out', 'ECG, lipid profile, follow-up in 1 week']],
        [['Dolor de rodilla tras una caída', 'Esguince de rodilla', 'Hielo, compresión, AINEs'],
            ['Knee pain after a fall', 'Knee sprain', 'Ice, compression, NSAIDs']],
        [['Control de diabetes', 'Diabetes mellitus tipo 2', 'Continuar metformina, HbA1c en 3 meses'],
            ['Routine diabetes review', 'Type 2 diabetes mellitus', 'Continue metformin, HbA1c in 3 months']],
        [['Tos persistente en niño', 'Bronquitis viral', 'Nebulizaciones, control en 5 días'],
            ['Persistent cough in child', 'Viral bronchitis', 'Nebulisation, review in 5 days']],
    ];
    // ER cases: [patient, triage, status, minutes ago, arrival, complaint es, complaint en, vitals [T, HR, sys, dia, RR, SpO2, pain], disposition, diagnosis es, diagnosis en]
    private const ER_CASES = [
        [0, 2, 'in_treatment', 35, 'ambulance', 'Dolor torácico opresivo irradiado al brazo izquierdo', 'Crushing chest pain radiating to the left arm', [36.9, 108, 158, 96, 22, 94, 8], null, 'Síndrome coronario agudo - descartar', 'Acute coronary syndrome - rule out'],
        [1, 3, 'waiting', 25, 'walk_in', 'Dolor abdominal en fosa ilíaca derecha', 'Right lower quadrant abdominal pain', [38.1, 98, 124, 80, 18, 98, 7], null, null, null],
        [2, 4, 'waiting', 50, 'walk_in', 'Corte en la mano con vidrio', 'Hand laceration from glass', [36.6, 82, 120, 78, 16, 99, 4], null, null, null],
        [4, 3, 'observation', 140, 'walk_in', 'Crisis asmática', 'Asthma attack', [37.2, 112, 118, 74, 26, 91, 3], null, 'Crisis asmática moderada', 'Moderate asthma exacerbation'],
        [5, 5, 'waiting', 70, 'walk_in', 'Resfrío y congestión nasal', 'Common cold and nasal congestion', [37.4, 80, 116, 76, 16, 98, 2], null, null, null],
        [6, 2, 'closed', 300, 'ambulance', 'Accidente de tránsito, dolor de cadera', 'Road traffic accident, hip pain', [36.8, 104, 132, 84, 20, 97, 9], 'transferred', 'Fractura de cadera - traslado a trauma', 'Hip fracture - transferred to trauma centre'],
        [7, 4, 'closed', 420, 'walk_in', 'Esguince de tobillo', 'Ankle sprain', [36.5, 78, 122, 80, 16, 99, 5], 'discharged', 'Esguince de tobillo grado I', 'Grade I ankle sprain'],
        [8, 5, 'closed', 600, 'walk_in', 'Dolor de cabeza leve', 'Mild headache', [36.7, 76, 128, 82, 16, 98, 3], 'lwbs', null, null],
    ];

    private const T = [
        'demo' => ['Datos de ejemplo', 'Demo data'],
        'ward_general' => ['Sala general A', 'General Ward A'],
        'ward_pediatric' => ['Sala de pediatría', 'Pediatric Ward'],
        'ward_icu' => ['UCI cardiológica', 'Cardiac ICU'],
        'ward_er' => ['Emergencias', 'Emergency Room'],
        'floor_0' => ['Planta baja', 'Ground'],
        'floor_1' => ['1er piso', '1st'],
        'floor_2' => ['2do piso', '2nd'],
        'street' => ['Av. Principal', 'Demo Street'],
        'city' => ['Lima', 'Demo City'],
        'penicillin' => ['Penicilina', 'Penicillin'],
        'diabetes' => ['Diabetes tipo 2', 'Type 2 diabetes'],
        'insurance' => ['Seguro Salud Plus', 'MediCare Plus'],
        'spouse' => ['Cónyuge', 'Spouse'],
        'parent' => ['Padre / madre', 'Parent'],
        'sibling' => ['Hermano(a)', 'Sibling'],
        'follow_reason' => ['Control', 'Follow-up review'],
        'new_reason' => ['Consulta nueva', 'New consultation'],
        'exam' => ['Examen general sin hallazgos aparte del motivo de consulta.', 'General examination unremarkable apart from presenting complaint.'],
        'high_remark' => ['Por encima del rango de referencia - revisar con el médico', 'Above reference range - review with doctor'],
        'adm_reason' => ['Glucosa descompensada', 'Uncontrolled blood sugar'],
        'adm_diag' => ['Hiperglucemia, diabetes tipo 2', 'Hyperglycaemia, type 2 diabetes'],
        'normal' => ['Normal', 'Normal'],
    ];
    private const ES_FEMALE = ['María', 'Lucía', 'Valentina', 'Camila', 'Sofía', 'Ximena', 'Daniela', 'Gabriela', 'Andrea', 'Rosa'];
    private const ES_MALE = ['Mateo', 'Santiago', 'Diego', 'Alejandro', 'Nicolás', 'Gabriel', 'Jorge', 'Luis', 'Carlos', 'Miguel'];
    private const ES_LAST = ['García', 'Rodríguez', 'Quispe', 'Flores', 'Sánchez', 'Ramírez', 'Torres', 'Mendoza', 'Rojas', 'Vargas', 'Castillo', 'Huamán', 'Chávez', 'Díaz'];
    private const EN_FEMALE = ['Sarah', 'Emma', 'Olivia', 'Ava', 'Mia', 'Chloe', 'Grace', 'Lily', 'Ella', 'Zoe'];
    private const EN_MALE = ['James', 'Liam', 'Noah', 'Ethan', 'Lucas', 'Henry', 'Jack', 'Leo', 'Oscar', 'Owen'];
    private const EN_LAST = ['Smith', 'Johnson', 'Brown', 'Taylor', 'Wilson', 'Clark', 'Lewis', 'Walker', 'Hall', 'Young', 'King', 'Wright'];

    public function key(): string
    {
        return 'hospital';
    }

    public function label(): string
    {
        return 'Hospital (Patients, Doctors, Wards & Beds, Emergency Room, Appointments, Visits, Lab)';
    }

    public function dataTables(): array
    {
        return ['patients', 'doctors', 'hospital_departments', 'hospital_wards', 'appointments'];
    }

    /** SuperAdmin "Delete real data": the company's whole hospital, children first (+ its journal entries). */
    public function wipePlan(): array
    {
        return [
            ['journal' => 'hospital_payment', 'of' => 'hospital_payments'],
            ['journal' => 'hospital_invoice', 'of' => 'hospital_invoices'],
            ['table' => 'hospital_payments'],
            ['table' => 'hospital_invoice_items', 'parent' => ['invoice_id', 'hospital_invoices']],
            ['table' => 'hospital_invoices'],
            ['table' => 'lab_order_items', 'parent' => ['lab_order_id', 'lab_orders']],
            ['table' => 'lab_orders'],
            ['table' => 'prescription_items', 'parent' => ['prescription_id', 'prescriptions']],
            ['table' => 'prescriptions'],
            ['table' => 'er_cases'],
            ['table' => 'admissions'],
            ['table' => 'patient_visits'],
            ['table' => 'appointments'],
            ['table' => 'hospital_beds'],
            ['table' => 'hospital_wards'],
            ['table' => 'doctors'],
            ['table' => 'hospital_departments'],
            ['table' => 'lab_tests'],
            ['table' => 'patients'],
        ];
    }

    public function requiredTables(): array
    {
        return ['patients', 'doctors', 'hospital_departments', 'hospital_wards', 'hospital_beds', 'appointments',
            'patient_visits', 'lab_tests', 'lab_orders', 'lab_order_items', 'admissions'];
    }

    private function t(string $key): string
    {
        return self::T[$key][$this->es ? 0 : 1];
    }

    /** [first, last, gender] in the company's language. */
    private function person(DemoContext $ctx, ?string $gender = null): array
    {
        $gender = $gender ?: $ctx->pick(['male', 'female']);
        $first = $this->es ? ($gender === 'female' ? self::ES_FEMALE : self::ES_MALE) : ($gender === 'female' ? self::EN_FEMALE : self::EN_MALE);
        $last = $this->es ? self::ES_LAST : self::EN_LAST;
        $surname = $this->es ? $ctx->pick($last).' '.$ctx->pick($last) : $ctx->pick($last);

        return [$ctx->pick($first), $surname, $gender];
    }

    private function slug(string $s): string
    {
        return preg_replace('/[^a-z]+/', '.', trim(strtolower(\Illuminate\Support\Str::ascii($s))));
    }

    public function generate(DemoContext $ctx): array
    {
        $lang = DB::table('settings')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->value('default_language');
        $this->es = str_starts_with((string) $lang, 'es');
        $demo = $this->t('demo');

        // ---- Departments
        $deptIds = [];
        foreach (self::DEPARTMENTS as $d) {
            $deptIds[] = $ctx->insert('hospital_departments', [
                'name' => $this->es ? $d[0] : $d[1], 'code' => $d[2], 'location' => $this->es ? $d[3] : $d[4],
                'phone' => '555'.random_int(1000000, 9999999),
                'description' => $demo, 'is_active' => 1,
            ]);
        }

        // ---- Doctors
        $hours = json_encode(['mon' => ['09:00', '17:00'], 'tue' => ['09:00', '17:00'], 'wed' => ['09:00', '13:00'],
            'thu' => ['09:00', '17:00'], 'fri' => ['09:00', '15:00']]);
        $doctors = [];
        foreach (self::DOCTORS as $i => [$dept, $specEs, $specEn, $qual, $fee]) {
            [$first, $last] = $this->person($ctx);
            $doctors[] = [
                'id' => $ctx->insert('doctors', [
                    'name' => "Dr. $first $last",
                    'code' => 'DOC-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'department_id' => $deptIds[$dept],
                    'specialty' => $this->es ? $specEs : $specEn,
                    'qualification' => $qual,
                    'license_no' => ($this->es ? 'CMP-' : 'LIC-').random_int(100000, 999999),
                    'phone' => '555'.random_int(1000000, 9999999),
                    'email' => $this->slug("dr $first $last").'.demo'.random_int(1000, 9999).'@example.com',
                    'consultation_fee' => $fee,
                    'availability' => $hours,
                    'image' => null,
                    'is_active' => 1,
                    'notes' => $demo,
                ]),
                'dept' => $deptIds[$dept],
                'fee' => $fee,
            ];
        }

        // ---- Wards & beds (the last one = the Emergency Room's beds)
        $wards = [
            [$this->t('ward_general'), 0, 'general', $this->t('floor_0'), 60, 4, 'G'],
            [$this->t('ward_pediatric'), 1, 'general', $this->t('floor_1'), 70, 2, 'P'],
            [$this->t('ward_icu'), 2, 'icu', $this->t('floor_2'), 250, 2, 'U'],
            [$this->t('ward_er'), 4, 'emergency', $this->t('floor_0'), 90, 5, 'E'],
        ];
        $hasErType = $this->wardTypeAllowed('emergency');
        $wardIds = $bedIds = [];
        $bedCount = 0;
        foreach ($wards as $wi => [$name, $dept, $type, $floor, $rate, $beds, $prefix]) {
            if ($type === 'emergency' && ! $hasErType) {
                continue;
            }
            $wardId = $ctx->insert('hospital_wards', [
                'name' => $name, 'department_id' => $deptIds[$dept], 'type' => $type,
                'floor' => $floor, 'daily_rate' => $rate, 'is_active' => 1, 'notes' => $demo,
            ]);
            $wardIds[$wi] = $wardId;
            for ($b = 1; $b <= $beds; $b++) {
                // First bed of the general ward is taken by the admission below.
                $bedIds[$wi][] = $ctx->insert('hospital_beds', [
                    'ward_id' => $wardId,
                    'bed_number' => $prefix.'-'.str_pad((string) $b, 2, '0', STR_PAD_LEFT),
                    'status' => $wi === 0 && $b === 1 ? 'occupied' : ($wi === 0 && $b === 4 ? 'maintenance' : 'available'),
                    'daily_rate' => null,
                ]);
                $bedCount++;
            }
        }

        // ---- Lab tests
        $tests = [];
        foreach (self::LAB_TESTS as $lt) {
            $tests[] = [
                'id' => $ctx->insert('lab_tests', [
                    'name' => $this->es ? $lt[0] : $lt[1], 'code' => $lt[2], 'category' => $this->es ? $lt[3] : $lt[4],
                    'sample_type' => $this->es ? $lt[5] : $lt[6],
                    'unit' => $lt[7], 'normal_range' => $lt[8], 'price' => $lt[9],
                    'turnaround_hours' => $lt[10], 'is_active' => 1,
                ]),
                'name' => $this->es ? $lt[0] : $lt[1], 'unit' => $lt[7], 'range' => $lt[8], 'price' => $lt[9],
            ];
        }

        // ---- Patients
        $bloods = ['A+', 'A-', 'B+', 'O+', 'O-', 'AB+'];
        $patientIds = [];
        for ($i = 0; $i < 10; $i++) {
            [$first, $last, $gender] = $this->person($ctx);
            [$eFirst] = $this->person($ctx);
            $patientIds[] = $ctx->insert('patients', [
                'mrn' => Patient::generateMrn(),
                'name' => "$first $last",
                'gender' => $gender,
                'date_of_birth' => now()->subYears(random_int(4, 78))->subDays(random_int(0, 360))->toDateString(),
                'phone' => ($this->es ? '9' : '555').random_int(10000000, 99999999),
                'email' => $this->slug("$first $last").'.demo'.random_int(1000, 9999).'@example.com',
                'address' => $this->t('street').' '.random_int(100, 3000),
                'city' => $this->t('city'),
                'blood_group' => $ctx->pick($bloods),
                'allergies' => $i % 4 === 0 ? $this->t('penicillin') : null,
                'chronic_conditions' => $i === 3 ? $this->t('diabetes') : null,
                'emergency_contact_name' => "$eFirst $last",
                'emergency_contact_phone' => ($this->es ? '9' : '555').random_int(10000000, 99999999),
                'emergency_contact_relation' => $this->t($ctx->pick(['spouse', 'parent', 'sibling'])),
                'insurance_provider' => $i % 2 ? $this->t('insurance') : null,
                'insurance_number' => $i % 2 ? 'INS-'.random_int(100000, 999999) : null,
                'image' => null,
                'notes' => $demo,
                'status' => 'active',
            ]);
        }

        // ---- Appointments: completed/no-show in the past, scheduled ahead.
        // [patient, doctor, days offset, hour, type, status]
        $plan = [
            [0, 0, -14, 9, 'consultation', 'completed'],
            [1, 3, -10, 10, 'consultation', 'completed'],
            [2, 4, -7, 11, 'consultation', 'completed'],
            [3, 1, -5, 9, 'follow_up', 'completed'],
            [4, 2, -2, 14, 'consultation', 'completed'],
            [5, 0, -1, 15, 'consultation', 'no_show'],
            [6, 3, 2, 10, 'follow_up', 'confirmed'],
            [7, 2, 3, 11, 'consultation', 'scheduled'],
            [1, 3, 7, 9, 'follow_up', 'scheduled'],
            [0, 1, 4, 12, 'teleconsult', 'cancelled'],
        ];
        $appointments = [];
        foreach ($plan as $n => [$p, $d, $days, $hour, $type, $status]) {
            $at = now()->addDays($days)->setTime($hour, 0);
            $appointments[$n] = [
                'id' => $ctx->insert('appointments', [
                    'reference' => Appointment::nextReference('APT'),
                    'patient_id' => $patientIds[$p],
                    'doctor_id' => $doctors[$d]['id'],
                    'department_id' => $doctors[$d]['dept'],
                    'scheduled_at' => $at,
                    'duration_minutes' => 20,
                    'type' => $type,
                    'status' => $status,
                    'reason' => $type === 'follow_up' ? $this->t('follow_reason') : $this->t('new_reason'),
                    'fee' => $doctors[$d]['fee'],
                    'notes' => $demo,
                    'created_by' => $ctx->userId,
                    'created_at' => $at->copy()->subDays(3), 'updated_at' => $at->copy()->subDays(3),
                ]),
                'p' => $p, 'd' => $d, 'at' => $at, 'status' => $status,
            ];
        }

        // ---- Visits for the completed appointments
        $visits = [];
        $c = 0;
        foreach ($appointments as $a) {
            if ($a['status'] !== 'completed') {
                continue;
            }
            [$complaint, $diagnosis, $treatment] = self::COMPLAINTS[$c++ % count(self::COMPLAINTS)][$this->es ? 0 : 1];
            $visits[] = [
                'id' => $ctx->insert('patient_visits', [
                    'reference' => PatientVisit::nextReference('VIS'),
                    'patient_id' => $patientIds[$a['p']],
                    'doctor_id' => $doctors[$a['d']]['id'],
                    'appointment_id' => $a['id'],
                    'department_id' => $doctors[$a['d']]['dept'],
                    'visit_date' => $a['at']->copy()->addMinutes(10),
                    'type' => 'opd',
                    'temperature' => $c === 1 ? 38.4 : 36.8,
                    'pulse' => random_int(64, 96),
                    'bp_systolic' => random_int(110, 145),
                    'bp_diastolic' => random_int(70, 92),
                    'respiratory_rate' => random_int(14, 20),
                    'spo2' => random_int(95, 99),
                    'weight' => random_int(20, 95),
                    'height' => random_int(110, 190),
                    'complaint' => $complaint,
                    'examination' => $this->t('exam'),
                    'diagnosis' => $diagnosis,
                    'treatment_plan' => $treatment,
                    'follow_up_date' => $a['at']->copy()->addDays(14)->toDateString(),
                    'status' => 'completed',
                    'fee' => $doctors[$a['d']]['fee'],
                    'created_by' => $ctx->userId,
                    'created_at' => $a['at'], 'updated_at' => $a['at'],
                ]),
                'p' => $a['p'], 'd' => $a['d'], 'at' => $a['at'],
            ];
        }

        // ---- Lab orders on some visits
        // [visit index, test indexes, status, priority]
        $labPlan = [
            [0, [0], 'completed', 'routine'],
            [1, [2, 1], 'completed', 'urgent'],
            [3, [5, 1], 'completed', 'routine'],
            [4, [0, 3], 'sample_collected', 'routine'],
        ];
        $results = [
            0 => ['7.2', 'normal'], 1 => ['132', 'high'], 2 => ['228', 'high'],
            3 => [$this->t('normal'), 'normal'], 4 => ['2.1', 'normal'], 5 => ['7.4', 'high'],
        ];
        $labOrders = $labItems = 0;
        foreach ($labPlan as [$v, $testIdx, $status, $priority]) {
            if (! isset($visits[$v])) {
                continue;
            }
            $visit = $visits[$v];
            $total = 0;
            foreach ($testIdx as $t) {
                $total += $tests[$t]['price'];
            }
            $orderedAt = $visit['at']->copy()->addMinutes(30);
            $orderId = $ctx->insert('lab_orders', [
                'reference' => LabOrder::nextReference('LAB'),
                'patient_id' => $patientIds[$visit['p']],
                'doctor_id' => $doctors[$visit['d']]['id'],
                'visit_id' => $visit['id'],
                'ordered_at' => $orderedAt,
                'completed_at' => $status === 'completed' ? $orderedAt->copy()->addHours(6) : null,
                'priority' => $priority,
                'status' => $status,
                'total' => $total,
                'notes' => $demo,
                'created_by' => $ctx->userId,
            ]);
            $labOrders++;
            foreach ($testIdx as $t) {
                $done = $status === 'completed';
                $ctx->insert('lab_order_items', [
                    'lab_order_id' => $orderId,
                    'lab_test_id' => $tests[$t]['id'],
                    'test_name' => $tests[$t]['name'],
                    'price' => $tests[$t]['price'],
                    'result_value' => $done ? $results[$t][0] : null,
                    'unit' => $tests[$t]['unit'],
                    'normal_range' => $tests[$t]['range'],
                    'flag' => $done ? $results[$t][1] : null,
                    'remarks' => $done && $results[$t][1] === 'high' ? $this->t('high_remark') : null,
                ]);
                $labItems++;
            }
        }

        // ---- One current admission (general ward, bed 1 = occupied above)
        $ctx->insert('admissions', [
            'reference' => Admission::nextReference('ADM'),
            'patient_id' => $patientIds[3],
            'doctor_id' => $doctors[1]['id'],
            'ward_id' => $wardIds[0],
            'bed_id' => $bedIds[0][0],
            'department_id' => $deptIds[0],
            'admitted_at' => now()->subDays(2)->setTime(16, 30),
            'daily_rate' => $wards[0][4],
            'reason' => $this->t('adm_reason'),
            'diagnosis' => $this->t('adm_diag'),
            'status' => 'admitted',
            'created_by' => $ctx->userId,
        ]);

        // ---- Emergency Room: open cases on the board (ER beds taken) + closed ones.
        $erCount = 0;
        if (Schema::hasTable('er_cases') && isset($wardIds[3])) {
            $erDoctor = $doctors[5]['id'];
            $freeErBeds = $bedIds[3];
            foreach (self::ER_CASES as [$p, $triage, $status, $ago, $mode, $cEs, $cEn, $v, $disp, $dEs, $dEn]) {
                $arrived = now()->subMinutes($ago);
                $open = $status !== 'closed';
                $bed = $open && $status !== 'waiting' ? array_shift($freeErBeds) : null;
                $ctx->insert('er_cases', [
                    'reference' => ErCase::nextReference('ER'),
                    'patient_id' => $patientIds[$p],
                    'doctor_id' => $status === 'waiting' ? null : $erDoctor,
                    'bed_id' => $bed,
                    'arrived_at' => $arrived,
                    'arrival_mode' => $mode,
                    'triage_level' => $triage,
                    'complaint' => $this->es ? $cEs : $cEn,
                    'temperature' => $v[0], 'pulse' => $v[1], 'bp_systolic' => $v[2], 'bp_diastolic' => $v[3],
                    'respiratory_rate' => $v[4], 'spo2' => $v[5], 'pain_score' => $v[6],
                    'status' => $status,
                    'treatment_started_at' => $status === 'waiting' || $disp === 'lwbs' ? null : $arrived->copy()->addMinutes(min(20, $triage * 5)),
                    'diagnosis' => $this->es ? $dEs : $dEn,
                    'notes' => $demo,
                    'disposition' => $disp,
                    'closed_at' => $open ? null : $arrived->copy()->addMinutes(150),
                    'created_by' => $ctx->userId,
                    'created_at' => $arrived, 'updated_at' => $arrived,
                ]);
                if ($bed) {
                    DB::table('hospital_beds')->where('id', $bed)->update(['status' => 'occupied']);
                }
                $erCount++;
            }
        }

        return [
            'Departments' => count($deptIds),
            'Doctors' => count($doctors),
            'Wards' => count($wardIds),
            'Beds' => $bedCount,
            'Lab tests' => count($tests),
            'Patients' => count($patientIds),
            'Appointments' => count($appointments),
            'Visits' => count($visits),
            'Lab orders' => $labOrders,
            'Lab order items' => $labItems,
            'Admissions' => 1,
            'ER cases' => $erCount,
        ];
    }

    /** The ward type exists in the column's enum (older databases before 2026-10-21 have no 'emergency'). */
    private function wardTypeAllowed(string $type): bool
    {
        $col = DB::selectOne("SHOW COLUMNS FROM hospital_wards LIKE 'type'");

        return $col && str_contains((string) $col->Type, "'$type'");
    }
}
