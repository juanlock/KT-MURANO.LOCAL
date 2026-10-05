<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\BookingNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reminder emails before appointments, for the companies with Settings ->
 * Booking -> Reminders on. booking_reminder_hours ("24,1") says how long
 * before; each one goes once (bookings.reminders_sent keeps the hours sent).
 * A booking made late (e.g. 3 h before) gets only the nearest reminder, not
 * every one it already passed. Pending and confirmed bookings only.
 */
class BookingsSendReminders extends Command
{
    protected $signature = 'bookings:send-reminders {--dry-run : List what would be sent}';

    protected $description = 'Email appointment reminders to customers and staff';

    public function handle(): int
    {
        $now = Carbon::now();
        $total = 0;
        $companies = DB::table('settings')->whereNull('deleted_at')->where('booking_reminders_enabled', 1)->get(['company_id', 'booking_reminder_hours']);
        foreach ($companies as $s) {
            $hours = collect(explode(',', (string) $s->booking_reminder_hours))->map(fn ($h) => (int) $h)->filter(fn ($h) => $h > 0)->unique()->sort()->values();
            if ($hours->isEmpty() || ! \App\Support\ModuleFlags::enabled((int) $s->company_id, 'bookings')) {
                continue;
            }
            $bookings = Booking::whereNull('deleted_at')->whereIn('status', ['pending', 'confirmed'])
                ->whereIn('customer_id', DB::table('clients')->where('company_id', $s->company_id)->select('id'))
                ->whereBetween('booking_date', [$now->toDateString(), $now->copy()->addHours($hours->max())->toDateString()])
                ->get();

            foreach ($bookings as $b) {
                $start = Carbon::parse(Carbon::parse($b->booking_date)->toDateString().' '.($b->booking_time ?: '00:00:00'));
                if ($start->lte($now)) {
                    continue;
                }
                $left = $now->diffInMinutes($start) / 60;
                $sent = collect(explode(',', (string) $b->reminders_sent))->filter()->map(fn ($h) => (int) $h);
                $due = $hours->filter(fn ($h) => $left <= $h && ! $sent->contains($h));
                if ($due->isEmpty()) {
                    continue;
                }
                $nearest = (int) $due->min();
                if ($this->option('dry-run')) {
                    $this->line("company {$s->company_id} {$b->Ref} {$start->format('Y-m-d H:i')}: {$nearest} h reminder");
                } else {
                    $to = BookingNotifier::bookingReminder((int) $s->company_id, (int) $b->id, $nearest);
                    // Every reminder the booking is already inside is done now.
                    $b->forceFill(['reminders_sent' => $sent->merge($hours->filter(fn ($h) => $left <= $h))->unique()->sort()->implode(',')])->saveQuietly();
                    $this->line("{$b->Ref}: {$nearest} h reminder -> ".(implode(', ', $to) ?: 'nobody (no email)'));
                }
                $total++;
            }
        }
        $this->info("Reminders: {$total}");

        return self::SUCCESS;
    }
}
