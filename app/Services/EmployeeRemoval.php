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
 *  - open helpdesk tickets assigned to the login: unassigned.
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
                'sales_agents' => 0, 'bookings_unassigned' => 0, 'tickets_unassigned' => 0, 'assets_to_collect' => []];

            DB::table('employees')->where('id', $e->id)->update([
                'deleted_at' => now(), 'leaving_date' => $e->leaving_date ?: $today, 'updated_at' => now(),
            ]);

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

    /** One line for the admin, e.g. "Login disabled · 2 open service jobs unassigned · collect: Laptop (A-12)". */
    public static function summary(array $d): string
    {
        return implode(' · ', array_filter([
            $d['login_disabled'] ? 'login disabled and signed out' : null,
            $d['jobs_unassigned'] ? "{$d['jobs_unassigned']} open service job(s) unassigned" : null,
            $d['bookings_unassigned'] ? "{$d['bookings_unassigned']} upcoming booking(s) without staff" : null,
            $d['tickets_unassigned'] ? "{$d['tickets_unassigned']} helpdesk ticket(s) unassigned" : null,
            $d['sales_agents'] ? 'sales agent deactivated' : null,
            $d['assets_to_collect'] ? 'collect: '.implode(', ', $d['assets_to_collect']) : null,
        ]));
    }
}
