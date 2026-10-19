<?php

namespace App\Http\Controllers;

use App\Services\PayPalInvoicePayment;
use App\Services\PayPalService;
use App\Support\PublicUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * PayPal module (2026-10-19): the customer's public "Pay now" page of an
 * invoice -- no sign-in. The page link is signed (PayPalInvoicePayment::payLink,
 * built on the company's public address); the money goes to the company's own
 * PayPal account (PayPalService::forCompany).
 *
 *  GET  /pay/{sale}              invoice summary + "Pay with PayPal"
 *  POST /pay/{sale}              creates the PayPal order for the amount due -> PayPal
 *  GET  /pay/paypal/return       capture -> payment recorded on the sale -> thank-you
 *  GET  /pay/paypal/cancel       back to the pay page
 *  POST /pay/paypal/webhook/{c}  PAYMENT.CAPTURE.COMPLETED (in case the customer
 *                                never comes back), verified with that company's keys
 */
class PayPalInvoiceController extends Controller
{
    /** Page texts (2026-10-19): the customer's language -- English or Spanish. */
    private const TEXTS = [
        'en' => [
            'title_pay' => 'Pay invoice :ref', 'title' => 'Invoice payment', 'not_found' => 'Invoice not found',
            'not_found_text' => 'This invoice no longer exists. Please contact the company that sent you the link.',
            'paid_title' => 'Payment received', 'paid_text' => 'Thank you! Your PayPal payment of :amount was applied to invoice :ref.',
            'invoice' => 'Invoice :ref', 'customer' => 'Customer', 'date' => 'Date', 'total' => 'Total', 'paid' => 'Paid', 'due' => 'Amount due',
            'cancelled_inv' => 'This invoice was cancelled.', 'paid_full' => 'This invoice is paid in full.',
            'unavailable' => 'Online payment is not available for this invoice. Please contact :company.',
            'button' => 'Pay :amount with PayPal',
            'help' => 'You will be taken to PayPal to pay with your PayPal account or a card. The payment is applied to this invoice right away.',
            'cancelled' => 'The PayPal payment was cancelled. Nothing was charged.',
            'err_down' => 'PayPal is not available right now. Please try again in a few minutes.',
            'err_capture' => 'The payment was not completed by PayPal. Nothing was charged -- you can try again.',
            'date_format' => 'M j, Y',
        ],
        'es' => [
            'title_pay' => 'Pagar factura :ref', 'title' => 'Pago de factura', 'not_found' => 'Factura no encontrada',
            'not_found_text' => 'Esta factura ya no existe. Comuníquese con la empresa que le envió el enlace.',
            'paid_title' => 'Pago recibido', 'paid_text' => '¡Gracias! Su pago con PayPal de :amount se aplicó a la factura :ref.',
            'invoice' => 'Factura :ref', 'customer' => 'Cliente', 'date' => 'Fecha', 'total' => 'Total', 'paid' => 'Pagado', 'due' => 'Saldo a pagar',
            'cancelled_inv' => 'Esta factura fue anulada.', 'paid_full' => 'Esta factura está pagada por completo.',
            'unavailable' => 'El pago en línea no está disponible para esta factura. Comuníquese con :company.',
            'button' => 'Pagar :amount con PayPal',
            'help' => 'Irá a PayPal para pagar con su cuenta de PayPal o con tarjeta. El pago se aplica a esta factura de inmediato.',
            'cancelled' => 'El pago con PayPal fue cancelado. No se cobró nada.',
            'err_down' => 'PayPal no está disponible en este momento. Intente de nuevo en unos minutos.',
            'err_capture' => 'PayPal no completó el pago. No se cobró nada; puede intentarlo de nuevo.',
            'date_format' => 'd/m/Y',
        ],
    ];

    /** The customer's language: the page's EN / ES switch (cookie), else the browser's language. */
    private function lang(Request $request): string
    {
        $picked = (string) $request->cookie('pay_lang', '');
        if (isset(self::TEXTS[$picked])) {
            return $picked;
        }

        return $request->getPreferredLanguage(['en', 'es']) === 'es' ? 'es' : 'en';
    }

    private function sale(int $saleId): ?object
    {
        return DB::table('sales as s')->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->leftJoin('companies as co', 'co.id', '=', 's.company_id')
            ->where('s.id', $saleId)->whereNull('s.deleted_at')
            ->first(['s.id', 's.Ref', 's.date', 's.company_id', 's.GrandTotal', 's.paid_amount', 's.payment_statut', 's.statut',
                'c.name as client_name', 'co.name as company_name']);
    }

    private function view(?object $sale, array $extra = [])
    {
        $cid = (int) ($sale->company_id ?? 0);
        $lang = $this->lang(request());
        $texts = self::TEXTS[$lang];
        $t = fn (string $key, array $r = []) => strtr($texts[$key] ?? $key, collect($r)->mapWithKeys(fn ($v, $k) => [':'.$k => $v])->all());
        if (isset($extra['message']) && isset($texts[$extra['message']])) {
            $extra['message'] = $texts[$extra['message']];
        }

        return response()->view('paypal.pay', $extra + [
            'lang' => $lang,
            't' => $t,
            'sale' => $sale,
            'due' => $sale ? PayPalInvoicePayment::due($sale) : 0,
            'currency' => $cid ? PayPalInvoicePayment::currency($cid) : 'USD',
            'available' => $cid && PayPalInvoicePayment::available($cid),
            'state' => 'pay',
            'message' => null,
        ]);
    }

    public function show(Request $request, int $sale)
    {
        $s = $this->sale($sale);

        return $this->view($s, ['state' => $request->query('cancelled') ? 'cancelled' : 'pay']);
    }

    public function start(Request $request, int $sale)
    {
        $s = $this->sale($sale);
        if (! $s || $s->statut === 'cancelled' || ! PayPalInvoicePayment::available((int) $s->company_id)) {
            return $this->view($s);
        }
        $due = PayPalInvoicePayment::due($s);
        if ($due <= 0) {
            return $this->view($s);
        }

        $cid = (int) $s->company_id;
        $currency = PayPalInvoicePayment::currency($cid);
        try {
            $order = PayPalService::forCompany($cid)->createOrder(
                $due, $currency, 'Invoice '.$s->Ref.' - '.$s->company_name,
                ['sale_id' => (int) $s->id, 'company_id' => $cid],
                PublicUrl::to($cid, '/pay/paypal/return'),
                PublicUrl::to($cid, '/pay/paypal/cancel'),
                $this->lang($request) === 'es' ? 'es-US' : 'en-US',
            );
        } catch (\Throwable $e) {
            Log::error('PayPal invoice order failed: '.$e->getMessage(), ['sale_id' => $s->id]);

            return $this->view($s, ['message' => 'err_down']);
        }

        DB::table('paypal_payments')->insert([
            'company_id' => $cid, 'sale_id' => $s->id, 'paypal_order_id' => $order['id'],
            'amount' => $due, 'currency' => $currency, 'status' => 'created',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->away($order['url']);
    }

    public function return(Request $request)
    {
        $orderId = (string) $request->query('token', '');
        $pp = $orderId !== '' ? DB::table('paypal_payments')->where('paypal_order_id', $orderId)->first() : null;
        if (! $pp) {
            return $this->view(null);
        }
        $s = $this->sale((int) $pp->sale_id);
        if ($pp->status !== 'captured') {
            $cap = PayPalService::forCompany((int) $pp->company_id)->captureOrder($orderId);
            if (! $cap['success'] || ! $cap['capture_id']) {
                DB::table('paypal_payments')->where('id', $pp->id)->where('status', 'created')->update(['status' => 'failed', 'updated_at' => now()]);

                return $this->view($s, ['message' => 'err_capture']);
            }
            PayPalInvoicePayment::record($orderId, $cap['capture_id'], $cap['payer_email'] ?? null, $cap['payer_name'] ?? null);
            $s = $this->sale((int) $pp->sale_id);
        }

        return $this->view($s, ['state' => 'paid', 'paidAmount' => (float) $pp->amount]);
    }

    public function cancel(Request $request)
    {
        $orderId = (string) $request->query('token', '');
        $pp = $orderId !== '' ? DB::table('paypal_payments')->where('paypal_order_id', $orderId)->first() : null;
        if (! $pp) {
            return $this->view(null);
        }
        DB::table('paypal_payments')->where('id', $pp->id)->where('status', 'created')->update(['status' => 'cancelled', 'updated_at' => now()]);

        return redirect()->to(PublicUrl::base((int) $pp->company_id).URL::signedRoute('invoice.pay', ['sale' => $pp->sale_id, 'cancelled' => 1], null, false));
    }

    public function webhook(Request $request, int $company)
    {
        $svc = PayPalService::forCompany($company);
        if (! $svc->isConfigured()) {
            return response()->json(['ok' => false], 404);
        }
        $payload = $request->getContent();
        $check = $svc->verifyWebhook($payload, (string) $request->header('PAYPAL-TRANSMISSION-SIG', ''));
        if (! $check['valid']) {
            return response()->json(['ok' => false], 400);
        }
        if ($check['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
            $res = json_decode($payload, true)['resource'] ?? [];
            $orderId = (string) ($res['supplementary_data']['related_ids']['order_id'] ?? '');
            if ($orderId !== '' && DB::table('paypal_payments')->where('paypal_order_id', $orderId)->where('company_id', $company)->exists()) {
                PayPalInvoicePayment::record($orderId, (string) ($res['id'] ?? ''));
            }
        } elseif (in_array($check['event_type'], ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'], true) && $check['transaction_id']) {
            // Refunds are handled in Murano by hand (sale return / payment delete); just mark it.
            DB::table('paypal_payments')->where('company_id', $company)->where('capture_id', $check['transaction_id'])
                ->update(['status' => 'refunded', 'updated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
