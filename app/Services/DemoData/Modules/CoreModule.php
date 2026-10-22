<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Core examples: products (with stock), customers, suppliers, sales,
 * purchases and expenses over the last 12 months (quotations: last 90 days),
 * posted to the journal like form-made documents -- ported from
 * the original DemoDataController, now per company and with every child row
 * (details, payments, product_warehouse) tagged for a clean reset.
 * Documents only reference DEMO products/customers/suppliers, so removing
 * them never touches real stock or balances.
 */
class CoreModule extends DemoModule
{
    private const PRODUCT_NAMES = [
        'Wireless Mouse', 'Mechanical Keyboard', 'USB-C Hub', 'Laptop Stand', 'HD Webcam',
        'Bluetooth Speaker', 'Noise-Cancelling Headphones', 'Portable SSD 1TB', 'Smartphone Case',
        'Power Bank 20000mAh', 'LED Desk Lamp', 'Ergonomic Chair', 'Coffee Maker', 'Electric Kettle',
    ];
    /**
     * A hospital's products are its pharmacy / supplies (prescriptions price
     * from them; the Pharmacy screen lists them). [es, en, generic, strength,
     * form es, form en, pack, prescription] -- names follow the company's
     * language (2026-10-21).
     */
    private const HOSPITAL_PRODUCTS = [
        ['Paracetamol 500 mg (caja x 20)', 'Paracetamol 500 mg (box of 20)', 'Paracetamol', '500 mg', 'Tableta', 'Tablet', '20', 0],
        ['Amoxicilina 500 mg (caja x 21)', 'Amoxicillin 500 mg (box of 21)', 'Amoxicillin', '500 mg', 'Cápsula', 'Capsule', '21', 1],
        ['Ibuprofeno 400 mg (caja x 20)', 'Ibuprofen 400 mg (box of 20)', 'Ibuprofen', '400 mg', 'Tableta', 'Tablet', '20', 0],
        ['Omeprazol 20 mg (caja x 14)', 'Omeprazole 20 mg (box of 14)', 'Omeprazole', '20 mg', 'Cápsula', 'Capsule', '14', 1],
        ['Loratadina 10 mg (caja x 10)', 'Loratadine 10 mg (box of 10)', 'Loratadine', '10 mg', 'Tableta', 'Tablet', '10', 0],
        ['Metformina 850 mg (caja x 30)', 'Metformin 850 mg (box of 30)', 'Metformin', '850 mg', 'Tableta', 'Tablet', '30', 1],
        ['Solución salina 0.9% 1 L', 'Saline solution 0.9% 1 L', 'Sodium chloride', '0.9%', 'Solución inyectable', 'Injectable solution', '1 L', 1],
        ['Gasas estériles (paquete x 50)', 'Sterile gauze pads (pack of 50)', null, null, 'Insumo', 'Supply', '50', 0],
        ['Guantes de nitrilo (caja x 100)', 'Nitrile gloves (box of 100)', null, null, 'Insumo', 'Supply', '100', 0],
        ['Jeringa descartable 5 ml (caja x 100)', 'Disposable syringe 5 ml (box of 100)', null, '5 ml', 'Insumo', 'Supply', '100', 0],
        ['Mascarillas quirúrgicas (caja x 50)', 'Surgical masks (box of 50)', null, null, 'Insumo', 'Supply', '50', 0],
        ['Alcohol 70% 1 L', 'Alcohol 70% 1 L', 'Ethanol', '70%', 'Solución tópica', 'Topical solution', '1 L', 0],
        ['Losartán 50 mg (caja x 30)', 'Losartan 50 mg (box of 30)', 'Losartan', '50 mg', 'Tableta', 'Tablet', '30', 1],
        ['Salbutamol inhalador 100 mcg', 'Salbutamol inhaler 100 mcg', 'Salbutamol', '100 mcg', 'Inhalador', 'Inhaler', '200 dosis', 1],
        ['Diclofenaco 75 mg/3 ml ampolla', 'Diclofenac 75 mg/3 ml ampoule', 'Diclofenac', '75 mg/3 ml', 'Ampolla', 'Ampoule', '1', 1],
        ['Ceftriaxona 1 g ampolla', 'Ceftriaxone 1 g vial', 'Ceftriaxone', '1 g', 'Polvo para inyección', 'Powder for injection', '1', 1],
    ];
    private const GENERIC_ES = [
        'Amoxicillin' => 'Amoxicilina', 'Ibuprofen' => 'Ibuprofeno', 'Omeprazole' => 'Omeprazol', 'Loratadine' => 'Loratadina',
        'Metformin' => 'Metformina', 'Sodium chloride' => 'Cloruro de sodio', 'Ethanol' => 'Etanol', 'Losartan' => 'Losartán',
        'Diclofenac' => 'Diclofenaco', 'Ceftriaxone' => 'Ceftriaxona',
    ];
    private const PHARMA_SUPPLIERS = ['Droguería Andina', 'Laboratorios Medifarma', 'Distribuidora Farmacéutica del Sur', 'Insumos Médicos Lima', 'Química Suiza'];
    private const COMPANY_WORDS =['Global', 'Prime', 'Atlas', 'Delta', 'Nova', 'Summit', 'Horizon', 'Union', 'Vertex', 'Coastal'];
    private const COMPANY_TYPES = ['Supplies', 'Trading', 'Distribution', 'Wholesale', 'Logistics', 'Industries'];
    /** Days back that sales / purchases / expenses spread over (a year, so accounting has history). */
    private const HISTORY_DAYS = 365;

    /** Demo document ids per table, posted to the journal at the end. */
    private array $posted = [];

    private const EXPENSE_DETAILS = ['Office rent', 'Electricity bill', 'Internet subscription', 'Fuel and transport', 'Cleaning services', 'Printer supplies', 'Team lunch', 'Equipment repair'];

    public function key(): string
    {
        return 'core';
    }

    public function label(): string
    {
        return 'Core (Products, Customers, Suppliers, Sales, Purchases, Quotations, Expenses)';
    }

    /** Offered when the company sells, buys or keeps accounts (not a school). */
    public function moduleFlags(): array
    {
        return ['products', 'sales', 'purchases', 'suppliers', 'accounting'];
    }

    public function dataTables(): array
    {
        // A company with its own products or documents is "in use" -- no core
        // examples. (Customers alone don't count: every company gets a
        // default Walk-in Customer automatically.)
        return ['products', 'sales', 'purchases'];
    }

    public function generate(DemoContext $ctx): array
    {
        $paymentMethodId = DB::table('payment_methods')->whereNull('deleted_at')->value('id');
        // Only what the company's modules use (user's rule, 2026-10-20): no demo
        // sales / quotations with Sales off, no purchases with Purchases off,
        // no suppliers when neither Purchases nor Suppliers is on (a hospital).
        // Read fresh (not ModuleFlags' per-request cache): on "create company with
        // demo data" the flags were written moments ago in this same request.
        $flags = \App\Services\DemoData\DemoRunner::moduleFlags($ctx->companyId);
        $on = fn (string $k) => ($flags[$k] ?? true) !== false;
        // No products / customers either when those modules are off (a school).
        $products = $on('products');
        // A hospital's customers are its patients and its sales its hospital bills
        // (2026-10-21, HospitalModule): no generic demo customers / sales here.
        $hospital = ($ctx->businessType ?? null) === 'hospital';
        $sales = $on('sales') && $products && ! $hospital;
        $purchases = $on('purchases') && $products;
        $suppliers = $purchases || $on('suppliers');

        $productIds = $products ? $this->makeProducts($ctx, 12) : [];
        $clientIds = $sales && $on('customers') ? $this->makeClients($ctx, 8) : [];
        $providerIds = $suppliers ? $this->makeProviders($ctx, 5) : [];
        // One demo sales agent, credited on about half of the demo sales, so
        // the Sales Agent field / Commissions have something to show.
        $agentId = $sales ? $this->makeSalesAgent($ctx) : null;

        return array_filter([
            'Products' => count($productIds),
            'Customers' => count($clientIds),
            'Suppliers' => count($providerIds),
            'Sales agents' => $agentId ? 1 : 0,
            'Sales' => $sales ? $this->makeSales($ctx, 48, $clientIds, $productIds, $paymentMethodId, $agentId) : 0,
            'Purchases' => $purchases ? $this->makePurchases($ctx, 24, $providerIds, $productIds, $paymentMethodId) : 0,
            'Quotations' => $sales ? $this->makeQuotations($ctx, 5, $clientIds, $productIds) : 0,
            // Expenses live in Accounting: none when it is off.
            'Expenses' => $on('accounting') ? $this->makeExpenses($ctx, 24) : 0,
            'Journal entries' => $this->postJournal($ctx),
        ], fn ($n, $k) => $n > 0 || in_array($k, ['Products', 'Expenses', 'Journal entries'], true), ARRAY_FILTER_USE_BOTH);
    }

    /** Sales, purchases, their payments and expenses -> journal (tagged demo). */
    private function postJournal(DemoContext $ctx): int
    {
        $n = 0;
        foreach (['sales' => 'fromSale', 'payment_sales' => 'fromPaymentSale', 'purchases' => 'fromPurchase', 'payment_purchases' => 'fromPaymentPurchase', 'expenses' => 'fromExpense'] as $table => $method) {
            $n += $ctx->postJournal($table, $method, $this->posted[$table] ?? []);
        }

        return $n;
    }

    /* ---------------------------------------------------------- entities */

    private function companyRowOr(DemoContext $ctx, string $table, callable $create): int
    {
        $q = DB::table($table)->whereNull('deleted_at');
        if ($ctx->hasColumn($table, 'company_id')) {
            $q->where('company_id', $ctx->companyId);
        }

        return (int) ($q->value('id') ?? $create());
    }

    private function makeProducts(DemoContext $ctx, int $count): array
    {
        $hospital = ($ctx->businessType ?? null) === 'hospital';
        $es = str_starts_with((string) DB::table('settings')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->value('default_language'), 'es');
        $categoryId = $ctx->insert('categories', ['code' => 'DEMO'.random_int(100, 999),
            'name' => $hospital ? ($es ? 'Medicamentos e insumos' : 'Medicines & supplies') : 'Demo Category']);
        $unitId = $this->companyRowOr($ctx, 'units', fn () => $ctx->insert('units', [
            'name' => 'Piece', 'ShortName' => 'pc', 'base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1,
        ]));

        $names = $hospital ? array_map(fn ($m) => $es ? $m[0] : $m[1], self::HOSPITAL_PRODUCTS) : self::PRODUCT_NAMES;
        if ($hospital) {
            $count = max($count, count(self::HOSPITAL_PRODUCTS));
        }
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $cost = $hospital ? random_int(1, 40) + 0.5 : random_int(5, 400) + 0.99;
            $price = round($cost * (1 + random_int(20, 60) / 100), 2);
            $med = $hospital ? self::HOSPITAL_PRODUCTS[$i % count(self::HOSPITAL_PRODUCTS)] : null;
            $pharmacy = $med ? array_filter([
                'generic_name' => $es && $med[2] ? (self::GENERIC_ES[$med[2]] ?? $med[2]) : $med[2], 'strength' => $med[3], 'dosage_form' => $es ? $med[4] : $med[5],
                'pack_size' => $med[6], 'prescription_required' => $med[7],
                'manufacturer' => $ctx->pick(['Laboratorios Andinos', 'Medifarma', 'Genfar', 'Bayer', 'Pfizer']),
            ], fn ($v) => $v !== null) : [];
            $id = $ctx->insert('products', $pharmacy + [
                'type' => 'is_single',
                'code' => 'DEMO'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                'Type_barcode' => 'CODE128',
                'name' => $names[$i % count($names)],
                'cost' => $cost,
                'price' => $price,
                'wholesale_price' => round($price * 0.9, 2),
                'min_price' => round($cost * 1.05, 2),
                'category_id' => $categoryId,
                'unit_id' => $unitId, 'unit_sale_id' => $unitId, 'unit_purchase_id' => $unitId,
                'tax_method' => '1', 'TaxNet' => 0, 'stock_alert' => 10,
                'is_variant' => 0, 'is_imei' => 0, 'is_active' => 1,
                'image' => 'no-image.png',
                'note' => 'Demo data',
            ]);
            $ids[] = $id;
            foreach ($ctx->warehouseIds as $w => $wid) {
                // Hospital: a few low / out of stock and expiring batches so the Pharmacy alerts have examples.
                $qty = $hospital && $w === 0 ? ([0 => 0, 3 => 6, 5 => 4][$i] ?? random_int(30, 200)) : random_int(30, 200);
                $ctx->insert('product_warehouse', [
                    'product_id' => $id, 'warehouse_id' => $wid, 'qte' => $qty, 'manage_stock' => 1,
                ]);
                if ($med && $med[2] && $qty > 0 && Schema::hasTable('product_batches')) {
                    $expiry = match ($i % 6) { 1 => now()->addDays(40), 4 => now()->subDays(10), default => now()->addMonths(random_int(8, 30)) };
                    $ctx->insert('product_batches', [
                        'product_id' => $id, 'warehouse_id' => $wid,
                        'batch_no' => 'L'.now()->format('y').str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
                        'expiry_date' => $expiry->toDateString(), 'mfg_date' => $expiry->copy()->subYears(2)->toDateString(),
                        'qty' => $qty, 'unit_cost' => $cost, 'status' => 'active', 'notes' => 'Demo data',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        return $ids;
    }

    private function makeClients(DemoContext $ctx, int $count): array
    {
        $code = (int) (DB::table('clients')->max('code') ?? 0);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            [$first, $last] = $ctx->personName();
            $ids[] = $ctx->insert('clients', [
                'code' => ++$code,
                'name' => "$first $last",
                'firstname' => $first, 'lastname' => $last,
                'email' => strtolower("$first.$last.demo".random_int(1000, 9999).'@example.com'),
                'phone' => '555'.random_int(1000000, 9999999),
                'country' => 'United States', 'city' => 'Demo City',
                'adresse' => random_int(1, 200).' Demo Street',
            ]);
        }

        return $ids;
    }

    private function makeProviders(DemoContext $ctx, int $count): array
    {
        $code = (int) (DB::table('providers')->max('code') ?? 0);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            // A hospital buys from laboratories / drug wholesalers (2026-10-21).
            $name = ($ctx->businessType ?? null) === 'hospital'
                ? self::PHARMA_SUPPLIERS[$i % count(self::PHARMA_SUPPLIERS)]
                : $ctx->pick(self::COMPANY_WORDS).' '.$ctx->pick(self::COMPANY_TYPES);
            $ids[] = $ctx->insert('providers', [
                'code' => ++$code,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', \Illuminate\Support\Str::ascii($name))).'.demo'.random_int(1000, 9999).'@example.com',
                'phone' => '555'.random_int(1000000, 9999999),
                'country' => 'United States', 'city' => 'Demo City',
                'adresse' => random_int(1, 200).' Industrial Zone',
            ]);
        }

        return $ids;
    }

    /* --------------------------------------------------------- documents */

    /** Random line items over the demo products; returns [lines, grandTotal]. */
    private function pickLines(array $productIds, string $priceColumn): array
    {
        $picked = (array) array_rand(array_flip($productIds), min(random_int(1, 4), count($productIds)));
        $prices = DB::table('products')->whereIn('id', $picked)->pluck($priceColumn, 'id');
        $lines = [];
        $total = 0.0;
        foreach ($picked as $pid) {
            $qty = random_int(1, 5);
            $price = (float) ($prices[$pid] ?? 10);
            $lineTotal = round($qty * $price, 2);
            $total += $lineTotal;
            $lines[] = ['product_id' => $pid, 'quantity' => $qty, 'price' => $price, 'total' => $lineTotal];
        }

        return [$lines, round($total, 2)];
    }

    private function adjustStock(int $productId, int $warehouseId, float $delta): void
    {
        DB::table('product_warehouse')
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->update(['qte' => DB::raw('GREATEST(0, qte + ('.$delta.'))')]);
    }

    /** paid / partial / unpaid, weighted towards paid like a real shop. */
    private function paidPart(float $grandTotal): array
    {
        $roll = random_int(1, 10);
        $paid = $roll <= 6 ? $grandTotal : ($roll <= 8 ? round($grandTotal * random_int(20, 80) / 100, 2) : 0.0);

        return [$paid, $paid >= $grandTotal ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid')];
    }

    /**
     * A demo sales agent (no login -- demo agents shouldn't provision real
     * accounts, same as CommissionsModule). Tagged like every demo row, so a
     * demo reset removes it. 0 when the install has no sales_agents table.
     */
    private function makeSalesAgent(DemoContext $ctx): int
    {
        if (! $ctx->columns('sales_agents')) {
            return 0;
        }
        [$first, $last] = $ctx->personName();

        return $ctx->insert('sales_agents', [
            'user_id' => null,
            'code' => 'SA-DEMO'.random_int(10, 99),
            'name' => "$first $last",
            'email' => strtolower("$first.$last").random_int(10, 99).'@example.com',
            'phone' => '+1 555 '.random_int(100, 999).' '.random_int(1000, 9999),
            'is_active' => 1,
            'notes' => 'Demo sales agent',
        ]);
    }

    private function makeSales(DemoContext $ctx, int $count, array $clientIds, array $productIds, $paymentMethodId, int $agentId = 0): int
    {
        if (! $clientIds || ! $productIds) {
            return 0;
        }
        for ($i = 1; $i <= $count; $i++) {
            $date = $ctx->randomDate(self::HISTORY_DAYS);
            $warehouseId = $ctx->warehouse();
            [$lines, $grandTotal] = $this->pickLines($productIds, 'price');
            [$paid, $status] = $this->paidPart($grandTotal);
            $seq = str_pad((string) $i, 4, '0', STR_PAD_LEFT).random_int(10, 99);

            $saleId = $this->posted['sales'][] = $ctx->insert('sales', [
                'user_id' => $ctx->userId,
                'date' => $date->toDateString(), 'time' => $date->format('H:i:s'),
                'Ref' => 'DEMO-SL-'.$seq,
                'client_id' => $ctx->pick($clientIds),
                'warehouse_id' => $warehouseId,
                'sales_agent_id' => $agentId && $i % 2 === 0 ? $agentId : null,
                'statut' => random_int(1, 10) <= 8 ? 'completed' : 'pending',
                'payment_statut' => $status,
                'paid_amount' => $paid, 'GrandTotal' => $grandTotal,
                'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'notes' => 'Demo data',
                'created_at' => $date, 'updated_at' => $date,
            ]);
            foreach ($lines as $line) {
                $ctx->insert('sale_details', [
                    'date' => $date->toDateString(), 'sale_id' => $saleId,
                    'product_id' => $line['product_id'], 'quantity' => $line['quantity'],
                    'price' => $line['price'], 'total' => $line['total'],
                    'TaxNet' => 0, 'discount' => 0, 'discount_method' => '2', 'tax_method' => '1',
                    'created_at' => $date, 'updated_at' => $date,
                ]);
                $this->adjustStock($line['product_id'], $warehouseId, -$line['quantity']);
            }
            if ($paid > 0) {
                $this->posted['payment_sales'][] = $ctx->insert('payment_sales', [
                    'user_id' => $ctx->userId, 'date' => $date->toDateString(),
                    'Ref' => 'DEMO-INV-SL-'.$seq, 'sale_id' => $saleId,
                    'montant' => $paid, 'change' => 0, 'payment_method_id' => $paymentMethodId,
                    'notes' => 'Demo data', 'created_at' => $date, 'updated_at' => $date,
                ]);
            }
        }

        return $count;
    }

    private function makePurchases(DemoContext $ctx, int $count, array $providerIds, array $productIds, $paymentMethodId): int
    {
        if (! $providerIds || ! $productIds) {
            return 0;
        }
        for ($i = 1; $i <= $count; $i++) {
            $date = $ctx->randomDate(self::HISTORY_DAYS);
            $warehouseId = $ctx->warehouse();
            [$lines, $grandTotal] = $this->pickLines($productIds, 'cost');
            [$paid, $status] = $this->paidPart($grandTotal);
            $received = random_int(1, 10) <= 7;
            $seq = str_pad((string) $i, 4, '0', STR_PAD_LEFT).random_int(10, 99);

            $purchaseId = $this->posted['purchases'][] = $ctx->insert('purchases', [
                'user_id' => $ctx->userId,
                'date' => $date->toDateString(), 'time' => $date->format('H:i:s'),
                'Ref' => 'DEMO-PR-'.$seq,
                'provider_id' => $ctx->pick($providerIds),
                'warehouse_id' => $warehouseId,
                'statut' => $received ? 'received' : 'pending',
                'payment_statut' => $status,
                'paid_amount' => $paid, 'GrandTotal' => $grandTotal,
                'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'notes' => 'Demo data',
                'created_at' => $date, 'updated_at' => $date,
            ]);
            foreach ($lines as $line) {
                $ctx->insert('purchase_details', [
                    'purchase_id' => $purchaseId,
                    'product_id' => $line['product_id'], 'quantity' => $line['quantity'],
                    'cost' => $line['price'], 'total' => $line['total'],
                    'TaxNet' => 0, 'discount' => 0, 'discount_method' => '2', 'tax_method' => '1',
                    'created_at' => $date, 'updated_at' => $date,
                ]);
                // Only received purchases touch stock, like the real flow.
                if ($received) {
                    $this->adjustStock($line['product_id'], $warehouseId, $line['quantity']);
                }
            }
            if ($paid > 0) {
                $this->posted['payment_purchases'][] = $ctx->insert('payment_purchases', [
                    'user_id' => $ctx->userId, 'date' => $date->toDateString(),
                    'Ref' => 'DEMO-INV-PR-'.$seq, 'purchase_id' => $purchaseId,
                    'montant' => $paid, 'change' => 0, 'payment_method_id' => $paymentMethodId,
                    'notes' => 'Demo data', 'created_at' => $date, 'updated_at' => $date,
                ]);
            }
        }

        return $count;
    }

    private function makeQuotations(DemoContext $ctx, int $count, array $clientIds, array $productIds): int
    {
        if (! $clientIds || ! $productIds) {
            return 0;
        }
        for ($i = 1; $i <= $count; $i++) {
            $date = $ctx->randomDate();
            [$lines, $grandTotal] = $this->pickLines($productIds, 'price');
            $quotationId = $ctx->insert('quotations', [
                'user_id' => $ctx->userId, 'date' => $date->toDateString(),
                'Ref' => 'DEMO-QT-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).random_int(10, 99),
                'client_id' => $ctx->pick($clientIds),
                'warehouse_id' => $ctx->warehouse(),
                'statut' => random_int(1, 10) <= 6 ? 'sent' : 'pending',
                'GrandTotal' => $grandTotal,
                'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'notes' => 'Demo data', 'created_at' => $date, 'updated_at' => $date,
            ]);
            foreach ($lines as $line) {
                $ctx->insert('quotation_details', [
                    'quotation_id' => $quotationId,
                    'product_id' => $line['product_id'], 'quantity' => $line['quantity'],
                    'price' => $line['price'], 'total' => $line['total'],
                    'TaxNet' => 0, 'discount' => 0, 'discount_method' => '2', 'tax_method' => '1',
                    'created_at' => $date, 'updated_at' => $date,
                ]);
            }
        }

        return $count;
    }

    private function makeExpenses(DemoContext $ctx, int $count): int
    {
        $categoryId = $ctx->insert('expense_categories', [
            'name' => 'Demo Expenses', 'user_id' => $ctx->userId, 'description' => 'Demo data',
        ]);
        for ($i = 1; $i <= $count; $i++) {
            $date = $ctx->randomDate(self::HISTORY_DAYS);
            $this->posted['expenses'][] = $ctx->insert('expenses', [
                'date' => $date->toDateString(),
                'Ref' => 'DEMO-EXP-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).random_int(10, 99),
                'user_id' => $ctx->userId,
                'expense_category_id' => $categoryId,
                'warehouse_id' => $ctx->warehouse(),
                'details' => $ctx->pick(self::EXPENSE_DETAILS),
                'amount' => random_int(20, 1500) + random_int(0, 99) / 100,
                'created_at' => $date, 'updated_at' => $date,
            ]);
        }

        return $count;
    }
}
