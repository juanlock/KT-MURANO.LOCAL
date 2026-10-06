<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Header -> "Clock in / Clock out": an employee records their OWN attendance
 * for today (permission attendance_self). Clock in creates today's attendance
 * row (clock_in now, clock_in_out = 1 while clocked in); Clock out closes it
 * (clock_out, total_work, late / early / overtime against their office shift).
 * Managers record anyone's day in HRM -> Attendance as before.
 */
class AttendanceSelfController extends Controller
{
    private function myEmployee(Request $request)
    {
        $user = $request->user('api');
        $ok = $user && ($user->type === 'SuperAdmin' || (bool) $user->roles()->first()?->permissions->contains('name', 'attendance_self'));
        abort_unless($ok, 403);
        $emp = DB::table('employees')->where('user_id', $user->id)->whereNull('deleted_at')->first(['id', 'company_id', 'office_shift_id', 'firstname', 'lastname']);
        abort_unless($emp, 422, 'Your user is not linked to an employee record.');

        return $emp;
    }

    /** Today's open attendance of the employee (clocked in, not out), if any. */
    private function openToday(int $employeeId)
    {
        return DB::table('attendances')->where('employee_id', $employeeId)->whereNull('deleted_at')
            ->where('date', now()->toDateString())->where('clock_in_out', 1)->orderByDesc('id')->first();
    }

    public function status(Request $request)
    {
        $emp = $this->myEmployee($request);
        $open = $this->openToday((int) $emp->id);
        $todayDone = DB::table('attendances')->where('employee_id', $emp->id)->whereNull('deleted_at')
            ->where('date', now()->toDateString())->where('clock_in_out', 0)->orderByDesc('id')->first(['clock_in', 'clock_out', 'total_work']);

        return response()->json([
            'clocked_in' => (bool) $open,
            'since' => $open->clock_in ?? null,
            'last' => $todayDone,
        ]);
    }

    public function clock(Request $request)
    {
        $emp = $this->myEmployee($request);
        $now = now();
        $open = $this->openToday((int) $emp->id);
        $shift = $emp->office_shift_id ? DB::table('office_shifts')->where('id', $emp->office_shift_id)->first() : null;
        $day = strtolower($now->englishDayOfWeek);
        $shiftIn = $shift ? self::clock24($shift->{$day.'_in'} ?? null) : null;
        $shiftOut = $shift ? self::clock24($shift->{$day.'_out'} ?? null) : null;

        if (! $open) {
            DB::table('attendances')->insert([
                'user_id' => $request->user('api')->id,
                'employee_id' => $emp->id,
                'company_id' => $emp->company_id,
                'date' => $now->toDateString(),
                'clock_in' => $now->format('H:i'),
                'clock_in_ip' => (string) $request->ip(),
                'clock_out' => '',
                'clock_out_ip' => '',
                'clock_in_out' => 1,
                'late_time' => $shiftIn && $now->format('H:i') > $shiftIn ? self::diff($shiftIn, $now->format('H:i')) : '00:00',
                'depart_early' => '00:00',
                'overtime' => '00:00',
                'total_work' => '00:00',
                'total_rest' => '00:00',
                'status' => 'present',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return response()->json(['success' => true, 'clocked_in' => true, 'time' => $now->format('H:i')]);
        }

        $out = $now->format('H:i');
        DB::table('attendances')->where('id', $open->id)->update([
            'clock_out' => $out,
            'clock_out_ip' => (string) $request->ip(),
            'clock_in_out' => 0,
            'total_work' => self::diff($open->clock_in, $out),
            'depart_early' => $shiftOut && $out < $shiftOut ? self::diff($out, $shiftOut) : '00:00',
            'overtime' => $shiftOut && $out > $shiftOut ? self::diff($shiftOut, $out) : '00:00',
            'updated_at' => $now,
        ]);

        return response()->json(['success' => true, 'clocked_in' => false, 'time' => $out, 'total_work' => self::diff($open->clock_in, $out)]);
    }

    /** "09:00AM" / "17:00PM" / "05:00PM" -> "HH:MM" (24 h), null when empty. */
    private static function clock24(?string $t): ?string
    {
        if (! $t || ! preg_match('/^\s*(\d{1,2}):(\d{2})\s*(AM|PM)?/i', $t, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $ap = strtoupper($m[3] ?? '');
        if ($ap === 'PM' && $h < 12) {
            $h += 12;
        } elseif ($ap === 'AM' && $h === 12) {
            $h = 0;
        }

        return sprintf('%02d:%s', $h, $m[2]);
    }

    /** "HH:MM" from $a to $b (same day). */
    private static function diff(string $a, string $b): string
    {
        $mins = max(0, Carbon::createFromFormat('H:i', substr($a, 0, 5))->diffInMinutes(Carbon::createFromFormat('H:i', substr($b, 0, 5)), false));

        return sprintf('%02d:%02d', intdiv((int) $mins, 60), (int) $mins % 60);
    }
}
