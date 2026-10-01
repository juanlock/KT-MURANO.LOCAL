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
    /**
     * Set by PosBookingService around its create: a POS sale's booking (often
     * the Walk-in Customer) must not create a customer login account.
     */
    public static bool $skipCustomerAccount = false;

    private function accountIfConfirmed(Booking $booking): void
    {
        if (self::$skipCustomerAccount || ! in_array($booking->status, ['confirmed', 'completed'], true) || ! $booking->customer_id) {
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

    /**
     * Set by the public "Book an appointment" page around its create: that
     * flow sends its own "new online booking" email (with the customer's
     * request), so the staff "new booking" notice is skipped for it.
     */
    public static bool $muteCreatedMail = false;

    /** Admin name for "Confirmed by" when confirmed from the email link (no login). */
    public static ?string $confirmedBy = null;

    /**
     * Admin notice for a booking registered by staff or confirmed, sent after
     * the response (BookingNotifier::bookingEvent, company mail server). The
     * completion report goes out with its invoice (BookingSaleService).
     */
    private function notifyAdmins(Booking $booking, string $event): void
    {
        try {
            $companyId = (int) \App\Models\Client::whereKey($booking->customer_id)->value('company_id');
            if (! $companyId) {
                return;
            }
            $u = auth('api')->user() ?? auth()->user();
            $by = $u ? (trim(($u->firstname ?? '').' '.($u->lastname ?? '')) ?: $u->username)
                : (self::$confirmedBy ? self::$confirmedBy.' (from the email)' : null);
            $id = $booking->id;
            dispatch(function () use ($companyId, $id, $event, $by) {
                try {
                    \App\Services\BookingNotifier::bookingEvent($companyId, $id, $event, $by);
                } catch (\Throwable $e) {
                    Log::warning('Booking admin mail failed (non-blocking): '.$e->getMessage(), ['booking_id' => $id]);
                }
            })->afterResponse();
        } catch (\Throwable $e) {
            Log::warning('Booking admin mail not queued: '.$e->getMessage(), ['booking_id' => $booking->id]);
        }
    }

    public function created(Booking $booking): void
    {
        $this->accountIfConfirmed($booking);
        $this->invoiceIfCompleted($booking);
        if (! self::$muteCreatedMail) {
            $this->notifyAdmins($booking, $booking->status === 'confirmed' ? 'confirmed' : 'created');
        }

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
        if ($booking->wasChanged('status') && $booking->status === 'confirmed') {
            $this->notifyAdmins($booking, 'confirmed');
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
