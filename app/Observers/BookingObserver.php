<?php

namespace App\Observers;

use App\Jobs\SyncBookingToGoogleCalendar;
use App\Models\Booking;
use App\Services\BookingSaleService;
use Illuminate\Support\Facades\Log;

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
                Log::info('Booking sale not created: '.$res['reason'], ['booking_id' => $booking->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('Booking sale failed (non-blocking): '.$e->getMessage(), ['booking_id' => $booking->id]);
        }
    }

    /**
     * Confirmed (or completed) booking -> its customer gets a linked users
     * row if they don't have one yet. A public-booking-page customer is
     * created without one on purpose: the account only exists once staff
     * accept the booking. Never blocks the booking save.
     */
    private function accountIfConfirmed(Booking $booking): void
    {
        if (! in_array($booking->status, ['confirmed', 'completed'], true) || ! $booking->customer_id) {
            return;
        }
        try {
            $client = \App\Models\Client::whereNull('deleted_at')->find($booking->customer_id);
            if ($client) {
                \App\Services\LinkedUserService::ensureForClient($client);
            }
        } catch (\Throwable $e) {
            Log::warning('Booking customer account not created (non-blocking): '.$e->getMessage(), ['booking_id' => $booking->id]);
        }
    }

    public function created(Booking $booking): void
    {
        $this->accountIfConfirmed($booking);
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
        if ($booking->wasChanged('status') || $booking->wasChanged('customer_id')) {
            $this->accountIfConfirmed($booking);
        }
        $this->invoiceIfCompleted($booking);

        if ($booking->wasChanged('product_id') && $booking->sale_id) {
            try {
                app(BookingSaleService::class)->syncServiceLine($booking);
            } catch (\Throwable $e) {
                Log::warning('Booking sale line sync failed (non-blocking): '.$e->getMessage(), ['booking_id' => $booking->id]);
            }
        }

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
