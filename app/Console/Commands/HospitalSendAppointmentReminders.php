<?php

namespace App\Console\Commands;

use App\Services\BookingNotifier;
use App\Support\ModuleFlags;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hospital appointment reminders (phase 4, 2026-10-25): one email to the
 * patient within the 24 h before a scheduled / confirmed appointment, for the
 * companies with the Hospital module on. Each appointment once
 * (appointments.reminder_sent_at); the patient also sees them in the portal.
 */
class HospitalSendAppointmentReminders extends Command
{
    protected $signature = 'hospital:appointment-reminders {--dry-run : List what would be sent}';

    protected $description = 'Email hospital appointment reminders to patients (24 h before)';

    public function handle(): int
    {
        $rows = DB::table('appointments')->whereNull('deleted_at')->whereNull('reminder_sent_at')
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereBetween('scheduled_at', [now(), now()->addHours(24)])
            ->orderBy('scheduled_at')->get(['id', 'company_id', 'reference', 'scheduled_at']);
        $sent = 0;
        foreach ($rows as $a) {
            if (! ModuleFlags::enabled((int) $a->company_id, 'hospital')) {
                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("company {$a->company_id} {$a->reference} {$a->scheduled_at}");

                continue;
            }
            $to = BookingNotifier::hospitalAppointmentReminder((int) $a->company_id, (int) $a->id);
            // Marked even without an email: nothing to retry every five minutes.
            DB::table('appointments')->where('id', $a->id)->update(['reminder_sent_at' => now()]);
            $this->line("{$a->reference}: ".($to ?: 'no email'));
            $sent += $to ? 1 : 0;
        }
        $this->info("Reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
