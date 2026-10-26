<?php

namespace App\Services;

use App\Support\UserAccess;
use Illuminate\Support\Facades\DB;

/**
 * Removing an employee (user's rule, 2026-10-07): ONE transaction -- all of it
 * happens or none of it does. The employee leaves, their login is disabled
 * and signed out everywhere, and what was waiting on them is released for
 * someone else, never deleted:
 *  - employee: soft-deleted, leaving date = today (when empty);
 *  - login (users): disabled + every token / session revoked (UserAccess);
 *  - service technician record(s): inactive; their open service jobs unassigned;
 *  - sales agent record(s) of the login: inactive;
 *  - upcoming bookings (pending / confirmed) of the login: staff cleared + a note;
 *  - open helpdesk tickets assigned to the login: unassigned;
 *  - a doctor (2026-10-26): see releaseDoctor() -- appointments to "To schedule", inpatients, roster.
 * Kept as history: attendance, payroll, leaves, completed jobs and bookings,
 * sales. Assets still assigned are reported (they must be collected), not changed.
 */
class EmployeeRemoval
{
    /** @return array what was done (shown to the admin), or null when the employee was already removed */
    public static function remove(int $employeeId, string $byName = ''): ?array
    {
        return DB::transaction(function () use ($employeeId, $byName) {
            $e = DB::table('employees')->where('id', $employeeId)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $e) {
                return null;
            }
            $today = now()->toDateString();
            $name = trim($e->firstname.' '.$e->lastname);
            $note = "[{$today}] {$name} was removed from employees".($byName ? " by {$byName}" : '').' -- reassign.';
            $done = ['employee' => $name, 'login_disabled' => false, 'sessions_closed' => 0, 'technicians' => 0, 'jobs_unassigned' => 0,
                'sales_agents' => 0, 'bookings_unassigned' => 0, 'tickets_unassigned' => 0, 'assets_to_collect' => [],
                'doctor' => false, 'appointments_to_reschedule' => 0, 'requests_unassigned' => 0, 'inpatients_without_doctor' => 0, 'shifts_removed' => 0,
                // The summary speaks the company's language (inside the app it wins, 2026-10-19).
                'es' => DB::table('settings')->where('company_id', $e->company_id)->whereNull('deleted_at')->value('default_language') === 'es'];

            DB::table('employees')->where('id', $e->id)->update([
                'deleted_at' => now(), 'leaving_date' => $e->leaving_date ?: $today, 'updated_at' => now(),
            ]);

            self::releaseDoctor($e, $done);

            // Field work: technician record(s) off, their open jobs back to "unassigned".
            $techIds = DB::table('service_technicians')->where('employee_id', $e->id)->whereNull('deleted_at')->pluck('id');
            if ($techIds->isNotEmpty()) {
                $done['technicians'] = DB::table('service_technicians')->whereIn('id', $techIds)->update(['is_active' => 0, 'updated_at' => now()]);
                $done['jobs_unassigned'] = DB::table('service_jobs')->whereIn('technician_id', $techIds)->whereNull('deleted_at')
                    ->whereNotIn('status', ['completed', 'cancelled'])->update(['technician_id' => null, 'updated_at' => now()]);
            }

            if ($e->user_id) {
                $uid = (int) $e->user_id;
                $done['sales_agents'] = DB::table('sales_agents')->where('user_id', $uid)->whereNull('deleted_at')->where('is_active', 1)
                    ->update(['is_active' => 0, 'updated_at' => now()]);

                // Upcoming appointments keep their customer and time; only the staff is cleared.
                $bookings = DB::table('bookings')->where('sales_person_id', $uid)->whereNull('deleted_at')
                    ->whereIn('status', ['pending', 'confirmed'])->where('booking_date', '>=', $today)->get(['id', 'notes']);
                foreach ($bookings as $b) {
                    DB::table('bookings')->where('id', $b->id)->update([
                        'sales_person_id' => null, 'notes' => trim(($b->notes ? $b->notes."\n" : '').$note), 'updated_at' => now(),
                    ]);
                }
                $done['bookings_unassigned'] = $bookings->count();

                $done['tickets_unassigned'] = DB::table('helpdesk_tickets')->where('assigned_to', $uid)
                    ->whereNotIn('status', ['closed', 'resolved'])->update(['assigned_to' => null, 'updated_at' => now()]);

                $done['assets_to_collect'] = DB::table('asset_assignments as aa')->join('assets as a', 'a.id', '=', 'aa.asset_id')
                    ->where('aa.user_id', $uid)->where('aa.status', 'assigned')->whereNull('aa.deleted_at')
                    ->get(['a.name', 'a.tag'])->map(fn ($a) => trim($a->name.($a->tag ? " ({$a->tag})" : '')))->all();

                $done['sessions_closed'] = UserAccess::disable($uid);
                $done['login_disabled'] = true;
            }

            return $done;
        });
    }

    /**
     * A doctor leaving (hospital / specialist clinic, user's rule 2026-10-26) -- same transaction:
     *  - doctor record(s) inactive: out of the schedule, the patient portal and the pickers (history kept);
     *  - appointments from today not started yet (scheduled / confirmed, no check-in): cancelled with a
     *    note, each one a "reassign" request in reception's "To schedule" panel, and the patient is emailed
     *    after the commit (reception will call to give another doctor);
     *  - pending follow-ups / referrals for this doctor: kept, without doctor;
     *  - inpatients: no attending doctor (Control tower alert) -- shifts from today on (as doctor or
     *    employee): removed from the roster.
     */
    private static function releaseDoctor(object $e, array &$done): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('doctors')) {
            return; // no hospital module in this install
        }
        $today = now()->toDateString();
        $es = $done['es'];
        $docs =DB::table('doctors')->where('employee_id', $e->id)->whereNull('deleted_at')->get(['id', 'name', 'specialty']);

        // Roster rows from today on (a nurse too): they won't be there.
        $done['shifts_removed'] = ! \Illuminate\Support\Facades\Schema::hasTable('hms_staff_shifts') ? 0 : DB::table('hms_staff_shifts')->whereNull('deleted_at')->whereDate('shift_date', '>=', $today)
            ->where(fn ($q) => $q->where('employee_id', $e->id)->when($docs->isNotEmpty(), fn ($w) => $w->orWhereIn('doctor_id', $docs->pluck('id'))))
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        if ($docs->isEmpty()) {
            return;
        }
        $ids = $docs->pluck('id');
        $done['doctor'] = true;
        DB::table('doctors')->whereIn('id', $ids)->update(['is_active' => 0, 'updated_at' => now()]);

        $byDoctor = $docs->keyBy('id');
        $appts = DB::table('appointments')->whereIn('doctor_id', $ids)->whereNull('deleted_at')
            ->where('scheduled_at', '>=', now()->startOfDay())->whereIn('status', ['scheduled', 'confirmed'])
            ->where(fn ($q) => $q->whereNull('flow_status')->orWhere('flow_status', ''))
            ->get(['id', 'company_id', 'patient_id', 'doctor_id', 'scheduled_at', 'notes']);
        foreach ($appts as $a) {
            $doc = $byDoctor[$a->doctor_id];
            $when = \Carbon\Carbon::parse($a->scheduled_at)->format('d/m H:i');
            $why = $es ? "Cita del {$when} con {$doc->name}: su médico fue dado de baja ({$today}) -- darle otro médico"
                : "Appointment on {$when} with {$doc->name}: the doctor was removed ({$today}) -- give another doctor";
            DB::table('appointments')->where('id', $a->id)->update([
                'status' => 'cancelled', 'notes' => trim(($a->notes ? $a->notes."\n" : '').$why), 'updated_at' => now(),
            ]);
            DB::table('hms_appointment_requests')->insert([
                'company_id' => $a->company_id, 'patient_id' => $a->patient_id, 'doctor_id' => null, 'from_doctor_id' => $a->doctor_id,
                'kind' => 'reassign', 'wanted_on' => substr((string) $a->scheduled_at, 0, 10), 'reason' => $why,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $done['appointments_to_reschedule'] = $appts->count();

        $done['requests_unassigned'] = DB::table('hms_appointment_requests')->whereIn('doctor_id', $ids)->where('status', 'pending')
            ->update(['doctor_id' => null, 'updated_at' => now()]);
        $done['inpatients_without_doctor'] = DB::table('admissions')->whereIn('doctor_id', $ids)->where('status', 'admitted')->whereNull('deleted_at')
            ->update(['doctor_id' => null, 'updated_at' => now()]);

        // The patients hear it only if all of this is saved.
        $notify = $appts->map(fn ($a) => [(int) $a->company_id, (int) $a->id])->all();
        DB::afterCommit(function () use ($notify) {
            foreach ($notify as [$cid, $aid]) {
                try {
                    \App\Services\BookingNotifier::clinicRescheduleNotice($cid, $aid);
                } catch (\Throwable $ex) {
                    \Illuminate\Support\Facades\Log::warning('Reschedule notice not sent', ['appointment_id' => $aid, 'error' => $ex->getMessage()]);
                }
            }
        });
    }

    /** One line for the admin, in the company's language, e.g. "Login disabled · 2 open service jobs unassigned · collect: Laptop (A-12)". */
    public static function summary(array $d): string
    {
        $es = $d['es'] ?? (app()->getLocale() === 'es');
        $t = fn ($en, $sp) => $es ? $sp : $en;

        return implode(' · ', array_filter([
            ! empty($d['doctor']) ? $t('doctor taken off the schedule and the portal', 'médico retirado de la agenda y del portal') : null,
            ! empty($d['appointments_to_reschedule']) ? $t("{$d['appointments_to_reschedule']} appointment(s) moved to \"To schedule\" (patients emailed)",
                "{$d['appointments_to_reschedule']} cita(s) pasaron a \"Por agendar\" (se avisó a los pacientes por correo)") : null,
            ! empty($d['requests_unassigned']) ? $t("{$d['requests_unassigned']} follow-up / referral(s) without doctor", "{$d['requests_unassigned']} control(es) / interconsulta(s) sin médico") : null,
            ! empty($d['inpatients_without_doctor']) ? $t("{$d['inpatients_without_doctor']} inpatient(s) without attending doctor", "{$d['inpatients_without_doctor']} hospitalizado(s) sin médico tratante") : null,
            ! empty($d['shifts_removed']) ? $t("{$d['shifts_removed']} shift(s) removed from the roster", "{$d['shifts_removed']} turno(s) quitados del rol") : null,
            $d['login_disabled'] ? $t('login disabled and signed out', 'login desactivado y desconectado') : null,
            $d['jobs_unassigned'] ? $t("{$d['jobs_unassigned']} open service job(s) unassigned", "{$d['jobs_unassigned']} trabajo(s) de servicio sin asignar") : null,
            $d['bookings_unassigned'] ? $t("{$d['bookings_unassigned']} upcoming booking(s) without staff", "{$d['bookings_unassigned']} reserva(s) futuras sin personal") : null,
            $d['tickets_unassigned'] ? $t("{$d['tickets_unassigned']} helpdesk ticket(s) unassigned", "{$d['tickets_unassigned']} ticket(s) de helpdesk sin asignar") : null,
            $d['sales_agents'] ? $t('sales agent deactivated', 'vendedor desactivado') : null,
            $d['assets_to_collect'] ? $t('collect: ', 'recoger: ').implode(', ', $d['assets_to_collect']) : null,
        ]));
    }
}
