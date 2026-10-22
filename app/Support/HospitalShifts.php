<?php

namespace App\Support;

use App\Models\Permission;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hospital staff shifts (user's rules, 2026-10-21): three 8-hour shifts --
 * morning 07-15, afternoon 15-23, night 23-07 -- and the emergency doctor's
 * 24-hour guard 07-07. Each 8-hour shift has 3 specialists, 1 nurse
 * supervisor and 4 nurses; the guard has 1 emergency doctor. A shift belongs
 * to the date it starts on (the night of the 21st ends on the 22nd at 07:00).
 */
class HospitalShifts
{
    /** key => [start hour, length hours] */
    public const SHIFTS = [
        'morning' => [7, 8],
        'afternoon' => [15, 8],
        'night' => [23, 8],
        'er24' => [7, 24],
    ];

    /** position => [shifts it works, people per shift, doctor|nurse] */
    public const POSITIONS = [
        'er_doctor' => [['er24'], 1, 'doctor'],
        'specialist' => [['morning', 'afternoon', 'night'], 3, 'doctor'],
        'nurse_supervisor' => [['morning', 'afternoon', 'night'], 1, 'nurse'],
        'nurse' => [['morning', 'afternoon', 'night'], 4, 'nurse'],
    ];

    /** [start, end] of a shift on a date. */
    public static function window(string $date, string $shift): array
    {
        [$h, $len] = self::SHIFTS[$shift] ?? self::SHIFTS['morning'];
        $start = Carbon::parse($date)->setTime($h, 0);

        return [$start, $start->copy()->addHours($len)];
    }

    /** The [date, shift] pairs running at $at (the 8-hour one and the 24-hour guard). */
    public static function current(?Carbon $at = null): array
    {
        $at = $at ?: now();
        $out = [];
        foreach (self::SHIFTS as $shift => [$h]) {
            foreach ([$at->copy()->subDay()->toDateString(), $at->toDateString()] as $date) {
                [$s, $e] = self::window($date, $shift);
                if ($at >= $s && $at < $e) {
                    $out[] = [$date, $shift];
                }
            }
        }

        return $out;
    }

    /** Does the user's role hold any of these permissions? (SuperAdmin: yes.) */
    public static function can($user, string ...$perms): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->type === 'SuperAdmin') {
            return true;
        }
        foreach ($perms as $p) {
            $perm = Permission::where('name', $p)->first();
            if ($perm && $user->hasRole($perm->roles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Patients in the hospital now (acting company): open admissions (ward /
     * bed) and open ER cases (waiting room or ER bed). One row per patient:
     * patient_id, name, mrn, where (label), area (ward | er_bed | waiting_room),
     * admission_id, er_case_id, triage_level, treatment_id (the active one).
     */
    public static function census(): \Illuminate\Support\Collection
    {
        $rows = collect();
        $admissions = \App\Models\Admission::with('patient', 'ward', 'bed')->whereNull('deleted_at')->where('status', 'admitted')->get();
        foreach ($admissions as $a) {
            if (! $a->patient) {
                continue;
            }
            $rows[$a->patient_id] = [
                'patient_id' => $a->patient_id, 'name' => $a->patient->name, 'mrn' => $a->patient->mrn,
                'allergies' => $a->patient->allergies, 'area' => 'ward',
                'where' => trim(optional($a->ward)->name.' · '.optional($a->bed)->bed_number, ' ·'),
                'admission_id' => $a->id, 'er_case_id' => null, 'triage_level' => null, 'since' => optional($a->admitted_at)->toIso8601String(),
            ];
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('er_cases')) {
            foreach (\App\Models\ErCase::with('patient', 'bed')->whereNull('deleted_at')->where('status', '!=', 'closed')->get() as $c) {
                if (! $c->patient || isset($rows[$c->patient_id])) {
                    continue;
                }
                $rows[$c->patient_id] = [
                    'patient_id' => $c->patient_id, 'name' => $c->patient->name, 'mrn' => $c->patient->mrn,
                    'allergies' => $c->patient->allergies, 'area' => $c->bed_id ? 'er_bed' : 'waiting_room',
                    'where' => $c->bed_id ? optional($c->bed)->bed_number : null,
                    'admission_id' => null, 'er_case_id' => $c->id, 'triage_level' => $c->triage_level, 'since' => optional($c->arrived_at)->toIso8601String(),
                ];
            }
        }
        $treatments = \App\Models\HmsTreatment::whereNull('deleted_at')->where('status', 'active')
            ->whereIn('patient_id', $rows->keys())->pluck('id', 'patient_id');

        return $rows->map(fn ($r) => $r + ['treatment_id' => $treatments[$r['patient_id']] ?? null])->values();
    }

    /** The user's employee id and doctor ids (a doctor is linked to an employee, the employee to the login). */
    public static function staffOf($user): array
    {
        $employeeId = $user ? DB::table('employees')->where('user_id', $user->id)->whereNull('deleted_at')->value('id') : null;
        $doctorIds = $employeeId ? DB::table('doctors')->where('employee_id', $employeeId)->whereNull('deleted_at')->pluck('id')->all() : [];

        return [$employeeId ? (int) $employeeId : null, array_map('intval', $doctorIds)];
    }
}
