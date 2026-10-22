<?php

use App\Models\HospitalInvoice;
use App\Models\HospitalPayment;
use App\Models\Patient;
use App\Services\HospitalSale;
use App\Services\PatientClient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Hospital = commerce (user's rule, 2026-10-21, replacing 2026-10-20's
 * "hospital without Sales / Purchases / Suppliers"):
 *  - a patient IS a customer: every patient gets its customer (same email in
 *    the same company = same customer, else a new one; App\Services\PatientClient);
 *  - a hospital invoice IS a sale: hospital_invoices.sale_id and
 *    hospital_payments.payment_sale_id; invoices already there get their sale
 *    and their payments their sale payments, and the hospital's own journal
 *    entries are replaced by the sale's (one entry per bill);
 *  - hospital companies (by business type) get Sales, Purchases and Suppliers
 *    on (POS stays off), to buy their medicines from suppliers.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hospital_invoices') && ! Schema::hasColumn('hospital_invoices', 'sale_id')) {
            Schema::table('hospital_invoices', fn (Blueprint $t) => $t->unsignedBigInteger('sale_id')->nullable()->index()->after('lab_order_id'));
        }
        if (Schema::hasTable('hospital_payments') && ! Schema::hasColumn('hospital_payments', 'payment_sale_id')) {
            Schema::table('hospital_payments', fn (Blueprint $t) => $t->unsignedBigInteger('payment_sale_id')->nullable()->after('invoice_id'));
        }

        // Hospital companies: Sales, Purchases, Suppliers on.
        if (Schema::hasColumn('companies', 'business_type')) {
            foreach (DB::table('companies')->where('business_type', 'hospital')->whereNull('deleted_at')->pluck('id') as $cid) {
                $row = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->first(['id', 'module_flags']);
                if ($row) {
                    $flags = json_decode((string) $row->module_flags, true) ?: [];
                    foreach (['sales', 'purchases', 'suppliers', 'customers', 'products'] as $k) {
                        $flags[$k] = true;
                    }
                    DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
                }
            }
        }

        if (! Schema::hasTable('patients')) {
            return;
        }

        // Every patient -> its customer.
        foreach (Patient::withoutGlobalScope('company')->whereNull('deleted_at')->orderBy('id')->get() as $patient) {
            DB::transaction(fn () => PatientClient::ensure($patient));
        }
        $this->tagDemoClients();

        // Every live invoice -> its sale; payments -> sale payments; the
        // hospital's own entries removed (the sale's replace them).
        foreach (HospitalInvoice::withoutGlobalScope('company')->whereNull('deleted_at')->whereNull('sale_id')->orderBy('id')->get() as $invoice) {
            DB::transaction(function () use ($invoice) {
                $this->removeEntries('hospital_invoice', [$invoice->id]);
                HospitalSale::sync($invoice);
                $payments = HospitalPayment::withoutGlobalScope('company')->whereNull('deleted_at')->where('invoice_id', $invoice->id)->whereNull('payment_sale_id')->get();
                $this->removeEntries('hospital_payment', $payments->pluck('id')->all());
                foreach ($payments as $payment) {
                    HospitalSale::syncPayment($payment, $invoice->fresh());
                }
            });
        }
    }

    /**
     * A demo patient's customer (and its login) is demo too, so removing the
     * hospital demo removes them -- never a customer of a real patient.
     */
    public function tagDemoClients(): void
    {
        if (! Schema::hasTable('demo_records')) {
            return;
        }
        $demoPatients = DB::table('demo_records')->where('record_type', 'patients')->get(['company_id', 'module', 'record_id']);
        foreach ($demoPatients as $d) {
            $clientId = DB::table('patients')->where('id', $d->record_id)->value('client_id');
            if (! $clientId || DB::table('demo_records')->where('record_type', 'clients')->where('record_id', $clientId)->exists()) {
                continue;
            }
            // Only a customer that belongs to demo patients alone.
            if (DB::table('patients')->where('client_id', $clientId)->whereNotIn('id', $demoPatients->pluck('record_id'))->exists()) {
                continue;
            }
            $rows = [['record_type' => 'clients', 'record_id' => $clientId]];
            if ($userId = DB::table('clients')->where('id', $clientId)->value('user_id')) {
                $rows[] = ['record_type' => 'users', 'record_id' => $userId];
            }
            foreach ($rows as $r) {
                DB::table('demo_records')->insert($r + ['company_id' => $d->company_id, 'module' => $d->module, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function removeEntries(string $type, array $ids): void
    {
        if (! $ids || ! Schema::hasTable('acc_journal_entries')) {
            return;
        }
        $entryIds = DB::table('acc_journal_entries')->where('reference_type', $type)->whereIn('reference_id', $ids)->pluck('id');
        if ($entryIds->isEmpty()) {
            return;
        }
        DB::table('acc_journal_entry_lines')->whereIn('journal_entry_id', $entryIds)->delete();
        DB::table('acc_journal_entries')->whereIn('id', $entryIds)->delete();
    }

    public function down(): void
    {
    }
};
