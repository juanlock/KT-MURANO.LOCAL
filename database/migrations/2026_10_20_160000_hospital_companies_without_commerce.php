<?php

use App\Services\DemoData\DemoRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Hospital companies (user's rule, 2026-10-20): a company of business type
 * "Hospital / clinic" works with Hospital Management -- patients, billing --
 * not with Sales, POS, Purchases or Suppliers (switchable modules since today,
 * BusinessTypes::EXCLUDE). For each existing hospital company (by business
 * type, not id -- today: Vitalis):
 *  - those four modules off, Hospital Management on (other switches untouched);
 *  - their DEMO rows removed (demo sales, quotations, purchases, their details
 *    and payments, demo customers / suppliers / sales agent and their demo
 *    logins, and the journal entries of those documents) -- only ids listed in
 *    demo_records, real data is never touched;
 *  - its demo products renamed to pharmacy items (prescriptions price from them).
 * Its users start on the Hospital dashboard by code (BusinessTypes::HOME).
 * Safe to run twice.
 */
return new class extends Migration
{
    private const OFF = ['sales', 'pos', 'purchases', 'suppliers'];

    private const DEMO_TYPES = [
        'sales', 'sale_details', 'payment_sales', 'quotations', 'quotation_details',
        'purchases', 'purchase_details', 'payment_purchases',
        'sale_returns', 'sale_return_details', 'payment_sale_returns',
        'purchase_returns', 'purchase_return_details', 'payment_purchase_returns',
        'clients', 'providers', 'sales_agents',
    ];

    private const PHARMACY = [
        'Paracetamol 500 mg (box of 20)', 'Amoxicillin 500 mg (box of 21)', 'Ibuprofen 400 mg (box of 20)', 'Omeprazole 20 mg (box of 14)',
        'Loratadine 10 mg (box of 10)', 'Metformin 850 mg (box of 30)', 'Saline solution 0.9% 1 L', 'Sterile gauze pads (pack of 50)',
        'Nitrile gloves (box of 100)', 'Disposable syringe 5 ml (box of 100)', 'Surgical masks (box of 50)', 'Alcohol 70% 1 L',
        'Losartan 50 mg (box of 30)', 'Salbutamol inhaler 100 mcg',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'business_type')) {
            return;
        }
        $ids = DB::table('companies')->where('business_type', 'hospital')->whereNull('deleted_at')->pluck('id');
        foreach ($ids as $cid) {
            $cid = (int) $cid;
            // Module switches.
            $row = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->first(['id', 'module_flags']);
            if ($row) {
                $flags = json_decode((string) $row->module_flags, true) ?: [];
                foreach (self::OFF as $k) {
                    $flags[$k] = false;
                }
                $flags['hospital'] = true;
                DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
            }

            // Demo rows of those modules.
            if (Schema::hasTable('demo_records')) {
                DemoRunner::purgeTypes($cid, self::DEMO_TYPES);

                // Demo products -> pharmacy items (only demo-tagged ones).
                $productIds = DB::table('demo_records')->where('company_id', $cid)->where('record_type', 'products')->orderBy('record_id')->pluck('record_id');
                foreach ($productIds->values() as $i => $pid) {
                    DB::table('products')->where('id', $pid)->where('note', 'Demo data')
                        ->update(['name' => self::PHARMACY[$i % count(self::PHARMACY)], 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Module switches can be turned back on in Business Modules; removed demo rows are not recreated.
    }
};
