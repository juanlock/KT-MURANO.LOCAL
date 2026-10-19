<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PaymentSale;
use App\Models\Sale;
use App\Support\ModuleFlags;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * PayPal module (2026-10-19): a customer pays an invoice ("Pay now" link) into
 * the company's OWN PayPal account (paypal_settings, PayPalService::forCompany).
 *
 *  - payLink()   public signed link to the invoice's pay page (PublicUrl base)
 *  - due()       what is still owed on the sale
 *  - record()    a COMPLETED capture -> sale payment (method PayPal, the
 *                account set in Settings -> PayPal), the account balance, the
 *                sale's paid amount / status, the paypal_payments row -- all
 *                in one transaction, never twice for the same capture
 *                (return page and webhook can both arrive).
 */
class PayPalInvoicePayment
{
    /** The module is on for the company and its PayPal account is set up. */
    public static function available(int $companyId): bool
    {
        return ModuleFlags::enabled($companyId, 'paypal') && PayPalService::forCompany($companyId)->isConfigured();
    }

    /** Public "Pay now" link of a sale (signed, works without signing in), or null when PayPal isn't available. */
    public static function payLink(int $saleId): ?string
    {
        $companyId = (int) DB::table('sales')->where('id', $saleId)->value('company_id');
        if (! $companyId || ! self::available($companyId)) {
            return null;
        }

        return PublicUrl::base($companyId).URL::signedRoute('invoice.pay', ['sale' => $saleId], null, false);
    }

    public static function due(object $sale): float
    {
        return round(max(0, (float) $sale->GrandTotal - (float) $sale->paid_amount), 2);
    }

    public static function currency(int $companyId): string
    {
        $code = DB::table('settings as s')->join('currencies as c', 'c.id', '=', 's.currency_id')
            ->where('s.company_id', $companyId)->whereNull('s.deleted_at')->value('c.code');

        return strtoupper((string) ($code ?: 'USD'));
    }

    /**
     * Record a completed capture on its sale. Returns the paypal_payments row
     * (fresh), or null when the order is unknown.
     */
    public static function record(string $orderId, string $captureId, ?string $payerEmail = null, ?string $payerName = null): ?object
    {
        return DB::transaction(function () use ($orderId, $captureId, $payerEmail, $payerName) {
            $pp = DB::table('paypal_payments')->where('paypal_order_id', $orderId)->lockForUpdate()->first();
            if (! $pp) {
                return null;
            }
            if ($pp->status === 'captured' && $pp->payment_sale_id) {
                return $pp; // already recorded (return page vs webhook)
            }

            $sale = Sale::whereKey($pp->sale_id)->lockForUpdate()->first();
            if (! $sale) {
                DB::table('paypal_payments')->where('id', $pp->id)->update(['status' => 'captured', 'capture_id' => $captureId, 'updated_at' => now()]);
                Log::error('PayPal capture for a sale that no longer exists', ['order' => $orderId, 'sale_id' => $pp->sale_id]);

                return DB::table('paypal_payments')->find($pp->id);
            }

            $settings = DB::table('paypal_settings')->where('company_id', $pp->company_id)->first();
            $account = $settings && $settings->account_id
                ? Account::withoutGlobalScope('company')->where('company_id', $pp->company_id)->whereNull('deleted_at')->find($settings->account_id)
                : null;
            $methodId = DB::table('payment_methods')->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', ['paypal'])->value('id');
            $amount = (float) $pp->amount;
            $payer = trim(($payerName ?: '').($payerEmail ? ' <'.$payerEmail.'>' : ''));

            $payment = PaymentSale::create([
                'sale_id' => $sale->id,
                'Ref' => app(\App\Http\Controllers\PaymentSalesController::class)->getNumberOrder(),
                'date' => now(), 'account_id' => $account?->id, 'payment_method_id' => $methodId,
                'montant' => $amount, 'change' => 0,
                'notes' => 'PayPal #'.$captureId.($payer !== '' ? ' - '.$payer : ''),
                'user_id' => $sale->user_id,
            ]);
            if ($account) {
                $account->update(['balance' => $account->balance + $amount]);
            }
            $paid = round((float) $sale->paid_amount + $amount, 2);
            $sale->update([
                'paid_amount' => $paid,
                'payment_statut' => $paid + 0.004 >= (float) $sale->GrandTotal ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            ]);

            DB::table('paypal_payments')->where('id', $pp->id)->update([
                'status' => 'captured', 'capture_id' => $captureId, 'payment_sale_id' => $payment->id,
                'payer_email' => $payerEmail ?: $pp->payer_email, 'payer_name' => $payerName ?: $pp->payer_name,
                'updated_at' => now(),
            ]);

            return DB::table('paypal_payments')->find($pp->id);
        });
    }
}
