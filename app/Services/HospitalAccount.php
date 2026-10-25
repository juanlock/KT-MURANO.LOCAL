<?php

namespace App\Services;

use App\Http\Controllers\HospitalBillingController;
use App\Models\Admission;
use App\Models\HospitalInvoice;
use Illuminate\Support\Facades\DB;

/**
 * The account of a hospital stay (user's analysis, 2026-10-25).
 *
 * lines(): what the stay has cost so far -- the bed nights (or the DRG fixed
 * rate when the stay is billed by diagnosis group), the lab orders made during
 * the stay, the medicines the pharmacy dispensed for its treatments (already
 * out of stock -> billed as a service line, never moved twice) and its
 * surgeries' fees; each source only once (billed_invoice_id).
 *
 * split(): who pays what -- self-pay: all the patient; insurance: the patient
 * pays the deductible, the copay and the share not covered (100 - coverage %),
 * the insurer the rest.
 *
 * close(): ONE transaction -- the invoice (= a sale) with those lines, the
 * insurer / patient shares, the guarantee deposit applied as the patient's
 * first payment, the sources marked billed, the admission linked.
 */
class HospitalAccount
{
    public static function lines(Admission $a): array
    {
        $lines = [];
        $from = $a->admitted_at;
        $to = $a->discharged_at ?: now();
        $es = DB::table('settings')->where('company_id', $a->company_id)->whereNull('deleted_at')->value('default_language') === 'es';
        $line = fn ($type, $desc, $qty, $price, $src = null) => ['type' => $type, 'product_id' => null, 'description' => $desc,
            'quantity' => round((float) $qty, 2), 'unit_price' => round((float) $price, 2), 'total' => round((float) $qty * (float) $price, 2), 'source' => $src];

        // Bed nights, or the DRG fixed rate (whatever the days).
        $drg = $a->billing_mode === 'drg' && $a->drg_id ? DB::table('hms_drg')->where('id', $a->drg_id)->first() : null;
        if ($drg) {
            $lines[] = $line('procedure', ($es ? 'Tarifa GRD ' : 'DRG rate ').$drg->code.' — '.$drg->name, 1, $drg->price);
        } else {
            $label = ($es ? 'Estancia — ' : 'Stay — ').(optional($a->ward)->name ?: ($es ? 'sala' : 'ward')).($a->bed ? ' / '.$a->bed->bed_number : '');
            $lines[] = $line('bed', $label, $a->nights, $a->daily_rate);
        }

        // Lab orders of the stay, not billed yet.
        foreach (DB::table('lab_orders')->where('patient_id', $a->patient_id)->whereNull('deleted_at')->whereNull('billed_invoice_id')
            ->whereBetween('ordered_at', [$from, $to])->where('status', '!=', 'cancelled')->get(['id', 'reference', 'total']) as $o) {
            if ((float) $o->total > 0) {
                $lines[] = $line('lab', ($es ? 'Laboratorio ' : 'Lab order ').$o->reference, 1, $o->total, ['lab_orders', $o->id]);
            }
        }

        // Medicines dispensed for the stay's treatments (price of the product).
        $dispenses = DB::table('hms_dispenses as d')->join('hms_medication_orders as o', 'o.id', '=', 'd.order_id')
            ->join('hms_treatments as t', 't.id', '=', 'o.treatment_id')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->where('t.admission_id', $a->id)->whereNull('d.billed_invoice_id')
            ->get(['d.id', 'd.quantity', 'o.medicine', 'p.name as product', 'p.price']);
        foreach ($dispenses as $d) {
            if ((float) $d->price > 0) {
                $lines[] = $line('medicine', ($es ? 'Medicamento — ' : 'Medicine — ').($d->product ?: $d->medicine), $d->quantity, $d->price, ['hms_dispenses', $d->id]);
            }
        }

        // Surgeries of the stay with a fee.
        foreach (DB::table('hms_surgeries')->where('admission_id', $a->id)->whereNull('deleted_at')->whereNull('billed_invoice_id')
            ->where('status', '!=', 'cancelled')->get(['id', 'reference', 'planned_procedure', 'procedure_performed', 'fee']) as $s) {
            if ((float) $s->fee > 0) {
                $lines[] = $line('procedure', ($es ? 'Cirugía ' : 'Surgery ').$s->reference.' — '.($s->procedure_performed ?: $s->planned_procedure), 1, $s->fee, ['hms_surgeries', $s->id]);
            }
        }

        return $lines;
    }

    /** [insurer share, patient share] of a total, from the admission's coverage. */
    public static function split(Admission $a, float $total): array
    {
        if (! in_array($a->payer_type, ['private', 'public'], true) || $a->authorization_status === 'denied') {
            return [0.0, round($total, 2)];
        }
        $deductible = min($total, (float) $a->deductible);
        $copay = min($total - $deductible, (float) $a->copay);
        $rest = $total - $deductible - $copay;
        $coverage = max(0, min(100, $a->coverage_percent !== null ? (float) $a->coverage_percent : 100));
        $patient = round($deductible + $copay + $rest * (100 - $coverage) / 100, 2);

        return [round($total - $patient, 2), $patient];
    }

    /** The account so far (for the screen): lines, total, split, deposit. */
    public static function estimate(Admission $a): array
    {
        $lines = self::lines($a);
        $total = round(array_sum(array_column($lines, 'total')), 2);
        [$ins, $pat] = self::split($a, $total);

        return ['lines' => $lines, 'total' => $total, 'insurer_amount' => $ins, 'patient_amount' => $pat,
            'deposit' => (float) $a->deposit_amount, 'patient_after_deposit' => round(max(0, $pat - (float) $a->deposit_amount), 2)];
    }

    /** Close the account: the invoice with everything, in one transaction. */
    public static function close(Admission $a, ?int $userId): HospitalInvoice
    {
        if ($a->account_invoice_id && HospitalInvoice::whereNull('deleted_at')->whereKey($a->account_invoice_id)->exists()) {
            throw new \RuntimeException('This stay already has its account closed.');
        }
        $lines = self::lines($a);
        if (! $lines) {
            throw new \RuntimeException('Nothing to bill for this stay.');
        }
        $billing = app(HospitalBillingController::class);

        return DB::transaction(function () use ($a, $lines, $userId, $billing) {
            $invoice = HospitalInvoice::create([
                'company_id' => $a->company_id, 'reference' => HospitalInvoice::nextReference('INV'), 'patient_id' => $a->patient_id,
                'admission_id' => $a->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
                'discount' => 0, 'tax' => 0, 'status' => 'unpaid', 'created_by' => $userId,
                'payer_type' => $a->payer_type ?: 'self', 'insurer' => $a->insurer, 'authorization_no' => $a->authorization_no,
                'notes' => 'Account of the stay '.$a->reference,
            ]);
            $billing->syncItems($invoice, array_map(fn ($l) => array_diff_key($l, ['source' => 1]), $lines));
            $billing->recalculate($invoice);
            [$ins, $pat] = self::split($a, (float) $invoice->total);
            $invoice->update(['insurer_amount' => $ins, 'patient_amount' => $pat]);

            // Each source is billed once.
            foreach ($lines as $l) {
                if ($l['source']) {
                    DB::table($l['source'][0])->where('id', $l['source'][1])->update(['billed_invoice_id' => $invoice->id]);
                }
            }
            // The guarantee deposit is the patient's first payment (up to the patient's share).
            $deposit = min((float) $a->deposit_amount, $pat);
            if ($deposit > 0) {
                $billing->addPayment($invoice, $deposit, $a->deposit_method ?: 'cash', 'patient', 'Deposit at admission '.$a->reference, $userId);
            }
            $a->update(['account_invoice_id' => $invoice->id]);

            return $invoice->fresh();
        });
    }

    /** Minutes from the admission order to the patient in the bed (null when no order time). */
    public static function waitMinutes(Admission $a): ?int
    {
        return $a->ordered_at && $a->admitted_at && $a->admitted_at >= $a->ordered_at ? (int) $a->ordered_at->diffInMinutes($a->admitted_at) : null;
    }
}
