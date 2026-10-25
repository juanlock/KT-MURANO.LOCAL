<?php

namespace App\Support;

use App\Models\ErCase;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * Example arrivals for the admission window + triage (user's request,
 * 2026-10-25), so the triage nurse has someone to call:
 *  - two emergencies registered at the window, waiting for triage (one with
 *    private insurance, one without financial data yet);
 *  - one patient referred for surgery, with insurance, waiting for a bed.
 * Used by the hospital demo and by the Vitalis migration. Safe to run twice
 * (skipped when the company already has the examples).
 */
class HospitalTriageExamples
{
    public const MARK = 'Triage example';

    /** @param callable|null $tag fn(string $table, int $id) -- the demo tags its rows */
    public static function seed(int $companyId, bool $es = true, ?callable $tag = null): int
    {
        if (ErCase::withoutGlobalScope('company')->where('company_id', $companyId)->where('notes', self::MARK)->whereNull('deleted_at')->exists()) {
            return 0;
        }
        $rows = [
            // [name, gender, age, minutes ago, pathway, complaint es, complaint en, money, referral]
            ['Jorge Salazar Medina', 'male', 58, 25, 'emergency', 'Dolor de pecho y falta de aire', 'Chest pain and shortness of breath',
                ['payer_type' => 'private', 'insurer' => 'Rímac Seguros', 'policy_no' => 'RX-204581'], null],
            ['Rosa Huamán Ccori', 'female', 34, 12, 'emergency', 'Fiebre alta y dolor de cabeza desde ayer', 'High fever and headache since yesterday',
                [], null],
            ['Alberto Ríos Paredes', 'male', 46, 40, 'scheduled', 'Colecistectomía laparoscópica programada', 'Scheduled laparoscopic cholecystectomy',
                ['payer_type' => 'private', 'insurer' => 'Pacífico Seguros', 'policy_no' => 'PS-778120', 'authorization_no' => 'AUT-55021'],
                ['Dr. Manuel Ramos (cirujano)', $es ? 'Colecistectomía laparoscópica' : 'Laparoscopic cholecystectomy']],
        ];
        $n = 0;
        foreach ($rows as [$name, $gender, $age, $ago, $pathway, $cEs, $cEn, $money, $ref]) {
            $patient = Patient::withoutGlobalScope('company')->where('company_id', $companyId)->where('name', $name)->first();
            if (! $patient) {
                $patient = new Patient([
                    'company_id' => $companyId, 'mrn' => Patient::generateMrn(), 'name' => $name, 'gender' => $gender,
                    'date_of_birth' => now()->subYears($age)->subDays(40)->toDateString(), 'phone' => '9'.random_int(10000000, 99999999),
                    'insurance_provider' => $money['insurer'] ?? null, 'insurance_number' => $money['policy_no'] ?? null,
                    'notes' => self::MARK, 'status' => 'active',
                ]);
                $patient->company_id = $companyId;
                $patient->save(); // the hook gives the patient its customer + login
                $tag && $tag('patients', $patient->id);
            }
            $arrived = now()->subMinutes($ago);
            $case = new ErCase([
                'reference' => self::nextReference($companyId), 'patient_id' => $patient->id, 'location' => 'waiting_room',
                'arrived_at' => $arrived, 'arrival_mode' => $pathway === 'scheduled' ? 'referral' : 'walk_in',
                'complaint' => $es ? $cEs : $cEn, 'status' => 'waiting', 'notes' => self::MARK,
                'pathway' => $pathway, 'triage_status' => $pathway === 'scheduled' ? 'skipped' : 'pending',
                'registration_status' => ErCase::financialComplete($money) ? 'done' : 'pending',
                'referred_by' => $ref[0] ?? null, 'procedure' => $ref[1] ?? null, 'planned_at' => $ref ? now()->addHours(3)->startOfHour() : null,
            ] + $money);
            $case->company_id = $companyId;
            $case->created_at = $arrived;
            $case->save();
            $tag && $tag('er_cases', $case->id);
            $n++;
        }

        return $n;
    }

    /** ER-YYYYMMDD-NNNN within the company (the model's helper needs an acting company). */
    private static function nextReference(int $companyId): string
    {
        $prefix = 'ER-'.now()->format('Ymd').'-';
        $last = DB::table('er_cases')->where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');

        return $prefix.str_pad((string) ($last ? ((int) substr($last, -4)) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }
}
