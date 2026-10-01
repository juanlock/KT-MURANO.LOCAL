<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Observers\BookingObserver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * "Confirm appointment" button in the admins' new-booking email: confirms a
 * Pending booking without signing in. The link is a signed URL (Laravel
 * `signed` middleware, valid 14 days) naming the booking and the admin it was
 * mailed to -- it can't be forged or pointed at another booking.
 *
 * GET only shows the booking and a Confirm button; the POST does the change.
 * Mail scanners that pre-open links (Outlook Safe Links, Gmail, antivirus)
 * would otherwise confirm bookings nobody looked at.
 * Confirming goes through the model (BookingObserver), exactly like
 * confirming from Bookings -> Edit: customer account, "Booking confirmed"
 * admin email, Google Calendar sync.
 */
class BookingEmailConfirmController extends Controller
{
    public static function link(int $bookingId, string $byName): string
    {
        return URL::temporarySignedRoute('booking.email.confirm', now()->addDays(14), ['booking' => $bookingId, 'by' => $byName]);
    }

    public function show(Request $request, int $booking)
    {
        $b = Booking::with(['customer', 'product'])->whereNull('deleted_at')->find($booking);

        return view('booking.email_confirm', ['b' => $b, 'done' => false]);
    }

    public function confirm(Request $request, int $booking)
    {
        $b = Booking::with(['customer', 'product'])->whereNull('deleted_at')->find($booking);
        $done = false;
        if ($b && $b->status === 'pending') {
            BookingObserver::$confirmedBy = trim((string) $request->query('by')) ?: null;
            try {
                $b->status = 'confirmed';
                $b->save();
            } finally {
                BookingObserver::$confirmedBy = null;
            }
            $done = true;
        }

        return view('booking.email_confirm', ['b' => $b, 'done' => $done]);
    }
}
