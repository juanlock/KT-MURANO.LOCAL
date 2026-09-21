<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Demo Data generator (System Settings → Demo Data).
 *
 * Every generated row is registered in demo_records (record_type, record_id),
 * so reset() removes exactly what generate() created — real data is never
 * touched. Demo sales/purchases/quotations only ever reference demo
 * products/customers/suppliers (auto-created when missing), so removing them
 * can never corrupt real stock or balances.
 */
class DemoDataController extends Controller
{
    // Entity => [table, soft-deletes?]. Shown in status() and purged in reset().
    private const ENTITIES = [
        'products' => ['products', true],
        'clients' => ['clients', true],
        'providers' => ['providers', true],
        'sales' => ['sales', true],
        'purchases' => ['purchases', true],
        'quotations' => ['quotations', true],
        'expenses' => ['expenses', true],
    ];

    private const PRODUCT_NAMES = [
        'Wireless Mouse', 'Mechanical Keyboard', 'USB-C Hub', 'Laptop Stand', 'HD Webcam',
        'Bluetooth Speaker', 'Noise-Cancelling Headphones', 'Portable SSD 1TB', 'Smartphone Case',
        'Screen Protector', 'Power Bank 20000mAh', 'LED Desk Lamp', 'Ergonomic Chair', 'Monitor 27"',
        'Graphics Tablet', 'Smart Watch', 'Fitness Tracker', 'Action Camera', 'Tripod', 'Ring Light',
        'Coffee Maker', 'Electric Kettle', 'Air Fryer', 'Blender', 'Toaster',
        'Office Desk', 'Bookshelf', 'Filing Cabinet', 'Whiteboard', 'Paper Shredder',
    ];

    private const FIRST_NAMES = ['James', 'Sarah', 'Mohamed', 'Fatima', 'David', 'Aisha', 'Carlos', 'Nadia', 'Kevin', 'Leila', 'Omar', 'Julia', 'Youssef', 'Emma', 'Karim'];
    private const LAST_NAMES = ['Smith', 'Johnson', 'Alami', 'Benali', 'Garcia', 'Martin', 'Idrissi', 'Brown', 'Tazi', 'Lee', 'Haddad', 'Wilson', 'Mansouri', 'Clark', 'Amrani'];
    private const COMPANY_WORDS = ['Global', 'Prime', 'Atlas', 'Delta', 'Nova', 'Summit', 'Horizon', 'Union', 'Vertex', 'Coastal'];
    private const COMPANY_TYPES = ['Supplies', 'Trading', 'Distribution', 'Wholesale', 'Import-Export', 'Logistics', 'Industries', 'Group'];
    private const EXPENSE_DETAILS = ['Office rent', 'Electricity bill', 'Internet subscription', 'Fuel and transport', 'Cleaning services', 'Printer supplies', 'Team lunch', 'Equipment repair', 'Software subscription', 'Water bill'];

    /* -------------------------------------------------------------- status */

    public function status(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);

        $demo = [];
        $real = [];
        foreach (self::ENTITIES as $type => [$table, $soft]) {
            $ids = DB::table('demo_records')->where('record_type', $type)->pluck('record_id');
            $base = DB::table($table);
            if ($soft) {
                $base->whereNull('deleted_at');
            }
            $total = (clone $base)->count();
            $demoCount = $ids->isEmpty() ? 0 : (clone $base)->whereIn('id', $ids)->count();
            $demo[$type] = $demoCount;
            $real[$type] = max(0, $total - $demoCount);
        }

        return response()->json(['demo' => $demo, 'real' => $real], 200);
    }

    /* ------------------------------------------------------------ generate */

    public function generate(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);

        $request->validate([
            'products' => 'nullable|integer|min:0|max:300',
            'clients' => 'nullable|integer|min:0|max:300',
            'providers' => 'nullable|integer|min:0|max:300',
            'sales' => 'nullable|integer|min:0|max:300',
            'purchases' => 'nullable|integer|min:0|max:300',
            'quotations' => 'nullable|integer|min:0|max:300',
            'expenses' => 'nullable|integer|min:0|max:300',
        ]);

        $want = [];
        foreach (array_keys(self::ENTITIES) as $type) {
            $want[$type] = (int) $request->input($type, 0);
        }
        if (array_sum($want) === 0) {
            return response()->json(['message' => 'Nothing to generate'], 422);
        }

        // Documents can only reference demo entities — top the pools up when
        // documents were requested without (enough) matching entities.
        if (($want['sales'] + $want['quotations'] + $want['purchases']) > 0) {
            $want['products'] = max($want['products'], $this->missingTo('products', 8));
        }
        if (($want['sales'] + $want['quotations']) > 0) {
            $want['clients'] = max($want['clients'], $this->missingTo('clients', 5));
        }
        if ($want['purchases'] > 0) {
            $want['providers'] = max($want['providers'], $this->missingTo('providers', 5));
        }

        $userId = $request->user('api')->id;
        $created = [];

        try {
            DB::transaction(function () use ($want, $userId, &$created) {
                $warehouseIds = DB::table('warehouses')->whereNull('deleted_at')->pluck('id')->all();
                if (! $warehouseIds) {
                    $wid = DB::table('warehouses')->insertGetId([
                        'name' => 'Demo Warehouse', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->tag('warehouses', [$wid]);
                    $warehouseIds = [$wid];
                }
                $paymentMethodId = DB::table('payment_methods')->whereNull('deleted_at')->value('id');

                $created['products'] = $this->makeProducts($want['products'], $warehouseIds);
                $created['clients'] = $this->makeClients($want['clients']);
                $created['providers'] = $this->makeProviders($want['providers']);

                $productIds = $this->demoIds('products');
                $clientIds = $this->demoIds('clients');
                $providerIds = $this->demoIds('providers');

                $created['sales'] = $this->makeSales($want['sales'], $userId, $warehouseIds, $clientIds, $productIds, $paymentMethodId);
                $created['purchases'] = $this->makePurchases($want['purchases'], $userId, $warehouseIds, $providerIds, $productIds, $paymentMethodId);
                $created['quotations'] = $this->makeQuotations($want['quotations'], $userId, $warehouseIds, $clientIds, $productIds);
                $created['expenses'] = $this->makeExpenses($want['expenses'], $userId, $warehouseIds);
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Generation failed: '.$e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'created' => $created], 200);
    }

    /* --------------------------------------------------------------- reset */

    public function reset(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);

        $deleted = [];
        try {
            DB::transaction(function () use (&$deleted) {
                // Documents first (children before parents), entities last.
                $saleIds = $this->demoIds('sales');
                DB::table('payment_sales')->whereIn('sale_id', $saleIds)->delete();
                DB::table('sale_details')->whereIn('sale_id', $saleIds)->delete();
                $deleted['sales'] = DB::table('sales')->whereIn('id', $saleIds)->delete();

                $purchaseIds = $this->demoIds('purchases');
                DB::table('payment_purchases')->whereIn('purchase_id', $purchaseIds)->delete();
                DB::table('purchase_details')->whereIn('purchase_id', $purchaseIds)->delete();
                $deleted['purchases'] = DB::table('purchases')->whereIn('id', $purchaseIds)->delete();

                $quotationIds = $this->demoIds('quotations');
                DB::table('quotation_details')->whereIn('quotation_id', $quotationIds)->delete();
                $deleted['quotations'] = DB::table('quotations')->whereIn('id', $quotationIds)->delete();

                $deleted['expenses'] = DB::table('expenses')->whereIn('id', $this->demoIds('expenses'))->delete();

                $productIds = $this->demoIds('products');
                DB::table('product_warehouse')->whereIn('product_id', $productIds)->delete();
                $deleted['products'] = DB::table('products')->whereIn('id', $productIds)->delete();

                $deleted['clients'] = DB::table('clients')->whereIn('id', $this->demoIds('clients'))->delete();
                $deleted['providers'] = DB::table('providers')->whereIn('id', $this->demoIds('providers'))->delete();

                // Scaffolding created on demand (category, expense category,
                // unit, warehouse) goes last — documents referenced it.
                DB::table('categories')->whereIn('id', $this->demoIds('categories'))->delete();
                DB::table('expense_categories')->whereIn('id', $this->demoIds('expense_categories'))->delete();
                DB::table('units')->whereIn('id', $this->demoIds('units'))->delete();
                DB::table('warehouses')->whereIn('id', $this->demoIds('warehouses'))->delete();

                DB::table('demo_records')->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'Reset failed: some demo records are referenced by real data (e.g. a real sale using a demo product). Remove those references first.',
            ], 409);
        }

        return response()->json(['success' => true, 'deleted' => $deleted], 200);
    }

    /* ------------------------------------------------------------- helpers */

    private function demoIds(string $type): array
    {
        return DB::table('demo_records')->where('record_type', $type)->pluck('record_id')->all();
    }

    /** How many entities to create so the demo pool reaches $min. */
    private function missingTo(string $type, int $min): int
    {
        return max(0, $min - count($this->demoIds($type)));
    }

    private function tag(string $type, array $ids): void
    {
        $now = now();
        $rows = array_map(fn ($id) => [
            'record_type' => $type, 'record_id' => $id,
            'created_at' => $now, 'updated_at' => $now,
        ], $ids);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('demo_records')->insert($chunk);
        }
    }

    private function randomDate(): Carbon
    {
        return Carbon::now()->subDays(random_int(0, 89))->setTime(random_int(8, 20), random_int(0, 59), random_int(0, 59));
    }

    /** Reusable demo scaffolding row, created once and tagged. */
    private function scaffold(string $type, string $table, array $attributes, array $values = []): int
    {
        $ids = $this->demoIds($type);
        if ($ids) {
            $existing = DB::table($table)->whereIn('id', $ids)->whereNull('deleted_at')->value('id');
            if ($existing) {
                return $existing;
            }
        }
        $id = DB::table($table)->insertGetId(
            $attributes + $values + ['created_at' => now(), 'updated_at' => now()]
        );
        $this->tag($type, [$id]);

        return $id;
    }

    private function makeProducts(int $count, array $warehouseIds): int
    {
        if ($count <= 0) {
            return 0;
        }
        $categoryId = $this->scaffold('categories', 'categories', ['code' => 'DEMO', 'name' => 'Demo Category']);
        $unitId = DB::table('units')->whereNull('deleted_at')->value('id')
            ?? $this->scaffold('units', 'units', ['name' => 'Piece', 'ShortName' => 'pc'], ['base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1]);

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $cost = random_int(5, 400) + 0.99;
            $price = round($cost * (1 + random_int(20, 60) / 100), 2);
            $id = DB::table('products')->insertGetId([
                'type' => 'is_single',
                'code' => 'DEMO'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                'Type_barcode' => 'CODE128',
                'name' => self::PRODUCT_NAMES[$i % count(self::PRODUCT_NAMES)].' '.chr(65 + intdiv($i, count(self::PRODUCT_NAMES))),
                'cost' => $cost,
                'price' => $price,
                'wholesale_price' => round($price * 0.9, 2),
                'min_price' => round($cost * 1.05, 2),
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'unit_sale_id' => $unitId,
                'unit_purchase_id' => $unitId,
                'tax_method' => '1',
                'TaxNet' => 0,
                'stock_alert' => 10,
                'is_variant' => 0,
                'is_imei' => 0,
                'is_active' => 1,
                // Same default as ProductsController@store when nothing is
                // uploaded; the UIs resolve it under /images/products/. No
                // product_images row on purpose — the gallery only ever holds
                // real uploads (see ProductsController duplicate logic).
                'image' => 'no-image.png',
                'note' => 'Demo data',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $ids[] = $id;
            foreach ($warehouseIds as $wid) {
                DB::table('product_warehouse')->insert([
                    'product_id' => $id, 'warehouse_id' => $wid,
                    'qte' => random_int(30, 200), 'manage_stock' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $this->tag('products', $ids);

        return count($ids);
    }

    private function makeClients(int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        $code = (int) (DB::table('clients')->max('code') ?? 0);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $first = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $ids[] = DB::table('clients')->insertGetId([
                'code' => ++$code,
                'name' => "$first $last",
                'email' => strtolower("$first.$last.demo".random_int(100, 999).'@example.com'),
                'phone' => '06'.random_int(10000000, 99999999),
                'country' => 'Demoland',
                'city' => 'Demo City',
                'adresse' => random_int(1, 200).' Demo Street',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->tag('clients', $ids);

        return count($ids);
    }

    private function makeProviders(int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        $code = (int) (DB::table('providers')->max('code') ?? 0);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $name = self::COMPANY_WORDS[array_rand(self::COMPANY_WORDS)].' '.self::COMPANY_TYPES[array_rand(self::COMPANY_TYPES)];
            $ids[] = DB::table('providers')->insertGetId([
                'code' => ++$code,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'.demo'.random_int(100, 999).'@example.com',
                'phone' => '05'.random_int(10000000, 99999999),
                'country' => 'Demoland',
                'city' => 'Demo City',
                'adresse' => random_int(1, 200).' Industrial Zone',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->tag('providers', $ids);

        return count($ids);
    }

    /** Random line items over the demo products; returns [details, grandTotal]. */
    private function pickLines(array $productIds, string $priceColumn): array
    {
        $lines = [];
        $total = 0.0;
        $picked = (array) array_rand(array_flip($productIds), min(random_int(1, 4), count($productIds)));
        $prices = DB::table('products')->whereIn('id', $picked)->pluck($priceColumn, 'id');
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
            ->whereNull('deleted_at')
            ->update(['qte' => DB::raw('GREATEST(0, qte + ('.$delta.'))')]);
    }

    private function makeSales(int $count, int $userId, array $warehouseIds, array $clientIds, array $productIds, ?int $paymentMethodId): int
    {
        if ($count <= 0 || ! $clientIds || ! $productIds) {
            return 0;
        }
        $seq = count($this->demoIds('sales'));
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $date = $this->randomDate();
            $warehouseId = $warehouseIds[array_rand($warehouseIds)];
            [$lines, $grandTotal] = $this->pickLines($productIds, 'price');

            // paid / partial / unpaid weighted towards paid, like a real shop.
            $roll = random_int(1, 10);
            $paid = $roll <= 6 ? $grandTotal : ($roll <= 8 ? round($grandTotal * random_int(20, 80) / 100, 2) : 0.0);
            $paymentStatut = $paid >= $grandTotal ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

            $ref = 'DEMO-SL-'.str_pad((string) (++$seq), 4, '0', STR_PAD_LEFT);
            $saleId = DB::table('sales')->insertGetId([
                'user_id' => $userId,
                'date' => $date->toDateString(),
                'time' => $date->format('H:i:s'),
                'Ref' => $ref,
                'client_id' => $clientIds[array_rand($clientIds)],
                'warehouse_id' => $warehouseId,
                'statut' => random_int(1, 10) <= 8 ? 'completed' : 'pending',
                'payment_statut' => $paymentStatut,
                'paid_amount' => $paid,
                'GrandTotal' => $grandTotal,
                'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'notes' => 'Demo data',
                'created_at' => $date, 'updated_at' => $date,
            ]);
            $ids[] = $saleId;

            foreach ($lines as $line) {
                DB::table('sale_details')->insert([
                    'date' => $date->toDateString(),
                    'sale_id' => $saleId,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                    'total' => $line['total'],
                    'TaxNet' => 0, 'discount' => 0,
                    'discount_method' => '2', 'tax_method' => '1',
                    'created_at' => $date, 'updated_at' => $date,
                ]);
                $this->adjustStock($line['product_id'], $warehouseId, -$line['quantity']);
            }

            if ($paid > 0) {
                DB::table('payment_sales')->insert([
                    'user_id' => $userId,
                    'date' => $date->toDateString(),
                    'Ref' => 'DEMO-INV-SL-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                    'sale_id' => $saleId,
                    'montant' => $paid,
                    'change' => 0,
                    'payment_method_id' => $paymentMethodId,
                    'notes' => 'Demo data',
                    'created_at' => $date, 'updated_at' => $date,
                ]);
            }
        }
        $this->tag('sales', $ids);

        return count($ids);
    }

    private function makePurchases(int $count, int $userId, array $warehouseIds, array $providerIds, array $productIds, ?int $paymentMethodId): int
    {
        if ($count <= 0 || ! $providerIds || ! $productIds) {
            return 0;
        }
        $seq = count($this->demoIds('purchases'));
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $date = $this->randomDate();
            $warehouseId = $warehouseIds[array_rand($warehouseIds)];
            [$lines, $grandTotal] = $this->pickLines($productIds, 'cost');

            $received = random_int(1, 10) <= 8;
            $roll = random_int(1, 10);
            $paid = $roll <= 6 ? $grandTotal : ($roll <= 8 ? round($grandTotal * random_int(20, 80) / 100, 2) : 0.0);
            $paymentStatut = $paid >= $grandTotal ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

            $purchaseId = DB::table('purchases')->insertGetId([
                'user_id' => $userId,
                'date' => $date->toDateString(),
                'time' => $date->format('H:i:s'),
                'Ref' => 'DEMO-PR-'.str_pad((string) (++$seq), 4, '0', STR_PAD_LEFT),
                'provider_id' => $providerIds[array_rand($providerIds)],
                'warehouse_id' => $warehouseId,
                'statut' => $received ? 'received' : 'ordered',
                'payment_statut' => $paymentStatut,
                'paid_amount' => $paid,
                'GrandTotal' => $grandTotal,
                'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'notes' => 'Demo data',
                'created_at' => $date, 'updated_at' => $date,
            ]);
            $ids[] = $purchaseId;

            foreach ($lines as $line) {
                DB::table('purchase_details')->insert([
                    'purchase_id' => $purchaseId,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'cost' => $line['price'],
                    'total' => $line['total'],
                    'TaxNet' => 0, 'discount' => 0,
                    'discount_method' => '2', 'tax_method' => '1',
                    'created_at' => $date, 'updated_at' => $date,
                ]);
                // Only received purchases touch stock, like the real flow.
                if ($received) {
                    $this->adjustStock($line['product_id'], $warehouseId, $line['quantity']);
                }
            }

            if ($paid > 0) {
                DB::table('payment_purchases')->insert([
                    'user_id' => $userId,
                    'date' => $date->toDateString(),
                    'Ref' => 'DEMO-INV-PR-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                    'purchase_id' => $purchaseId,
                    'montant' => $paid,
                    'change' => 0,
                    'payment_method_id' => $paymentMethodId,
                    'notes' => 'Demo data',
                    'created_at' => $date, 'updated_at' => $date,
                ]);
            }
        }
        $this->tag('purchases', $ids);

        return count($ids);
    }

    private function makeQuotations(int $count, int $userId, array $warehouseIds, array $clientIds, array $productIds): int
    {
        if ($count <= 0 || ! $clientIds || ! $productIds) {
            return 0;
        }
        $seq = count($this->demoIds('quotations'));
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $date = $this->randomDate();
            [$lines, $grandTotal] = $this->pickLines($productIds, 'price');

            $quotationId = DB::table('quotations')->insertGetId([
                'user_id' => $userId,
                'date' => $date->toDateString(),
                'Ref' => 'DEMO-QT-'.str_pad((string) (++$seq), 4, '0', STR_PAD_LEFT),
                'client_id' => $clientIds[array_rand($clientIds)],
                'warehouse_id' => $warehouseIds[array_rand($warehouseIds)],
                'statut' => random_int(1, 10) <= 6 ? 'sent' : 'pending',
                'GrandTotal' => $grandTotal,
                'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'notes' => 'Demo data',
                'created_at' => $date, 'updated_at' => $date,
            ]);
            $ids[] = $quotationId;

            foreach ($lines as $line) {
                DB::table('quotation_details')->insert([
                    'quotation_id' => $quotationId,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                    'total' => $line['total'],
                    'TaxNet' => 0, 'discount' => 0,
                    'discount_method' => '2', 'tax_method' => '1',
                    'created_at' => $date, 'updated_at' => $date,
                ]);
            }
        }
        $this->tag('quotations', $ids);

        return count($ids);
    }

    private function makeExpenses(int $count, int $userId, array $warehouseIds): int
    {
        if ($count <= 0) {
            return 0;
        }
        $categoryId = $this->scaffold('expense_categories', 'expense_categories', [
            'name' => 'Demo Expenses', 'user_id' => $userId,
        ], ['description' => 'Demo data']);

        $seq = count($this->demoIds('expenses'));
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $date = $this->randomDate();
            $ids[] = DB::table('expenses')->insertGetId([
                'date' => $date->toDateString(),
                'Ref' => 'DEMO-EXP-'.str_pad((string) (++$seq), 4, '0', STR_PAD_LEFT),
                'user_id' => $userId,
                'expense_category_id' => $categoryId,
                'warehouse_id' => $warehouseIds[array_rand($warehouseIds)],
                'details' => self::EXPENSE_DETAILS[array_rand(self::EXPENSE_DETAILS)],
                'amount' => random_int(20, 1500) + random_int(0, 99) / 100,
                'created_at' => $date, 'updated_at' => $date,
            ]);
        }
        $this->tag('expenses', $ids);

        return count($ids);
    }
}
