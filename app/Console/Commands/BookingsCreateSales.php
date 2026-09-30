<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\BookingSaleService;
use Illuminate\Console\Command;

/**
 * Backfill: create the sale invoice (paid in cash) of every completed booking
 * that has none yet. New completions are invoiced automatically by
 * BookingObserver; this catches bookings completed before that existed.
 */
class BookingsCreateSales extends Command
{
    protected $signature = 'bookings:create-sales {--dry-run : List what would be invoiced without writing}';

    protected $description = 'Create a sale invoice for each completed booking that has none';

    public function handle(BookingSaleService $service): int
    {
        $bookings = Booking::whereNull('deleted_at')
            ->where('status', 'completed')
            ->whereNull('sale_id')
            ->orderBy('booking_date')->orderBy('id')
            ->get();

        $made = 0;
        $skipped = 0;
        foreach ($bookings as $b) {
            if ($this->option('dry-run')) {
                $this->line("#{$b->id} {$b->Ref} {$b->booking_date} ".($b->product_id ? 'ok' : 'NO SERVICE'));

                continue;
            }
            $res = $service->createForBooking($b);
            if ($res['ok']) {
                $made++;
                $this->info("#{$b->id} {$b->Ref} -> sale {$res['sale']->Ref} ({$res['sale']->GrandTotal})");
            } else {
                $skipped++;
                $this->warn("#{$b->id} {$b->Ref} skipped: {$res['reason']}");
            }
        }

        $this->line("Created {$made}, skipped {$skipped}, of {$bookings->count()} completed bookings without a sale.");

        return self::SUCCESS;
    }
}
