<?php

namespace App\Services;

use App\Http\Controllers\PaymentSalesController;
use App\Http\Controllers\SalesController;
use App\Models\HospitalInvoice;
use App\Models\HospitalInvoiceItem;
use App\Models\HospitalPayment;
use App\Models\PaymentMethod;
use App\Models\PaymentSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * A billed hospital service IS a sale (user's rule, 2026-10-21). Each hospital
 * invoice owns one sale (hospital_invoices.sale_id): the patient's customer,
 * the company's own sale number, one line per invoice line -- the medicine's
 * product (its stock goes down) or the company's hospital service product for
 * consultations, bed nights, lab tests and other charges --, its discount /
 * tax / total, and each hospital payment as a sale payment
 * (hospital_payments.payment_sale_id). Accounting comes from the sale and its
 * payments like any sale (AccountingV2 events), so the hospital no longer
 * posts its own entries: one entry per bill.
 *
 * Called by HospitalBillingController inside its transactions.
 */
class HospitalSale
{
    /** line type => [code, Spanish name, English name] */
    private const SERVICES = [
        'consultation' => ['HMS-CONSULT', 'Consulta médica', 'Medical consultation'],
        'bed' => ['HMS-BED', 'Hospitalización (día)', 'Hospital stay (night)'],
        'lab' => ['HMS-LAB', 'Examen de laboratorio', 'Laboratory test'],
        'medicine' => ['HMS-MED', 'Medicamento', 'Medicine'],
        'procedure' => ['HMS-PROC', 'Procedimiento', 'Procedure'],
        'other' => ['HMS-OTHER', 'Servicio hospitalario', 'Hospital service'],
    ];

    /** Create or bring up to date the invoice's sale (lines, totals, status). */
    public static function sync(HospitalInvoice $invoice, ?int $userId = null): ?Sale
    {
        if ($invoice->trashed() || $invoice->status === 'cancelled') {
            self::remove($invoice);

            return null;
        }

        $companyId = (int) $invoice->company_id;
        $patient = \App\Models\Patient::withoutGlobalScope('company')->find($invoice->patient_id);
        $clientId = $patient ? PatientClient::ensure($patient) : null;
        $warehouseId = self::warehouseFor($companyId);
        if (! $clientId || ! $warehouseId) {
            return null; // no customer / location: the invoice still exists, its sale comes on the next save
        }
        $userId = $userId ?: (auth('api')->id() ?: User::where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->value('id'));

        $sale = $invoice->sale_id ? Sale::whereNull('deleted_at')->find($invoice->sale_id) : null;
        $isNew = ! $sale;
        if ($isNew) {
            $sale = new Sale;
            $sale->Ref = app(SalesController::class)->getNumberOrder($warehouseId, $companyId);
            $sale->is_pos = 0;
            $sale->company_id = $companyId;
            $sale->warehouse_id = $warehouseId;
            $sale->user_id = $userId;
            $sale->tax_method = '1';
            $sale->discount_Method = '2';
            $sale->shipping = 0;
            $sale->paid_amount = 0;
            $sale->payment_statut = 'unpaid';
        }
        $completed = $invoice->status !== 'draft';
        $sale->date = optional($invoice->invoice_date)->toDateString() ?: now()->toDateString();
        $sale->time = $sale->time ?: now()->toTimeString();
        $sale->client_id = $clientId;
        $sale->tax_rate = 0;
        $sale->TaxNet = (float) $invoice->tax;
        $sale->discount = (float) $invoice->discount;
        $sale->GrandTotal = (float) $invoice->total;
        $sale->statut = $completed ? 'completed' : 'pending';
        $sale->notes = trim('Hospital '.$invoice->reference.($patient ? ' - '.$patient->name.' ('.$patient->mrn.')' : ''));

        // Lines: put the old ones' stock back, then write the invoice's lines.
        if (! $isNew) {
            self::restoreStock($sale);
            SaleDetail::where('sale_id', $sale->id)->delete();
        }
        $sale->save();

        $date = $sale->date;
        foreach (HospitalInvoiceItem::where('invoice_id', $invoice->id)->orderBy('id')->get() as $item) {
            $product = $item->product_id ? Product::whereNull('deleted_at')->find($item->product_id) : null;
            if ($product && $product->company_id && (int) $product->company_id !== $companyId) {
                $product = null;
            }
            $product = $product ?: self::serviceProduct($companyId, $item->type ?: 'other');
            if (! $product) {
                continue;
            }
            SaleDetail::create([
                'date' => $date,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_variant_id' => null,
                'sale_unit_id' => $product->unit_sale_id ?: null,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->unit_price,
                'TaxNet' => 0,
                'tax_method' => '1',
                'discount' => 0,
                'discount_method' => '2',
                'total' => (float) $item->total,
                'price_type' => 'retail',
                'pack_multiplier' => 1,
                'note' => $item->description,
            ]);
            if ($completed && $product->type !== 'is_service') {
                self::moveStock($product, $warehouseId, -(float) $item->quantity);
            }
        }

        self::syncPaidStatus($sale, $invoice);

        if ((int) $invoice->sale_id !== (int) $sale->id) {
            HospitalInvoice::withoutGlobalScope('company')->whereKey($invoice->id)->update(['sale_id' => $sale->id]);
            $invoice->sale_id = $sale->id;
        }

        return $sale;
    }

    /** A hospital payment -> its sale payment (accounting: Cash / A/R via the sale payment). */
    public static function syncPayment(HospitalPayment $payment, HospitalInvoice $invoice): void
    {
        $sale = $invoice->sale_id ? Sale::whereNull('deleted_at')->find($invoice->sale_id) : self::sync($invoice);
        if (! $sale || $payment->payment_sale_id) {
            return;
        }
        $ps = PaymentSale::create([
            'sale_id' => $sale->id,
            'Ref' => app(PaymentSalesController::class)->getNumberOrder(),
            'date' => optional($payment->paid_on)->toDateString() ?: now()->toDateString(),
            'account_id' => null,
            'payment_method_id' => self::methodId($payment->method),
            'montant' => (float) $payment->amount,
            'change' => 0,
            'notes' => trim('Hospital '.$payment->reference.' '.($payment->notes ?? '')),
            'user_id' => $payment->created_by ?: $sale->user_id,
        ]);
        HospitalPayment::withoutGlobalScope('company')->whereKey($payment->id)->update(['payment_sale_id' => $ps->id]);
        $payment->payment_sale_id = $ps->id;
        self::syncPaidStatus($sale, $invoice->fresh());
    }

    /** A hospital payment removed -> its sale payment removed (its entry reversed). */
    public static function removePayment(HospitalPayment $payment, HospitalInvoice $invoice): void
    {
        if ($payment->payment_sale_id && ($ps = PaymentSale::whereNull('deleted_at')->find($payment->payment_sale_id))) {
            $ps->forceFill(['deleted_at' => now()])->save(); // fires PaymentDeleted -> reversal (AccountingV2ServiceProvider)
        }
        if ($invoice->sale_id && ($sale = Sale::whereNull('deleted_at')->find($invoice->sale_id))) {
            self::syncPaidStatus($sale, $invoice->fresh());
        }
    }

    /** Invoice cancelled / deleted -> its sale removed like Sales -> Delete (stock back, payments and entry reversed). */
    public static function remove(HospitalInvoice $invoice): void
    {
        $sale = $invoice->sale_id ? Sale::whereNull('deleted_at')->find($invoice->sale_id) : null;
        if (! $sale) {
            return;
        }
        if ($sale->statut === 'completed') {
            self::restoreStock($sale);
        }
        SaleDetail::where('sale_id', $sale->id)->delete();
        foreach (PaymentSale::where('sale_id', $sale->id)->whereNull('deleted_at')->get() as $ps) {
            $ps->forceFill(['deleted_at' => now()])->save();
        }
        $sale->update(['deleted_at' => now()]);
        event(new \App\Events\SaleDeleted($sale));
        HospitalInvoice::withoutGlobalScope('company')->withTrashed()->whereKey($invoice->id)->update(['sale_id' => null]);
        $invoice->sale_id = null;
    }

    // ------------------------------------------------------------------

    private static function syncPaidStatus(Sale $sale, HospitalInvoice $invoice): void
    {
        $paid = (float) PaymentSale::where('sale_id', $sale->id)->whereNull('deleted_at')->sum('montant');
        $total = (float) $sale->GrandTotal;
        $status = $paid <= 0.004 ? 'unpaid' : ($paid + 0.004 >= $total ? 'paid' : 'partial');
        $sale->forceFill(['paid_amount' => round($paid, 2), 'payment_statut' => $status])->saveQuietly();
    }

    /** The company's hospital service product for a line type, created on first use. */
    private static function serviceProduct(int $companyId, string $type): ?Product
    {
        [$code, $es, $en] = self::SERVICES[$type] ?? self::SERVICES['other'];
        $existing = Product::where('company_id', $companyId)->where('code', $code)->whereNull('deleted_at')->first();
        if ($existing) {
            return $existing;
        }
        $lang = (string) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('default_language');
        $isEs = str_starts_with($lang, 'es');
        $categoryId = DB::table('categories')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereIn('name', ['Servicios hospitalarios', 'Hospital services'])->value('id');
        if (! $categoryId) {
            $categoryId = DB::table('categories')->insertGetId([
                'company_id' => $companyId, 'code' => 'HMS', 'name' => $isEs ? 'Servicios hospitalarios' : 'Hospital services',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $product = new Product;
        $product->forceFill([
            'company_id' => $companyId, 'type' => 'is_service', 'code' => $code, 'Type_barcode' => 'CODE128',
            'name' => $isEs ? $es : $en, 'cost' => 0, 'price' => 0, 'wholesale_price' => 0, 'min_price' => 0,
            'category_id' => $categoryId, 'TaxNet' => 0, 'tax_method' => '1', 'is_active' => 1,
            'hide_from_online_store' => 1, 'show_on_booking_form' => 0,
            'note' => 'Hospital billing line (Hospital Management invoices become sales).',
        ])->save();

        return $product;
    }

    private static function warehouseFor(int $companyId): ?int
    {
        $default = Setting::where('company_id', $companyId)->whereNull('deleted_at')->value('warehouse_id');
        if ($default && Warehouse::whereKey($default)->where('company_id', $companyId)->whereNull('deleted_at')->exists()) {
            return (int) $default;
        }

        return Warehouse::where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    private static function restoreStock(Sale $sale): void
    {
        if ($sale->statut !== 'completed') {
            return;
        }
        foreach (SaleDetail::where('sale_id', $sale->id)->get() as $d) {
            $product = Product::find($d->product_id);
            if ($product && $product->type !== 'is_service') {
                self::moveStock($product, (int) $sale->warehouse_id, (float) $d->quantity);
            }
        }
    }

    /** +qty back in / -qty out of the location, in the product's sale unit. */
    private static function moveStock(Product $product, int $warehouseId, float $qty): void
    {
        $unit = $product->unit_sale_id ? Unit::find($product->unit_sale_id) : null;
        $opv = max((float) ($unit->operator_value ?? 1), 1);
        $base = ($unit && $unit->operator === '/') ? $qty / $opv : $qty * $opv;
        $pw = \App\Models\product_warehouse::whereNull('deleted_at')->where('warehouse_id', $warehouseId)
            ->where('product_id', $product->id)->whereNull('product_variant_id')->first();
        if ($pw) {
            $pw->qte = $pw->qte + $base;
            $pw->save();
        }
    }

    /** Hospital payment method (cash / card / transfer / insurance...) -> a payment method, Cash by default. */
    private static function methodId(?string $method): ?int
    {
        $method = mb_strtolower(trim((string) $method));
        $names = [
            'card' => ['credit card', 'card', 'tarjeta'], 'transfer' => ['bank transfer', 'transfer', 'transferencia'],
            'insurance' => ['insurance', 'seguro'], 'cheque' => ['cheque', 'check'],
        ][$method] ?? [$method];
        foreach (array_merge($names, ['cash', 'efectivo']) as $n) {
            if ($n !== '' && ($id = PaymentMethod::whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [$n])->value('id'))) {
                return (int) $id;
            }
        }

        return PaymentMethod::whereNull('deleted_at')->orderBy('id')->value('id');
    }
}
