<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\HospitalInvoice;
use App\Services\HospitalAccount;
use App\Support\HospitalShifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The administrative side of an admission and its account (user's analysis,
 * 2026-10-25, phase 2): origin and admission order (wait to the bed),
 * coverage and authorization, signed consents, guarantee deposit, billing
 * mode (fee for service / DRG), closing the account (HospitalAccount),
 * payment plan; the company's DRG rates.
 *
 *  GET  hospital/admissions/{id}/account                admission data + estimate / invoice
 *  PUT  hospital/admissions/{id}/account                origin, order, coverage, billing mode
 *  POST hospital/admissions/{id}/consents {type, signed_by, relation}; DELETE .../consents/{type}
 *  POST hospital/admissions/{id}/deposit {amount, method}
 *  POST hospital/admissions/{id}/close-account          -> the invoice (sale)
 *  POST hospital/invoices/{id}/plan {installments, first_due, every_days}
 *  GET/POST/PUT/DELETE hospital/drg[/{id}]
 */
class HospitalAccountController extends Controller
{
    private function can(Request $request): void
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_admissions', 'hms_billing', 'hms_admission_desk'), 403);
    }

    private function admission($id): Admission
    {
        return Admission::with('patient', 'ward', 'bed', 'doctor')->whereNull('deleted_at')->findOrFail($id);
    }

    public function show(Request $request, $id)
    {
        $this->can($request);
        $a = $this->admission($id);
        $invoice = $a->account_invoice_id ? HospitalInvoice::whereNull('deleted_at')->find($a->account_invoice_id) : null;

        return response()->json([
            'admission' => [
                'id' => $a->id, 'reference' => $a->reference, 'status' => $a->status, 'patient_id' => $a->patient_id,
                'patient_name' => optional($a->patient)->name, 'patient_mrn' => optional($a->patient)->mrn,
                'ward' => optional($a->ward)->name, 'bed' => optional($a->bed)->bed_number, 'doctor_id' => $a->doctor_id,
                'admitted_at' => optional($a->admitted_at)->toIso8601String(), 'discharged_at' => optional($a->discharged_at)->toIso8601String(),
                'nights' => $a->nights, 'daily_rate' => (float) $a->daily_rate,
                'origin' => $a->origin, 'ordered_by' => $a->ordered_by, 'ordered_at' => optional($a->ordered_at)->format('Y-m-d H:i'),
                'wait_minutes' => HospitalAccount::waitMinutes($a),
                'payer_type' => $a->payer_type ?: ($a->patient && $a->patient->insurance_provider ? 'private' : 'self'),
                'insurer' => $a->insurer ?: optional($a->patient)->insurance_provider,
                'policy_number' => $a->policy_number ?: optional($a->patient)->insurance_number,
                'authorization_no' => $a->authorization_no, 'authorization_status' => $a->authorization_status,
                'coverage_percent' => $a->coverage_percent !== null ? (float) $a->coverage_percent : null,
                'copay' => $a->copay !== null ? (float) $a->copay : null, 'deductible' => $a->deductible !== null ? (float) $a->deductible : null,
                'consents' => (object) ($a->consents ?: []),
                'deposit_amount' => (float) $a->deposit_amount, 'deposit_method' => $a->deposit_method, 'deposit_at' => optional($a->deposit_at)->toIso8601String(),
                'billing_mode' => $a->billing_mode ?: 'fee', 'drg_id' => $a->drg_id,
            ],
            'consent_types' => Admission::CONSENTS,
            'estimate' => $invoice ? null : HospitalAccount::estimate($a),
            'invoice' => $invoice ? [
                'id' => $invoice->id, 'reference' => $invoice->reference, 'total' => (float) $invoice->total, 'paid' => (float) $invoice->paid,
                'status' => $invoice->status, 'insurer_amount' => (float) $invoice->insurer_amount, 'patient_amount' => (float) $invoice->patient_amount,
                'insurer_due' => HospitalBillingController::dueOf($invoice, 'insurer'), 'patient_due' => (float) $invoice->insurer_amount > 0 ? HospitalBillingController::dueOf($invoice, 'patient') : $invoice->due,
                'payment_plan' => $invoice->payment_plan ?: [],
            ] : null,
            'doctors' => DB::table('doctors')->where('company_id', $a->company_id)->whereNull('deleted_at')->where('is_active', 1)->orderBy('name')->get(['id', 'name']),
            'drg' => DB::table('hms_drg')->where('company_id', $a->company_id)->whereNull('deleted_at')->where('is_active', 1)->orderBy('code')->get(['id', 'code', 'name', 'price', 'avg_days']),
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->can($request);
        $a = $this->admission($id);
        $v = $request->validate([
            'origin' => ['nullable', Rule::in(Admission::ORIGINS)],
            'ordered_by' => ['nullable', \App\Support\CompanyExists::rule('doctors')],
            'ordered_at' => 'nullable|date',
            'payer_type' => ['nullable', Rule::in(Admission::PAYERS)],
            'insurer' => 'nullable|string|max:120', 'policy_number' => 'nullable|string|max:80',
            'authorization_no' => 'nullable|string|max:80', 'authorization_status' => 'nullable|in:not_needed,pending,approved,denied',
            'coverage_percent' => 'nullable|numeric|min:0|max:100', 'copay' => 'nullable|numeric|min:0', 'deductible' => 'nullable|numeric|min:0',
            'billing_mode' => 'nullable|in:fee,drg', 'drg_id' => ['nullable', \App\Support\CompanyExists::rule('hms_drg')],
        ]);
        if ($a->account_invoice_id && HospitalInvoice::whereNull('deleted_at')->whereKey($a->account_invoice_id)->exists()) {
            // The account is closed: only the order data can still change.
            $v = array_intersect_key($v, array_flip(['origin', 'ordered_by', 'ordered_at']));
        }
        if (($v['billing_mode'] ?? null) === 'drg' && empty($v['drg_id'])) {
            return response()->json(['message' => 'Pick the DRG rate.'], 422);
        }
        $a->update($v);

        return $this->show($request, $a->id);
    }

    /** A consent signed: who signed (patient or guardian) and when. */
    public function sign(Request $request, $id)
    {
        $this->can($request);
        $a = $this->admission($id);
        $v = $request->validate(['type' => ['required', Rule::in(array_keys(Admission::CONSENTS))], 'signed_by' => 'required|string|max:120', 'relation' => 'nullable|string|max:60']);
        $c = $a->consents ?: [];
        $c[$v['type']] = ['signed_by' => strip_tags($v['signed_by']), 'relation' => strip_tags((string) ($v['relation'] ?? '')), 'at' => now()->toIso8601String(),
            'by_user' => optional($request->user('api'))->id];
        $a->update(['consents' => $c]);

        return $this->show($request, $a->id);
    }

    public function unsign(Request $request, $id, $type)
    {
        $this->can($request);
        $a = $this->admission($id);
        $c = $a->consents ?: [];
        unset($c[$type]);
        $a->update(['consents' => $c ?: null]);

        return $this->show($request, $a->id);
    }

    /** The guarantee deposit taken at admission (applied when the account is closed). */
    public function deposit(Request $request, $id)
    {
        $this->can($request);
        $a = $this->admission($id);
        $v = $request->validate(['amount' => 'required|numeric|min:0', 'method' => 'nullable|string|max:32']);
        if ($a->account_invoice_id) {
            return response()->json(['message' => 'The account is closed: take a payment on its invoice instead.'], 422);
        }
        $a->update(['deposit_amount' => round((float) $v['amount'], 2), 'deposit_method' => $v['method'] ?: 'cash', 'deposit_at' => now()]);

        return $this->show($request, $a->id);
    }

    public function close(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_billing', 'hms_admissions'), 403);
        $a = $this->admission($id);
        try {
            $invoice = HospitalAccount::close($a, optional($request->user('api'))->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['invoice_id' => $invoice->id, 'reference' => $invoice->reference] + $this->show($request, $a->id)->getData(true));
    }

    /** Payment plan over what the patient still owes: N installments, every X days from the first date. */
    public function plan(Request $request, $invoiceId)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_billing'), 403);
        $invoice = HospitalInvoice::whereNull('deleted_at')->findOrFail($invoiceId);
        $v = $request->validate(['installments' => 'required|integer|min:0|max:36', 'first_due' => 'nullable|date', 'every_days' => 'nullable|integer|min:7|max:93']);
        $due = (float) $invoice->insurer_amount > 0 ? HospitalBillingController::dueOf($invoice, 'patient') : $invoice->due;
        $n = (int) $v['installments'];
        $plan = [];
        if ($n > 0 && $due > 0) {
            $each = floor($due / $n * 100) / 100;
            $date = \Carbon\Carbon::parse($v['first_due'] ?? now()->addDays(30));
            for ($i = 1; $i <= $n; $i++) {
                $plan[] = ['n' => $i, 'due' => $date->toDateString(), 'amount' => $i === $n ? round($due - $each * ($n - 1), 2) : $each];
                $date->addDays((int) ($v['every_days'] ?? 30));
            }
        }
        $invoice->update(['payment_plan' => $plan ?: null]);

        return response()->json(['payment_plan' => $plan]);
    }

    // ------------------------------------------------------------------ DRG rates

    public function drgIndex(Request $request)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_setup', 'hms_billing', 'hms_admissions'), 403);
        $cid = (int) Admission::actingCompanyId();

        return response()->json(['drg' => DB::table('hms_drg')->when($cid, fn ($q) => $q->where('company_id', $cid))->whereNull('deleted_at')->orderBy('code')->get()]);
    }

    public function drgSave(Request $request, $id = null)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_setup'), 403);
        $cid = (int) Admission::actingCompanyId();
        abort_unless($cid, 422, 'Pick the company first.');
        $v = $request->validate(['code' => 'required|string|max:24', 'name' => 'required|string|max:160', 'price' => 'required|numeric|min:0',
            'avg_days' => 'nullable|integer|min:0|max:365', 'is_active' => 'boolean']);
        $row = ['code' => strip_tags($v['code']), 'name' => strip_tags($v['name']), 'price' => $v['price'], 'avg_days' => $v['avg_days'] ?? null,
            'is_active' => (bool) ($v['is_active'] ?? true), 'updated_at' => now()];
        if ($id) {
            DB::table('hms_drg')->where('company_id', $cid)->where('id', $id)->update($row);
        } else {
            DB::table('hms_drg')->insert($row + ['company_id' => $cid, 'created_at' => now()]);
        }

        return $this->drgIndex($request);
    }

    public function drgDelete(Request $request, $id)
    {
        abort_unless(HospitalShifts::can($request->user('api'), 'hms_setup'), 403);
        DB::table('hms_drg')->where('company_id', (int) Admission::actingCompanyId())->where('id', $id)->update(['deleted_at' => now()]);

        return $this->drgIndex($request);
    }
}
