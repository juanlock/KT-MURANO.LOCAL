<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Illuminate\Support\Facades\DB;

/**
 * Core examples: products (with stock), customers, suppliers, sales,
 * purchases, quotations and expenses over the last 90 days -- ported from
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
    private const COMPANY_WORDS = ['Global', 'Prime', 'Atlas', 'Delta', 'Nova', 'Summit', 'Horizon', 'Union', 'Vertex', 'Coastal'];
    private const COMPANY_TYPES = ['Supplies', 'Trading', 'Distribution', 'Wholesale', 'Logistics', 'Industries'];
    private const EXPENSE_DETAILS = ['Office rent', 'Electricity bill', 'Internet subscription', 'Fuel and transport', 'Cleaning services', 'Printer supplies', 'Team lunch', 'Equipment repair'];

    public function key(): string
    {
        return 'core';
    }

    public function label(): string
    {
        return 'Core (Products, Customers, Suppliers, Sales, Purchases, Quotations, Expenses)';
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

        $productIds = $this->makeProducts($ctx, 12);
        $clientIds = $this->makeClients($ctx, 8);
        $providerIds = $this->makeProviders($ctx, 5);

        return [
            'Products' => count($productIds),
            'Customers' => count($clientIds),
            'Suppliers' => count($providerIds),
            'Sales' => $this->makeSales($ctx, 20, $clientIds, $productIds, $paymentMethodId),
            'Purchases' => $this->makePurchases($ctx, 8, $providerIds, $productIds, $paymentMethodId),
            'Quotations' => $this->makeQuotations($ctx, 5, $clientIds, $productIds),
            'Expenses' => $this->makeExpenses($ctx, 8),
        ];
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
        $categoryId = $ctx->insert('categories', ['code' => 'DEMO'.random_int(100, 999), 'name' => 'Demo Category']);
        $unitId = $this->companyRowOr($ctx, 'units', fn () => $ctx->insert('units', [
            'name' => 'Piece', 'ShortName' => 'pc', 'base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1,
        ]));

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $cost = random_int(5, 400) + 0.99;
            $price = round($cost * (1 + random_int(20, 60) / 100), 2);
            $id = $ctx->insert('products', [
                'type' => 'is_single',
                'code' => 'DEMO'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                'Type_barcode' => 'CODE128',
                'name' => self::PRODUCT_NAMES[$i % count(self::PRODUCT_NAMES)],
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
            foreach ($ctx->warehouseIds as $wid) {
                $ctx->insert('product_warehouse', [
                    'product_id' => $id, 'warehouse_id' => $wid, 'qte' => random_int(30, 200), 'manage_stock' => 1,
                ]);
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
            $name = $ctx->pick(self::COMPANY_WORDS).' '.$ctx->pick(self::COMPANY_TYPES);
            $ids[] = $ctx->insert('providers', [
                'code' => ++$code,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'.demo'.random_int(1000, 9999).'@example.com',
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

    private function makeSales(DemoContext $ctx, int $count, array $clientIds, array $productIds, $paymentMethodId): int
    {
        if (! $clientIds || ! $productIds) {
            return 0;
        }
        for ($i = 1; $i <= $count; $i++) {
            $date = $ctx->randomDate();
            $warehouseId = $ctx->warehouse();
            [$lines, $grandTotal] = $this->pickLines($productIds, 'price');
            [$paid, $status] = $this->paidPart($grandTotal);
            $seq = str_pad((string) $i, 4, '0', STR_PAD_LEFT).random_int(10, 99);

            $saleId = $ctx->insert('sales', [
                'user_id' => $ctx->userId,
                'date' => $date->toDateString(), 'time' => $date->format('H:i:s'),
                'Ref' => 'DEMO-SL-'.$seq,
                'client_id' => $ctx->pick($clientIds),
                'warehouse_id' => $warehouseId,
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
                $ctx->insert('payment_sales', [
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
            $date = $ctx->randomDate();
            $warehouseId = $ctx->warehouse();
            [$lines, $grandTotal] = $this->pickLines($productIds, 'cost');
            [$paid, $status] = $this->paidPart($grandTotal);
            $received = random_int(1, 10) <= 7;
            $seq = str_pad((string) $i, 4, '0', STR_PAD_LEFT).random_int(10, 99);

            $purchaseId = $ctx->insert('purchases', [
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
                $ctx->insert('payment_purchases', [
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
            $date = $ctx->randomDate();
            $ctx->insert('expenses', [
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
