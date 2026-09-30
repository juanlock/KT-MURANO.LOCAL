<?php

namespace App\Services;

use App\Http\Controllers\PaymentSalesController;
use App\Http\Controllers\SalesController;
use App\Models\Booking;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\PaymentSale;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Booking -> Sale invoice. A completed booking (appointment) gets exactly one
 * sale: one line for the booked service at the booking's price, dated on the
 * booking date, in the customer's company, status completed and paid in full
 * by one Cash payment. bookings.sale_id is the link and the idempotency key.
 *
 * Called from BookingObserver (any path that completes a booking: edit form,
 * calendar, status endpoint, import), from POST bookings/{id}/sale, and from
 * `php artisan bookings:create-sales` for the backfill.
 */
class BookingSaleService
{
    /**
     * @return array{ok: bool, sale: ?Sale, reason: ?string}
     */
    public function createForBooking(Booking $booking, ?int $userId = null): array
    {
        if ($booking->status !== 'completed') {
            return $this->fail('Only completed bookings are invoiced.');
        }
        if ($booking->sale_id) {
            return ['ok' => true, 'sale' => Sale::find($booking->sale_id), 'reason' => 'already'];
        }
        if (! $booking->product_id) {
            return $this->fail('The booking has no service selected -- pick one to create its sale.');
        }

        $client = Client::find($booking->customer_id);
        $product = Product::find($booking->product_id);
        if (! $client || ! $product) {
            return $this->fail('The booking customer or service no longer exists.');
        }
        $companyId = (int) $client->company_id;
        if ($product->company_id && (int) $product->company_id !== $companyId) {
            return $this->fail('The booking service belongs to another company.');
        }

        $warehouseId = $this->warehouseFor($companyId);
        if (! $warehouseId) {
            return $this->fail('The company has no warehouse to put the sale on.');
        }

        $userId = $userId ?: $this->userFor($booking, $companyId);
        if (! $userId) {
            return $this->fail('No user of the company to record the sale under.');
        }

        return DB::transaction(function () use ($booking, $client, $product, $companyId, $warehouseId, $userId) {
            // Re-read under lock: two saves completing the same booking at once
            // must not create two invoices.
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();
            if ($locked->sale_id) {
                return ['ok' => true, 'sale' => Sale::find($locked->sale_id), 'reason' => 'already'];
            }

            $price = (float) ($locked->amount > 0 ? $locked->amount : ($locked->price ?? 0));
            $taxPercent = (float) ($product->TaxNet ?? 0);
            $taxMethod = (string) ($product->tax_method ?? '1');
            // tax_method 1 = exclusive (tax added on top), 2 = inclusive.
            $taxAmount = $taxMethod === '1' ? round($price * $taxPercent / 100, 2) : 0;
            $lineTotal = round($price + $taxAmount, 2);
            $date = $locked->booking_date ? \Carbon\Carbon::parse($locked->booking_date)->toDateString() : now()->toDateString();

            $sale = new Sale;
            $sale->is_pos = 0;
            $sale->date = $date;
            $sale->time = $locked->booking_time ?: now()->toTimeString();
            $sale->Ref = app(SalesController::class)->getNumberOrder();
            $sale->client_id = $client->id;
            $sale->warehouse_id = $warehouseId;
            $sale->company_id = $companyId;
            $sale->tax_rate = 0;
            $sale->TaxNet = 0;
            $sale->tax_method = '1';
            $sale->discount = 0;
            $sale->discount_Method = '2';
            $sale->shipping = 0;
            $sale->GrandTotal = $lineTotal;
            $sale->paid_amount = 0;
            $sale->statut = 'completed';
            $sale->payment_statut = 'unpaid'; // settled by the Cash payment below
            $sale->notes = trim('Booking '.$locked->Ref.($locked->product_name ? ' - '.$locked->product_name : ''));
            $sale->user_id = $userId;
            $sale->seller_id = $locked->sales_person_id ?: null;
            $sale->save();

            SaleDetail::create(array_merge([
                'date' => $date,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_variant_id' => null,
                'sale_unit_id' => $product->unit_sale_id ?: null,
                'quantity' => 1,
                'price' => $price,
                'TaxNet' => $taxPercent,
                'tax_method' => $taxMethod,
                'discount' => 0,
                'discount_method' => '2',
                'total' => $lineTotal,
                'price_type' => 'retail',
                'pack_multiplier' => 1,
                'note' => $locked->product_description ?: null,
            ], SaleDetail::computeWarrantyGuaranteeDates($product, $date)));

            // Services carry no stock; a booked physical product leaves the
            // warehouse like any completed sale line.
            if ($product->type !== 'is_service') {
                $this->deductStock($product, $warehouseId);
            }

            // Appointments are paid in cash at the visit: the sale is settled
            // in full by one Cash payment on the booking date.
            if ($lineTotal > 0) {
                PaymentSale::create([
                    'sale_id' => $sale->id,
                    'Ref' => app(PaymentSalesController::class)->getNumberOrder(),
                    'date' => $date,
                    'account_id' => null,
                    'payment_method_id' => $this->cashMethodId(),
                    'montant' => $lineTotal,
                    'change' => 0,
                    'notes' => 'Booking '.$locked->Ref,
                    'user_id' => $userId,
                ]);
            }
            $sale->forceFill(['paid_amount' => $lineTotal, 'payment_statut' => 'paid'])->save();

            $locked->forceFill(['sale_id' => $sale->id])->saveQuietly();
            $booking->setRawAttributes($locked->getAttributes(), true);

            DB::afterCommit(function () use ($sale) {
                try {
                    app(CommissionService::class)->calculateForSale($sale->fresh());
                } catch (\Throwable $e) {
                    Log::warning('Booking sale commission failed (non-blocking): '.$e->getMessage(), ['sale_id' => $sale->id]);
                }
            });

            return ['ok' => true, 'sale' => $sale, 'reason' => null];
        });
    }

    /** The "Cash" payment method (payment methods are shared by all companies). */
    private function cashMethodId(): ?int
    {
        return PaymentMethod::whereNull('deleted_at')->whereRaw('LOWER(name) = ?', ['cash'])->value('id');
    }

    private function fail(string $reason): array
    {
        return ['ok' => false, 'sale' => null, 'reason' => $reason];
    }

    /** The company's default warehouse (Settings), else its first one. */
    private function warehouseFor(int $companyId): ?int
    {
        $default = optional(Setting::where('company_id', $companyId)->whereNull('deleted_at')->first())->warehouse_id;
        if ($default && Warehouse::whereKey($default)->where('company_id', $companyId)->whereNull('deleted_at')->exists()) {
            return (int) $default;
        }

        return Warehouse::where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    /** Logged-in user, else the booking's sales person, else the company's first active user. */
    private function userFor(Booking $booking, int $companyId): ?int
    {
        if (Auth::id()) {
            return (int) Auth::id();
        }
        if ($booking->sales_person_id) {
            return (int) $booking->sales_person_id;
        }

        return User::where('company_id', $companyId)->whereNull('deleted_at')->where('statut', 1)->orderBy('id')->value('id');
    }

    private function deductStock(Product $product, int $warehouseId): void
    {
        $pw = product_warehouse::whereNull('deleted_at')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->first();
        $unit = $product->unit_sale_id ? Unit::find($product->unit_sale_id) : null;
        if (! $pw || ! $unit) {
            return;
        }
        $pw->qte -= $unit->operator === '/' ? 1 / $unit->operator_value : 1 * $unit->operator_value;
        $pw->save();
    }
}
