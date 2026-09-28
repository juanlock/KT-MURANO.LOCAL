<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\Modules\Concerns\DemoRefs;
use Illuminate\Support\Facades\DB;

/**
 * Service / repair jobs: technicians, a checklist template (categories +
 * items), and jobs at different stages with their checklist, labor/part
 * lines and payments. Totals and payment status are computed the way
 * ServiceJobController::recalculateTotals() does.
 *
 * No job is 'delivered' and every part line has stock_deducted = 0 -- delivery
 * is what deducts stock, which demo data must not do. Payments carry no
 * account_id, so no account balance moves. None of these tables has
 * company_id; the client/warehouse tie a job to the company.
 */
class ServiceModule extends DemoModule
{
    use DemoRefs;

    public function key(): string
    {
        return 'service';
    }

    public function label(): string
    {
        return 'Service Jobs';
    }

    public function dataTables(): array
    {
        return ['service_jobs', 'service_technicians'];
    }

    public function requiredTables(): array
    {
        return ['service_jobs', 'service_technicians', 'service_checklist_categories', 'service_checklist_items',
            'service_job_checklist_items', 'service_job_items', 'service_job_payments'];
    }

    public function generate(DemoContext $ctx): array
    {
        $clients = $this->clientIds($ctx, 6);
        $parts = DB::table('products')->whereIn('id', $this->productIds($ctx, 6, 'is_single'))
            ->get(['id', 'name', 'price'])->values();

        // Technicians
        $techs = [];
        foreach (range(1, 3) as $n) {
            [$first, $last] = $ctx->personName();
            $techs[] = $ctx->insert('service_technicians', [
                'name' => "$first $last",
                'phone' => '+1 555 '.random_int(100, 999).' '.random_int(1000, 9999),
                'email' => strtolower("$first.$last").random_int(10, 99).'@example.com',
                'notes' => ['Senior technician -- phones & tablets', 'Laptops and desktops', 'Apprentice'][$n - 1],
                'is_active' => 1,
            ]);
        }

        // Checklist template (category names are globally unique)
        $template = [
            'Intake Inspection' => ['Check for physical damage', 'Record accessories received', 'Verify device powers on'],
            'Quality Control' => ['Run full diagnostics', 'Test charging and battery', 'Clean device before return'],
        ];
        $catIds = $itemIds = [];
        $checkItems = 0;
        foreach ($template as $cat => $items) {
            $catName = $this->uniqueValue('service_checklist_categories', 'name', $cat);
            $catIds[$cat] = $ctx->insert('service_checklist_categories', [
                'name' => $catName, 'description' => "$cat steps", 'is_active' => 1,
            ]);
            foreach ($items as $sort => $item) {
                $itemIds[$cat][$item] = $ctx->insert('service_checklist_items', [
                    'category_id' => $catIds[$cat], 'name' => $item, 'sort_order' => $sort, 'is_active' => 1,
                ]);
                $checkItems++;
            }
        }

        $jobs = [
            // item, brand, model, issue, status, days ago, labor, part qty, paid ratio
            ['Smartphone', 'Samsung', 'Galaxy S22', 'Cracked screen after a drop', 'pending', 1, 0, 0, 0],
            ['Laptop', 'Dell', 'XPS 13', 'Does not power on', 'diagnostic', 3, 35, 0, 0],
            ['Tablet', 'Apple', 'iPad Air', 'Battery drains quickly', 'quoted', 5, 45, 1, 0],
            ['Smartphone', 'Apple', 'iPhone 13', 'Charging port loose', 'in_progress', 8, 40, 1, 0.5],
            ['Laptop', 'Lenovo', 'ThinkPad T14', 'Keyboard keys not responding', 'ready', 12, 60, 1, 1],
            ['Game Console', 'Sony', 'PlayStation 5', 'Overheating and fan noise', 'completed', 20, 55, 0, 1],
        ];

        $ref = $this->nextRef('service_jobs', 'SJ');
        $payRef = $this->nextRef('service_job_payments', 'SJP');
        $cashMethod = DB::table('payment_methods')->where('name', 'Cash')->value('id');
        $jobCount = $lineCount = $jobChecks = $payCount = 0;

        foreach ($jobs as $i => [$item, $brand, $model, $issue, $status, $ago, $labor, $partQty, $paidRatio]) {
            $received = now()->subDays($ago)->setTime(random_int(9, 17), random_int(0, 59));
            $diagnosticFee = $status === 'pending' ? 0 : 15;

            // Lines first (in memory) so the job carries its real totals.
            $lines = [];
            if ($labor > 0) {
                $lines[] = ['type' => 'labor', 'product_id' => null, 'description' => 'Repair labor', 'quantity' => 1, 'unit_price' => $labor];
            }
            if ($partQty > 0 && count($parts)) {
                $part = $parts[$i % count($parts)];
                $lines[] = ['type' => 'part', 'product_id' => $part->id, 'description' => $part->name,
                    'quantity' => $partQty, 'unit_price' => round((float) $part->price ?: 30, 2)];
            }
            $itemsTotal = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines));
            $total = round($itemsTotal + $diagnosticFee, 2);
            $paid = round($total * $paidRatio, 2);
            $payStatus = $paid <= 0 ? 'unpaid' : ($total - $paid > 0.0001 ? 'partial' : 'paid');
            $started = in_array($status, ['in_progress', 'ready', 'completed'], true) ? $received->copy()->addDay() : null;
            $done = in_array($status, ['ready', 'completed'], true) ? $received->copy()->addDays(3) : null;

            $jobId = $ctx->insert('service_jobs', [
                'Ref' => 'SJ_'.($ref++),
                'client_id' => $clients[$i % count($clients)],
                'technician_id' => $status === 'pending' ? null : $techs[$i % count($techs)],
                'warehouse_id' => $ctx->warehouseIds[0],
                'service_item' => $item,
                'device_brand' => $brand,
                'device_model' => $model,
                'device_serial' => strtoupper(substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 10)),
                'device_color' => $ctx->pick(['Black', 'Silver', 'White', 'Blue']),
                'accessories' => json_encode($item === 'Laptop' ? ['Charger', 'Bag'] : ['Charger']),
                'condition_on_arrival' => 'Minor scratches on the casing.',
                'reported_issue' => $issue,
                'diagnosis' => $status === 'pending' ? null : 'Confirmed: '.strtolower($issue).'.',
                'diagnostic_fee' => $diagnosticFee,
                'quote_amount' => $status === 'pending' ? 0 : $total,
                'quote_valid_until' => $status === 'pending' ? null : $received->copy()->addDays(14)->toDateString(),
                'quote_approved_at' => in_array($status, ['in_progress', 'ready', 'completed'], true) ? $received->copy()->addHours(20) : null,
                'total_amount' => $total,
                'paid_amount' => $paid,
                'payment_status' => $payStatus,
                'warranty_days' => 30,
                'warranty_expires_at' => $status === 'completed' ? $done->copy()->addDays(30)->toDateString() : null,
                'job_type' => $ctx->pick(['Repair', 'Repair', 'Diagnostic']),
                'status' => $status,
                'scheduled_date' => $received,
                'scheduled_end_date' => $received->copy()->addDays(4),
                'started_at' => $started,
                'completed_at' => $done,
                'created_at' => $received,
                'updated_at' => $received,
            ]);
            $jobCount++;

            foreach ($lines as $l) {
                $ctx->insert('service_job_items', $l + [
                    'service_job_id' => $jobId,
                    'product_variant_id' => null,
                    'warehouse_id' => $l['type'] === 'part' ? $ctx->warehouseIds[0] : null,
                    'cost' => 0,
                    'discount' => 0,
                    'discount_method' => '1',
                    'tax_rate' => 0,
                    'tax_method' => '1',
                    'total' => round($l['quantity'] * $l['unit_price'], 2),
                    'stock_deducted' => 0,
                ]);
                $lineCount++;
            }

            // Checklist: intake always; QC too once work has started.
            $completedCats = $started ? ['Intake Inspection', 'Quality Control'] : ['Intake Inspection'];
            foreach ($template as $cat => $items) {
                foreach ($items as $name) {
                    $isDone = in_array($cat, $completedCats, true) && ($cat === 'Intake Inspection' || $done);
                    $ctx->insert('service_job_checklist_items', [
                        'service_job_id' => $jobId,
                        'category_id' => $catIds[$cat],
                        'item_id' => $itemIds[$cat][$name],
                        'category_name' => $cat,
                        'item_name' => $name,
                        'is_completed' => $isDone ? 1 : 0,
                        'completed_at' => $isDone ? ($done ?? $received) : null,
                    ]);
                    $jobChecks++;
                }
            }

            if ($paid > 0) {
                $split = $paidRatio < 1 ? [[$paid, 'deposit']] : [[round($paid * 0.4, 2), 'deposit'], [round($paid - round($paid * 0.4, 2), 2), 'payment']];
                foreach ($split as $k => [$amount, $kind]) {
                    $ctx->insert('service_job_payments', [
                        'Ref' => 'SJP_'.($payRef++),
                        'service_job_id' => $jobId,
                        'user_id' => $ctx->userId,
                        'payment_method_id' => $cashMethod,
                        'account_id' => null,
                        'date' => ($k === 0 ? $received : ($done ?? $received))->toDateString(),
                        'montant' => $amount,
                        'change' => 0,
                        'payment_kind' => $kind,
                    ]);
                    $payCount++;
                }
            }
        }

        return [
            'Technicians' => count($techs),
            'Checklist categories' => count($catIds),
            'Checklist items' => $checkItems,
            'Service jobs' => $jobCount,
            'Job lines' => $lineCount,
            'Job checklist items' => $jobChecks,
            'Job payments' => $payCount,
        ];
    }
}
