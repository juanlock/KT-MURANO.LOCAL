<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\PaymentSale;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Unit;
use App\Models\Warehouse;

/**
 * Demo data for development/testing: warehouses, catalog, customers/suppliers,
 * and ~60 days of sales/purchases/expenses so the dashboard and every migrated
 * report has something to show.
 *
 * Run:  php artisan db:seed --class=DemoDataSeeder
 *
 * Safe to re-run: demo rows are tagged with the [DEMO] marker / DEMO- codes and
 * the seeder exits early if they already exist. It never touches existing rows.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (Client::where('name', 'like', '[DEMO]%')->exists()) {
            $this->command?->warn('Demo data already present — nothing to do. (Delete [DEMO] rows to reseed.)');
            return;
        }

        DB::transaction(function () {
            $userId = DB::table('users')->min('id') ?: 1;

            /* ---------------------------------------------------- catalog */
            $unit = Unit::firstOrCreate(
                ['ShortName' => 'pc'],
                ['name' => 'Piece', 'base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1]
            );

            $warehouses = collect([
                ['name' => '[DEMO] Main Warehouse', 'city' => 'Casablanca', 'country' => 'Morocco', 'mobile' => '0600000001', 'email' => 'main@demo.test', 'zip' => '20000'],
                ['name' => '[DEMO] Depot 2', 'city' => 'Rabat', 'country' => 'Morocco', 'mobile' => '0600000002', 'email' => 'depot2@demo.test', 'zip' => '10000'],
            ])->map(fn ($w) => Warehouse::create($w));

            $categories = collect(['Electronics', 'Groceries', 'Fashion', 'Home & Garden', 'Sports'])
                ->map(fn ($n, $i) => Category::create(['code' => 'DEMO-C' . ($i + 1), 'name' => "[DEMO] $n"]));

            $brands = collect(['Acme', 'Globex', 'Initech', 'Umbrella'])
                ->map(fn ($n) => Brand::create(['name' => "[DEMO] $n", 'description' => 'Demo brand']));

            $productNames = [
                'Wireless Earbuds Pro', 'Smart Watch S9', 'Bluetooth Speaker', 'USB-C Charger 65W',
                'Organic Coffee 1kg', 'Green Tea Box', 'Olive Oil 1L', 'Honey Jar 500g',
                'Cotton T-Shirt', 'Denim Jacket', 'Running Shoes', 'Leather Belt',
                'LED Desk Lamp', 'Ceramic Vase', 'Garden Hose 20m', 'Tool Set 32pc',
                'Yoga Mat Premium', 'Dumbbell 10kg', 'Tennis Racket', 'Camping Tent 2P',
            ];

            $products = collect($productNames)->map(function ($name, $i) use ($categories, $brands, $unit) {
                $cost = random_int(5, 180);
                return Product::create([
                    'code' => 'DEMO-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    'Type_barcode' => 'CODE128',
                    'name' => "[DEMO] $name",
                    'cost' => $cost,
                    'price' => round($cost * 1.45, 2),
                    'unit_id' => $unit->id,
                    'unit_sale_id' => $unit->id,
                    'unit_purchase_id' => $unit->id,
                    'stock_alert' => 5,
                    'category_id' => $categories[$i % $categories->count()]->id,
                    'brand_id' => $brands[$i % $brands->count()]->id,
                    'is_variant' => 0,
                    'tax_method' => 1,
                    'type' => 'is_single',
                    'is_active' => 1,
                ]);
            });

            foreach ($products as $product) {
                foreach ($warehouses as $wh) {
                    product_warehouse::create([
                        'product_id' => $product->id,
                        'warehouse_id' => $wh->id,
                        'qte' => random_int(10, 120),
                        'manage_stock' => 1,
                    ]);
                }
            }

            /* ----------------------------------------------------- people */
            $clientNames = ['Sarah Miller', 'Omar Khalil', 'Lina Berrada', 'James Chen', 'Fatima Zahra', 'David Osei', 'Aisha Noor', 'Karim Haddad'];
            $clients = collect($clientNames)->map(fn ($n, $i) => Client::create([
                'code' => 90001 + $i,
                'name' => "[DEMO] $n",
                'firstname' => explode(' ', $n)[0],
                'lastname' => explode(' ', $n)[1] ?? '',
                'email' => strtolower(str_replace(' ', '.', $n)) . '@demo.test',
                'phone' => '06' . str_pad((string) (10000000 + $i), 8, '0'),
                'country' => 'Morocco',
                'city' => 'Casablanca',
                'opening_balance' => 0,
                'credit_limit' => 5000,
                'points' => random_int(0, 400),
                'is_royalty_eligible' => 1,
            ]));

            $providers = collect(['TechSource SARL', 'FoodImport Co', 'TextilePro', 'HomeSupply Ltd', 'SportGear Inc'])
                ->map(fn ($n, $i) => Provider::create([
                    'code' => 95001 + $i,
                    'name' => "[DEMO] $n",
                    'email' => strtolower(preg_replace('/[^a-z]/i', '', $n)) . '@demo.test',
                    'phone' => '05' . str_pad((string) (20000000 + $i), 8, '0'),
                    'country' => 'Morocco',
                    'city' => 'Tangier',
                    'opening_balance' => 0,
                    'credit_limit' => 0,
                ]));

            /* ------------------------------------------------ money plumbing */
            $paymentMethodId = PaymentMethod::query()->value('id')
                ?? PaymentMethod::create(['name' => 'Cash', 'status' => 1])->id;
            $accountId = Account::query()->value('id')
                ?? Account::create(['account_num' => 'DEMO-001', 'account_name' => '[DEMO] Main Account', 'initial_balance' => 0, 'balance' => 0])->id;

            /* ------------------------------------------------------ sales */
            $ref = 9000;
            foreach (range(1, 60) as $i) {
                $date = now()->subDays(random_int(0, 59))->format('Y-m-d');
                $wh = $warehouses->random();
                $client = $clients->random();

                $grand = 0.0;
                $lines = [];
                foreach (range(1, random_int(1, 3)) as $_) {
                    $p = $products->random();
                    $qty = random_int(1, 5);
                    $total = round($p->price * $qty, 2);
                    $grand += $total;
                    $lines[] = ['p' => $p, 'qty' => $qty, 'total' => $total];
                }

                [$statut, $payStatut, $paid] = match (true) {
                    $i % 7 === 0 => ['pending', 'unpaid', 0.0],
                    $i % 5 === 0 => ['completed', 'partial', round($grand / 2, 2)],
                    default      => ['completed', 'paid', $grand],
                };

                $sale = Sale::create([
                    'date' => $date,
                    'Ref' => 'SL_' . (++$ref),
                    'is_pos' => 0,
                    'client_id' => $client->id,
                    'warehouse_id' => $wh->id,
                    'user_id' => $userId,
                    'statut' => $statut,
                    'GrandTotal' => round($grand, 2),
                    'TaxNet' => 0,
                    'tax_rate' => 0,
                    'discount' => 0,
                    'shipping' => 0,
                    'paid_amount' => $paid,
                    'payment_statut' => $payStatut,
                ]);

                foreach ($lines as $l) {
                    SaleDetail::create([
                        'date' => $date,
                        'sale_id' => $sale->id,
                        'sale_unit_id' => $unit->id,
                        'product_id' => $l['p']->id,
                        'quantity' => $l['qty'],
                        'price' => $l['p']->price,
                        'total' => $l['total'],
                        'TaxNet' => 0,
                        'discount' => 0,
                        'discount_method' => '2',
                        'tax_method' => '1',
                    ]);
                }

                if ($paid > 0) {
                    PaymentSale::create([
                        'sale_id' => $sale->id,
                        'date' => $date,
                        'Ref' => 'INV/SL_' . $ref,
                        'montant' => $paid,
                        'change' => 0,
                        'payment_method_id' => $paymentMethodId,
                        'account_id' => $accountId,
                        'user_id' => $userId,
                    ]);
                }
            }

            /* -------------------------------------------------- purchases */
            $pref = 9000;
            foreach (range(1, 25) as $i) {
                $date = now()->subDays(random_int(0, 59))->format('Y-m-d');
                $wh = $warehouses->random();

                $grand = 0.0;
                $lines = [];
                foreach (range(1, random_int(1, 4)) as $_) {
                    $p = $products->random();
                    $qty = random_int(5, 20);
                    $total = round($p->cost * $qty, 2);
                    $grand += $total;
                    $lines[] = ['p' => $p, 'qty' => $qty, 'total' => $total];
                }

                [$statut, $payStatut, $paid] = match (true) {
                    $i % 6 === 0 => ['ordered', 'unpaid', 0.0],
                    $i % 4 === 0 => ['received', 'partial', round($grand / 2, 2)],
                    default      => ['received', 'paid', $grand],
                };

                $purchase = Purchase::create([
                    'date' => $date,
                    'Ref' => 'PR_' . (++$pref),
                    'provider_id' => $providers->random()->id,
                    'warehouse_id' => $wh->id,
                    'user_id' => $userId,
                    'statut' => $statut,
                    'GrandTotal' => round($grand, 2),
                    'TaxNet' => 0,
                    'tax_rate' => 0,
                    'discount' => 0,
                    'shipping' => 0,
                    'paid_amount' => $paid,
                    'payment_statut' => $payStatut,
                ]);

                foreach ($lines as $l) {
                    // NOTE: PurchaseDetail has no `date` column (SaleDetail does).
                    PurchaseDetail::create([
                        'purchase_id' => $purchase->id,
                        'purchase_unit_id' => $unit->id,
                        'product_id' => $l['p']->id,
                        'quantity' => $l['qty'],
                        'cost' => $l['p']->cost,
                        'total' => $l['total'],
                        'TaxNet' => 0,
                        'discount' => 0,
                        'discount_method' => '2',
                        'tax_method' => '1',
                    ]);
                }

                if ($paid > 0) {
                    PaymentPurchase::create([
                        'purchase_id' => $purchase->id,
                        'date' => $date,
                        'Ref' => 'INV/PR_' . $pref,
                        'montant' => $paid,
                        'change' => 0,
                        'payment_method_id' => $paymentMethodId,
                        'account_id' => $accountId,
                        'user_id' => $userId,
                    ]);
                }
            }

            /* --------------------------------------------------- expenses */
            $expCat = ExpenseCategory::firstOrCreate(
                ['name' => '[DEMO] Operations'],
                ['user_id' => $userId, 'description' => 'Demo expenses']
            );
            foreach (range(1, 15) as $i) {
                Expense::create([
                    'date' => now()->subDays(random_int(0, 59))->format('Y-m-d'),
                    'Ref' => 'EXP_' . (9000 + $i),
                    'user_id' => $userId,
                    'expense_category_id' => $expCat->id,
                    'warehouse_id' => $warehouses->random()->id,
                    'account_id' => $accountId,
                    'payment_method_id' => $paymentMethodId,
                    'details' => '[DEMO] ' . ['Electricity', 'Rent', 'Internet', 'Fuel', 'Maintenance'][$i % 5],
                    'amount' => random_int(50, 900),
                ]);
            }
        });

        $this->command?->info('Demo data created: 2 warehouses, 20 products, 8 customers, 5 suppliers, 60 sales, 25 purchases, 15 expenses.');
    }
}
