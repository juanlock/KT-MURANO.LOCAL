<?php

namespace App\Observers;

use App\Jobs\SyncBookingToGoogleCalendar;
use App\Models\Booking;
use App\Services\BookingSaleService;

class BookingObserver
{
    /**
     * Completed booking -> its sale invoice (once; see BookingSaleService).
     * Also retried on later saves of a completed booking still without one,
     * e.g. after a service is picked on a booking that had none. Never blocks
     * the booking save.
     */
    private function invoiceIfCompleted(Booking $booking): void
    {
        if ($booking->status !== 'completed' || $booking->sale_id) {
            return;
        }
        try {
            $res = app(BookingSaleService::class)->createForBooking($booking);
            if (! $res['ok']) {
                \Log::info('Booking sale not created: '.$res['reason'], ['booking_id' => $booking->id]);
            }
        } catch (\Throwable $e) {
            \Log::warning('Booking sale failed (non-blocking): '.$e->getMessage(), ['booking_id' => $booking->id]);
        }
    }

    public function created(Booking $booking): void
    {
        $this->invoiceIfCompleted($booking);

        if ($booking->status === 'confirmed') {
            SyncBookingToGoogleCalendar::dispatch(
                $booking->id,
                SyncBookingToGoogleCalendar::ACTION_UPSERT
            );
        }
    }

    public function updated(Booking $booking): void
    {
        $this->invoiceIfCompleted($booking);

        $relevant = ['status', 'booking_date', 'booking_time', 'booking_end_time', 'customer_id', 'product_id', 'price', 'notes'];
        if (empty(array_intersect($relevant, array_keys($booking->getChanges())))) {
            return;
        }

        SyncBookingToGoogleCalendar::dispatch(
            $booking->id,
            SyncBookingToGoogleCalendar::ACTION_UPSERT
        );
    }

    public function deleted(Booking $booking): void
    {
        if (! empty($booking->google_calendar_event_id)) {
            SyncBookingToGoogleCalendar::dispatch(
                $booking->id,
                SyncBookingToGoogleCalendar::ACTION_DELETE,
                $booking->google_calendar_event_id
            );
        }
    }
}
