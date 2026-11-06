<?php

namespace App\Services;

use App\Http\Controllers\PaymentSalesController;
use App\Http\Controllers\SalesController;
use App\Models\PaymentSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use Illuminate\Support\Facades\DB;

/**
 * A parcel IS a sale of the agency (user's OK, 2026-11-04), like a hospital bill: one line of the company's
 * "Encomienda" service product, on the agent's open cash register. Paid at the counter, or
 * unpaid until delivery when the receiver pays. Called inside the parcel's transaction.
 */
class TransportParcelSale
{
    private const CODE = 'ENC-SERV';

    public static function create(object $parcel, int $userId, ?int $methodId): Sale
    {
        $companyId = (int) $parcel->company_id;
        $wid = (int) $parcel->warehouse_id;
        $product = self::product($companyId);
        $sale = new Sale;
        $sale->forceFill([
            'Ref' => app(SalesController::class)->getNumberOrder($wid, $companyId), 'is_pos' => 0, 'company_id' => $companyId, 'warehouse_id' => $wid, 'user_id' => $userId,
            'tax_method' => '1', 'discount_Method' => '2', 'shipping' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'client_id' => (int) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('client_id'), 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0,
            'GrandTotal' => (float) $parcel->price, 'statut' => 'completed', 'notes' => 'Encomienda '.$parcel->number,
            'cash_register_id' => DB::table('cash_registers')->where('user_id', $userId)->where('warehouse_id', $wid)->where('status', 'open')->orderByDesc('id')->value('id'),
        ])->save();
        SaleDetail::create([
            'date' => $sale->date, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_variant_id' => null, 'sale_unit_id' => $product->unit_sale_id ?: null,
            'quantity' => 1, 'price' => (float) $parcel->price, 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'total' => (float) $parcel->price,
            'price_type' => 'retail', 'pack_multiplier' => 1, 'note' => 'Encomienda '.$parcel->number,
        ]);
        if ($parcel->payment === 'paid' && (float) $parcel->price > 0) {
            self::pay($sale, $userId, $methodId);
        }

        return $sale;
    }

    /** The whole sale paid (at the counter, or on delivery). */
    public static function pay(Sale $sale, int $userId, ?int $methodId): void
    {
        $due = (float) $sale->GrandTotal - (float) PaymentSale::where('sale_id', $sale->id)->whereNull('deleted_at')->sum('montant');
        if ($due <= 0) {
            return;
        }
        PaymentSale::create([
            'sale_id' => $sale->id, 'Ref' => app(PaymentSalesController::class)->getNumberOrder(), 'date' => now()->toDateString(), 'account_id' => null,
            'payment_method_id' => $methodId ?: DB::table('payment_methods')->whereNull('deleted_at')->orderBy('id')->value('id'), 'montant' => $due, 'change' => 0,
            'notes' => $sale->notes, 'user_id' => $userId,
        ]);
        $sale->paid_amount = (float) $sale->GrandTotal;
        $sale->payment_statut = 'paid';
        $sale->save();
    }

    /** The price changed while still at the agency. */
    public static function reprice(int $saleId, float $price): void
    {
        $sale = Sale::whereNull('deleted_at')->find($saleId);
        if (! $sale) {
            return;
        }
        $sale->GrandTotal = $price;
        if ((float) $sale->paid_amount > 0) {
            $sale->paid_amount = $price;
            PaymentSale::where('sale_id', $saleId)->whereNull('deleted_at')->orderBy('id')->limit(1)->update(['montant' => $price]);
        }
        $sale->save();
        SaleDetail::where('sale_id', $saleId)->update(['price' => $price, 'total' => $price]);
    }

    public static function cancel(int $saleId): void
    {
        $sale = Sale::whereNull('deleted_at')->find($saleId);
        if (! $sale) {
            return;
        }
        SaleDetail::where('sale_id', $sale->id)->delete();
        foreach (PaymentSale::where('sale_id', $sale->id)->whereNull('deleted_at')->get() as $payment) {
            $payment->forceFill(['deleted_at' => now()])->save();
        }
        $sale->update(['deleted_at' => now()]);
        event(new \App\Events\SaleDeleted($sale));
    }

    private static function product(int $companyId): Product
    {
        $existing = Product::where('company_id', $companyId)->where('code', self::CODE)->whereNull('deleted_at')->first();
        if ($existing) {
            return $existing;
        }
        $categoryId = DB::table('categories')->where('company_id', $companyId)->whereNull('deleted_at')->where('name', 'Encomiendas')->value('id')
            ?: DB::table('categories')->insertGetId(['company_id' => $companyId, 'code' => 'ENC', 'name' => 'Encomiendas', 'created_at' => now(), 'updated_at' => now()]);
        $product = new Product;
        $product->forceFill([
            'company_id' => $companyId, 'type' => 'is_service', 'code' => self::CODE, 'Type_barcode' => 'CODE128', 'name' => 'Encomienda', 'cost' => 0, 'price' => 0, 'wholesale_price' => 0, 'min_price' => 0,
            'category_id' => $categoryId, 'TaxNet' => 0, 'tax_method' => '1', 'is_active' => 1, 'hide_from_online_store' => 1, 'show_on_booking_form' => 0, 'note' => 'Parcels (Transport) become sales.',
        ])->save();

        return $product;
    }
}
