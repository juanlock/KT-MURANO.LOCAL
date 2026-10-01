<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Opening hours + booked time of a company, shared by the public "Make an
 * appointment" form (PublicBookingController) and the staff booking screens
 * (BookingController::availability -> components/BookingWhenFields.vue), so
 * both pickers offer and block the same time slots.
 *
 * Bookings carry no company_id: a booking belongs to the company of its customer.
 */
class BookingAvailability
{
    /**
     * Settings -> Defaults -> Booking -> Online booking hours.
     * @return array{open: string, close: string, slot: int, closed: int[], capacity: int}
     */
    public static function hours(int $cid): array
    {
        $s = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')
            ->first(['booking_open_time', 'booking_close_time', 'booking_slot_minutes', 'booking_closed_days', 'booking_capacity']);
        $open = ($s->booking_open_time ?? null) ?: '09:00';
        $close = ($s->booking_close_time ?? null) ?: '19:00';
        if ($close <= $open) { // misconfigured -> fall back to the defaults
            [$open, $close] = ['09:00', '19:00'];
        }

        return [
            'open' => $open,
            'close' => $close,
            'slot' => in_array((int) ($s->booking_slot_minutes ?? 30), [15, 30, 45, 60], true) ? (int) $s->booking_slot_minutes : 30,
            'closed' => collect(explode(',', (string) ($s->booking_closed_days ?? '')))
                ->filter(fn ($d) => $d !== '' && ctype_digit($d))->map(fn ($d) => (int) $d)->values()->all(),
            // Settings -> Defaults -> Booking -> Appointments at the same time
            'capacity' => max(1, (int) ($s->booking_capacity ?? 1)),
        ];
    }

    /**
     * Booked time per day (not cancelled) as [startMin, endMin] pairs -- only
     * times, nothing about who booked. A missing end time counts as 60 minutes.
     *
     * @param  int|null  $excludeId  booking being edited (its own time isn't "taken")
     * @return array<string, array<int, array{0: int, 1: int}>>
     */
    public static function busy(int $cid, string $from, string $to, ?int $excludeId = null, bool $lock = false): array
    {
        $q = DB::table('bookings')->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->whereIn('customer_id', DB::table('clients')->where('company_id', $cid)->select('id'))
            ->whereBetween('booking_date', [$from, $to])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));
        if ($lock) {
            $q->lockForUpdate();
        }
        $toMin = fn (?string $t) => $t ? ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2) : null;
        $out = [];
        foreach ($q->get(['booking_date', 'booking_time', 'booking_end_time']) as $b) {
            $s = $toMin($b->booking_time);
            if ($s === null) {
                continue;
            }
            $e = $toMin($b->booking_end_time);
            $out[substr((string) $b->booking_date, 0, 10)][] = [$s, $e !== null && $e > $s ? $e : $s + 60];
        }

        return $out;
    }

    /**
     * Most bookings running at the same moment inside [from, to). Checked at
     * `from` and at every booking start inside the window -- the only points
     * where the count can go up. Same logic as maxConcurrent() in the pickers' JS.
     * @param  array<int, array{0: int, 1: int}>  $intervals
     */
    public static function maxConcurrent(array $intervals, int $from, int $to): int
    {
        $points = [$from];
        foreach ($intervals as [$s]) {
            if ($s > $from && $s < $to) {
                $points[] = $s;
            }
        }
        $max = 0;
        foreach ($points as $p) {
            $max = max($max, count(array_filter($intervals, fn ($b) => $b[0] <= $p && $b[1] > $p)));
        }

        return $max;
    }
}
