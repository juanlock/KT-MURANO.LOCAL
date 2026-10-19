<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\PayPalInvoicePayment;
use App\Services\PayPalService;
use App\Support\PublicUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Settings -> PayPal (PayPal module, 2026-10-19), permission paypal_manage,
 * for the acting company (a SuperAdmin's selected company, else the user's
 * own): the company's own PayPal REST app (Client ID / Secret, sandbox or
 * live, webhook id), the account the payments go to, and its PayPal payments.
 * The secret is stored encrypted and never sent back (blank = keep it).
 */
class PayPalSettingsController extends Controller
{
    use ScopesToCompany;

    private function guard(Request $request): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'paypal_manage'), 403, 'Your role can\'t manage PayPal.');

        return $this->resolveActingCompanyId($request);
    }

    public function show(Request $request)
    {
        $companyId = $this->guard($request);
        $row = DB::table('paypal_settings')->where('company_id', $companyId)->first();

        return response()->json([
            'enabled' => (bool) ($row->enabled ?? false),
            'client_id' => (string) ($row->client_id ?? ''),
            'has_secret' => (bool) ($row->client_secret ?? null),
            'sandbox' => (bool) ($row->sandbox ?? true),
            'webhook_id' => (string) ($row->webhook_id ?? ''),
            'account_id' => $row->account_id ?? null,
            'currency' => PayPalInvoicePayment::currency($companyId),
            'webhook_url' => PublicUrl::to($companyId, '/pay/paypal/webhook/'.$companyId),
            'accounts' => DB::table('accounts')->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('account_name')->get(['id', 'account_name', 'account_num']),
        ]);
    }

    public function update(Request $request)
    {
        $companyId = $this->guard($request);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'client_id' => 'nullable|string|max:191',
            'client_secret' => 'nullable|string|max:255',
            'sandbox' => 'required|boolean',
            'webhook_id' => 'nullable|string|max:100',
            'account_id' => 'nullable|integer',
        ]);
        if (! empty($data['account_id']) && ! DB::table('accounts')->where('id', $data['account_id'])->where('company_id', $companyId)->whereNull('deleted_at')->exists()) {
            return response()->json(['message' => 'That account is not one of this company\'s accounts.'], 422);
        }

        $values = [
            'enabled' => $data['enabled'],
            'client_id' => trim((string) ($data['client_id'] ?? '')) ?: null,
            'sandbox' => $data['sandbox'],
            'webhook_id' => trim((string) ($data['webhook_id'] ?? '')) ?: null,
            'account_id' => $data['account_id'] ?? null,
            'updated_at' => now(),
        ];
        if (trim((string) ($data['client_secret'] ?? '')) !== '') {
            $values['client_secret'] = Crypt::encryptString(trim($data['client_secret']));
        }
        $exists = DB::table('paypal_settings')->where('company_id', $companyId)->exists();
        if ($exists) {
            DB::table('paypal_settings')->where('company_id', $companyId)->update($values);
        } else {
            DB::table('paypal_settings')->insert($values + ['company_id' => $companyId, 'created_at' => now()]);
        }

        if ($values['enabled'] && (! $values['client_id'] || ! DB::table('paypal_settings')->where('company_id', $companyId)->value('client_secret'))) {
            return response()->json(['ok' => true, 'warning' => 'Saved, but PayPal stays unavailable until both the Client ID and the Secret are set.']);
        }

        return response()->json(['ok' => true]);
    }

    public function test(Request $request)
    {
        $companyId = $this->guard($request);
        $svc = PayPalService::forCompany($companyId);

        return response()->json($svc->testConnection());
    }

    public function payments(Request $request)
    {
        $companyId = $this->guard($request);
        $rows = DB::table('paypal_payments as p')->leftJoin('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->where('p.company_id', $companyId)->orderByDesc('p.id')->limit(200)
            ->get(['p.id', 'p.sale_id', 's.Ref', 'c.name as client_name', 'p.paypal_order_id', 'p.capture_id', 'p.amount', 'p.currency',
                'p.status', 'p.payer_email', 'p.payer_name', 'p.created_at']);

        return response()->json(['payments' => $rows]);
    }

    /** "Pay now" link of an invoice (Sales -> the sale's menu). */
    public function saleLink(Request $request, int $id)
    {
        $user = $request->user('api');
        abort_unless(HelpdeskAccess::can($user, 'Sales_view'), 403, 'Your role can\'t view sales.');
        $sale = DB::table('sales')->where('id', $id)->whereNull('deleted_at')->first(['id', 'Ref', 'company_id', 'GrandTotal', 'paid_amount']);
        abort_unless($sale && ($user->type === 'SuperAdmin' || (int) $sale->company_id === (int) $user->company_id), 404, 'Sale not found.');
        if (! PayPalInvoicePayment::available((int) $sale->company_id)) {
            return response()->json(['message' => 'PayPal is not set up for this company (Settings -> PayPal).'], 422);
        }

        return response()->json([
            'url' => PayPalInvoicePayment::payLink((int) $sale->id),
            'Ref' => $sale->Ref,
            'due' => PayPalInvoicePayment::due($sale),
        ]);
    }
}
