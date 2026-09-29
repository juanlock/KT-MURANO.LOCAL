<?php

namespace App\Services\DemoData\Modules;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;

/**
 * Hospital examples: departments, doctors (with weekly clinic hours), wards
 * and beds, a lab test catalogue, patients (company-scoped), appointments
 * past and upcoming, OPD visits for the completed ones, lab orders with
 * results, and one current in-patient admission (its bed marked occupied).
 *
 * Only patients carry company_id in this module; departments, doctors,
 * wards, beds and lab tests are install-wide, which is why they are listed
 * in dataTables(): examples are only added while the hospital is empty.
 */
class HospitalModule extends DemoModule
{
    private const DEPARTMENTS = [
        ['General Medicine', 'GEN', 'Block A, Ground floor'],
        ['Pediatrics', 'PED', 'Block B, 1st floor'],
        ['Cardiology', 'CAR', 'Block A, 2nd floor'],
        ['Orthopedics', 'ORT', 'Block C, 1st floor'],
    ];
    // [department index, specialty, qualification, fee]
    private const DOCTORS = [
        [0, 'General Physician', 'MBBS', 40],
        [0, 'Internal Medicine', 'MBBS, MD', 55],
        [1, 'Pediatrician', 'MBBS, DCH', 50],
        [2, 'Cardiologist', 'MBBS, MD, DM (Cardiology)', 90],
        [3, 'Orthopedic Surgeon', 'MBBS, MS (Ortho)', 80],
    ];
    // [name, code, category, sample, unit, normal range, price, turnaround h]
    private const LAB_TESTS = [
        ['Complete Blood Count', 'CBC', 'Hematology', 'Blood', 'x10^9/L', '4.0-11.0', 15, 4],
        ['Fasting Blood Glucose', 'FBG', 'Biochemistry', 'Blood', 'mg/dL', '70-100', 8, 2],
        ['Lipid Profile', 'LIP', 'Biochemistry', 'Blood', 'mg/dL', '< 200', 25, 6],
        ['Urinalysis', 'URI', 'Clinical Pathology', 'Urine', null, 'Normal', 10, 3],
        ['Thyroid Stimulating Hormone', 'TSH', 'Endocrinology', 'Blood', 'mIU/L', '0.4-4.0', 20, 24],
        ['HbA1c', 'HBA', 'Biochemistry', 'Blood', '%', '4.0-5.6', 18, 24],
    ];
    private const COMPLAINTS = [
        ['Fever and sore throat for 3 days', 'Acute pharyngitis', 'Paracetamol, fluids, rest'],
        ['Chest discomfort on exertion', 'Stable angina - rule out', 'ECG, lipid profile, follow-up in 1 week'],
        ['Knee pain after a fall', 'Knee sprain', 'Ice, compression, NSAIDs'],
        ['Routine diabetes review', 'Type 2 diabetes mellitus', 'Continue metformin, HbA1c in 3 months'],
        ['Persistent cough in child', 'Viral bronchitis', 'Nebulisation, review in 5 days'],
    ];

    public function key(): string
    {
        return 'hospital';
    }

    public function label(): string
    {
        return 'Hospital (Patients, Doctors, Wards & Beds, Appointments, Visits, Lab)';
    }

    public function dataTables(): array
    {
        return ['patients', 'doctors', 'hospital_departments', 'hospital_wards', 'appointments'];
    }

    public function requiredTables(): array
    {
        return ['patients', 'doctors', 'hospital_departments', 'hospital_wards', 'hospital_beds', 'appointments',
            'patient_visits', 'lab_tests', 'lab_orders', 'lab_order_items', 'admissions'];
    }

    public function generate(DemoContext $ctx): array
    {
        // ---- Departments
        $deptIds = [];
        foreach (self::DEPARTMENTS as [$name, $code, $location]) {
            $deptIds[] = $ctx->insert('hospital_departments', [
                'name' => $name, 'code' => $code, 'location' => $location,
                'phone' => '555'.random_int(1000000, 9999999),
                'description' => 'Demo data', 'is_active' => 1,
            ]);
        }

        // ---- Doctors
        $hours = json_encode(['mon' => ['09:00', '17:00'], 'tue' => ['09:00', '17:00'], 'wed' => ['09:00', '13:00'],
            'thu' => ['09:00', '17:00'], 'fri' => ['09:00', '15:00']]);
        $doctors = [];
        foreach (self::DOCTORS as $i => [$dept, $specialty, $qual, $fee]) {
            [$first, $last] = $ctx->personName();
            $doctors[] = [
                'id' => $ctx->insert('doctors', [
                    'name' => "Dr. $first $last",
                    'code' => 'DOC-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'department_id' => $deptIds[$dept],
                    'specialty' => $specialty,
                    'qualification' => $qual,
                    'license_no' => 'LIC-'.random_int(100000, 999999),
                    'phone' => '555'.random_int(1000000, 9999999),
                    'email' => strtolower("dr.$first.$last.demo".random_int(1000, 9999).'@example.com'),
                    'consultation_fee' => $fee,
                    'availability' => $hours,
                    'image' => null,
                    'is_active' => 1,
                    'notes' => 'Demo data',
                ]),
                'dept' => $deptIds[$dept],
                'fee' => $fee,
            ];
        }

        // ---- Wards & beds
        $wards = [
            ['General Ward A', 0, 'general', 'Ground', 60, 4],
            ['Pediatric Ward', 1, 'general', '1st', 70, 2],
            ['Cardiac ICU', 2, 'icu', '2nd', 250, 2],
        ];
        $wardIds = $bedIds = [];
        $bedCount = 0;
        foreach ($wards as $wi => [$name, $dept, $type, $floor, $rate, $beds]) {
            $wardId = $ctx->insert('hospital_wards', [
                'name' => $name, 'department_id' => $deptIds[$dept], 'type' => $type,
                'floor' => $floor, 'daily_rate' => $rate, 'is_active' => 1, 'notes' => 'Demo data',
            ]);
            $wardIds[] = $wardId;
            for ($b = 1; $b <= $beds; $b++) {
                // First bed of the general ward is taken by the admission below.
                $bedIds[$wi][] = $ctx->insert('hospital_beds', [
                    'ward_id' => $wardId,
                    'bed_number' => strtoupper(substr($name, 0, 1)).'-'.str_pad((string) $b, 2, '0', STR_PAD_LEFT),
                    'status' => $wi === 0 && $b === 1 ? 'occupied' : ($wi === 0 && $b === 4 ? 'maintenance' : 'available'),
                    'daily_rate' => null,
                ]);
                $bedCount++;
            }
        }

        // ---- Lab tests
        $tests = [];
        foreach (self::LAB_TESTS as [$name, $code, $cat, $sample, $unit, $range, $price, $tat]) {
            $tests[] = [
                'id' => $ctx->insert('lab_tests', [
                    'name' => $name, 'code' => $code, 'category' => $cat, 'sample_type' => $sample,
                    'unit' => $unit, 'normal_range' => $range, 'price' => $price,
                    'turnaround_hours' => $tat, 'is_active' => 1,
                ]),
                'name' => $name, 'unit' => $unit, 'range' => $range, 'price' => $price,
            ];
        }

        // ---- Patients (company-scoped)
        $bloods = ['A+', 'A-', 'B+', 'O+', 'O-', 'AB+'];
        $patientIds = [];
        for ($i = 0; $i < 8; $i++) {
            [$first, $last] = $ctx->personName();
            [$eFirst] = $ctx->personName();
            $patientIds[] = $ctx->insert('patients', [
                'mrn' => Patient::generateMrn(),
                'name' => "$first $last",
                'gender' => in_array($first, ['Sarah', 'Fatima', 'Aisha', 'Nadia', 'Leila', 'Julia', 'Emma'], true) ? 'female' : 'male',
                'date_of_birth' => now()->subYears(random_int(4, 78))->subDays(random_int(0, 360))->toDateString(),
                'phone' => '555'.random_int(1000000, 9999999),
                'email' => strtolower("$first.$last.demo".random_int(1000, 9999).'@example.com'),
                'address' => random_int(1, 300).' Demo Street',
                'city' => 'Demo City',
                'blood_group' => $ctx->pick($bloods),
                'allergies' => $i % 4 === 0 ? 'Penicillin' : null,
                'chronic_conditions' => $i === 3 ? 'Type 2 diabetes' : null,
                'emergency_contact_name' => "$eFirst $last",
                'emergency_contact_phone' => '555'.random_int(1000000, 9999999),
                'emergency_contact_relation' => $ctx->pick(['Spouse', 'Parent', 'Sibling']),
                'insurance_provider' => $i % 2 ? 'MediCare Plus' : null,
                'insurance_number' => $i % 2 ? 'INS-'.random_int(100000, 999999) : null,
                'image' => null,
                'notes' => 'Demo data',
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
                    'reason' => $type === 'follow_up' ? 'Follow-up review' : 'New consultation',
                    'fee' => $doctors[$d]['fee'],
                    'notes' => 'Demo data',
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
            [$complaint, $diagnosis, $treatment] = self::COMPLAINTS[$c++ % count(self::COMPLAINTS)];
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
                    'examination' => 'General examination unremarkable apart from presenting complaint.',
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
            3 => ['Normal', 'normal'], 4 => ['2.1', 'normal'], 5 => ['7.4', 'high'],
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
                'notes' => 'Demo data',
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
                    'remarks' => $done && $results[$t][1] === 'high' ? 'Above reference range - review with doctor' : null,
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
            'reason' => 'Uncontrolled blood sugar',
            'diagnosis' => 'Hyperglycaemia, type 2 diabetes',
            'status' => 'admitted',
            'created_by' => $ctx->userId,
        ]);

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
        ];
    }
}
