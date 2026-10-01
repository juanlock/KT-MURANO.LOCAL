<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Sale;
use App\Models\Setting;
use App\Observers\BookingObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POS Settings -> Company rules -> "Integrate POS with Booking".
 *
 * A POS sale also records a booking, so the visit shows in the Bookings list
 * and calendar: same customer, date and time, the services sold (first one =
 * the booking's service, all of them in the description), the staff member
 * (salesperson, else cashier), the location and the total. It is created
 * COMPLETED and linked to the sale (bookings.sale_id), so it is never
 * invoiced twice; the company admins get the usual "Booking completed"
 * report (BookingNotifier::bookingCompleted). Never blocks the sale.
 */
class PosBookingService
{
    public static function createForSale(int $saleId): ?Booking
    {
        $sale = Sale::find($saleId);
        if (! $sale || ! $sale->company_id || ! $sale->client_id) {
            return null;
        }
        $settings = Setting::forCompany((int) $sale->company_id);
        if (! ($settings && $settings->pos_booking_integration)) {
            return null;
        }
        // Idempotent: one booking per sale (offline re-sync, retries).
        if ($existing = Booking::withTrashed()->where('sale_id', $sale->id)->first()) {
            return $existing;
        }

        $lines = DB::table('sale_details')
            ->join('products', 'products.id', '=', 'sale_details.product_id')
            ->where('sale_details.sale_id', $sale->id)
            ->orderBy('sale_details.id')
            ->get(['products.id', 'products.name', 'products.type', 'products.service_duration', 'sale_details.quantity', 'sale_details.total']);
        if ($lines->isEmpty()) {
            return null;
        }
        $services = $lines->where('type', 'is_service');
        $main = $services->first() ?? $lines->first();

        // Visit length: the services' durations (x quantity), else the booking slot.
        $minutes = (int) $services->sum(fn ($l) => (int) $l->service_duration * max(1, (int) round((float) $l->quantity)));
        if ($minutes <= 0) {
            $minutes = (int) ($settings->booking_slot_minutes ?: 30);
        }
        $start = \Carbon\Carbon::parse($sale->date.' '.($sale->time ?: now()->format('H:i:s')));
        $end = (clone $start)->addMinutes($minutes);

        $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 2, '.', ''), '0'), '.');
        $names = $lines->pluck('name')->unique()->implode(', ');
        $description = $lines->map(fn ($l) => $l->name.' x '.$qty($l->quantity))->implode("\n");

        BookingObserver::$muteCreatedMail = true;      // the completed report is sent below instead
        BookingObserver::$skipCustomerAccount = true;  // no login account for a POS customer
        try {
            $booking = new Booking;
            $booking->forceFill([
                'Ref' => app(\App\Http\Controllers\BookingController::class)->getNumberOrder(),
                'customer_id' => $sale->client_id,
                'product_id' => $main->id,
                'product_name' => mb_strimwidth($names, 0, 255, '…'),
                'product_description' => $description,
                'warehouse_id' => $sale->warehouse_id,
                'sales_person_id' => $sale->seller_id ?: $sale->user_id,
                'price' => $sale->GrandTotal,
                'amount' => $sale->GrandTotal,
                'booking_date' => $start->toDateString(),
                'booking_time' => $start->format('H:i'),
                'booking_end_time' => $end->isSameDay($start) ? $end->format('H:i') : '23:59',
                'status' => 'completed',
                'sale_id' => $sale->id,
                'notes' => 'POS sale '.$sale->Ref,
            ])->save();
        } finally {
            BookingObserver::$muteCreatedMail = false;
            BookingObserver::$skipCustomerAccount = false;
        }

        $companyId = (int) $sale->company_id;
        $bookingId = $booking->id;
        dispatch(function () use ($companyId, $bookingId, $saleId) {
            try {
                BookingNotifier::bookingCompleted($companyId, $bookingId, $saleId);
            } catch (\Throwable $e) {
                Log::warning('POS booking mail failed (non-blocking): '.$e->getMessage(), ['booking_id' => $bookingId]);
            }
        })->afterResponse();

        return $booking;
    }
}
