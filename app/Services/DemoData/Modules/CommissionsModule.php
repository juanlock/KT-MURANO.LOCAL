<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\Modules\Concerns\DemoRefs;
use Illuminate\Support\Facades\DB;

/**
 * Sales commissions: company-scoped sales agents (no linked login user --
 * demo agents shouldn't provision real accounts), a program with rules, and,
 * when the company has sales to point at, calculated commissions plus a
 * payout receipt. Real sales are only referenced, never modified.
 */
class CommissionsModule extends DemoModule
{
    use DemoRefs;

    public function key(): string
    {
        return 'commissions';
    }

    public function label(): string
    {
        return 'Commissions';
    }

    public function dataTables(): array
    {
        return ['sales_agents', 'commission_programs', 'sale_commissions'];
    }

    public function requiredTables(): array
    {
        return ['sales_agents', 'commission_programs', 'commission_rules', 'sale_commissions', 'commission_receipts'];
    }

    public function generate(DemoContext $ctx): array
    {
        $agents = [];
        foreach (range(1, 3) as $n) {
            [$first, $last] = $ctx->personName();
            $agents[] = $ctx->insert('sales_agents', [
                'user_id' => null,
                'code' => 'SA-'.str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT),
                'name' => "$first $last",
                'email' => strtolower("$first.$last").random_int(10, 99).'@example.com',
                'phone' => '+1 555 '.random_int(100, 999).' '.random_int(1000, 9999),
                'is_active' => $n < 3 ? 1 : 0,
                'notes' => $n < 3 ? 'Field sales, retail accounts' : 'On leave',
            ]);
        }

        $programId = $ctx->insert('commission_programs', [
            'name' => 'Standard Sales Commission '.now()->year,
            'description' => '5% on every sale, 7.5% for the senior agent above $1,000.',
            'is_active' => 1,
            'valid_from' => now()->startOfYear()->toDateString(),
            'valid_to' => now()->endOfYear()->toDateString(),
        ]);
        $ctx->insert('commission_programs', [
            'name' => 'Holiday Bonus Program',
            'description' => 'Flat bonus per sale during the holiday season.',
            'is_active' => 0,
            'valid_from' => now()->setDate(now()->year, 11, 15)->toDateString(),
            'valid_to' => now()->setDate(now()->year, 12, 31)->toDateString(),
        ]);

        $standardRule = $ctx->insert('commission_rules', [
            'commission_program_id' => $programId, 'name' => 'Base 5% on sale total',
            'type' => 'percentage', 'source' => 'sale_total', 'value' => 5,
            'applies_to' => 'all_agents', 'sales_agent_id' => null, 'priority' => 0, 'is_active' => 1,
        ]);
        $seniorRule = $ctx->insert('commission_rules', [
            'commission_program_id' => $programId, 'name' => 'Senior agent 7.5% over $1,000',
            'type' => 'percentage', 'source' => 'paid_amount', 'value' => 7.5,
            'min_threshold' => 1000, 'max_cap' => 500,
            'applies_to' => 'specific_agent', 'sales_agent_id' => $agents[0], 'priority' => 10, 'is_active' => 1,
        ]);
        $ctx->insert('commission_rules', [
            'commission_program_id' => $programId, 'name' => 'Flat $10 per sale',
            'type' => 'fixed', 'source' => 'sale_total', 'value' => 10,
            'applies_to' => 'all_agents', 'sales_agent_id' => null, 'priority' => 1, 'is_active' => 0,
        ]);

        // Commissions need sales to point at (sale_id is a required FK).
        $commissions = 0;
        $receipts = 0;
        $sales = DB::table('sales')->whereIn('id', $this->saleIds($ctx, 6))->get(['id', 'GrandTotal', 'date']);
        $plan = [];
        foreach ($sales->values() as $i => $sale) {
            $agentIdx = $i % 2; // active agents only
            $ruleId = $agentIdx === 0 && (float) $sale->GrandTotal >= 1000 ? $seniorRule : $standardRule;
            $rate = $ruleId === $seniorRule ? 0.075 : 0.05;
            $amount = round((float) $sale->GrandTotal * $rate, 4);
            if ($ruleId === $seniorRule) {
                $amount = min($amount, 500);
            }
            $plan[] = [
                'sale' => $sale, 'agent' => $agents[$agentIdx], 'rule' => $ruleId,
                'amount' => $amount, 'status' => ['paid', 'approved', 'pending'][$i % 3],
            ];
        }

        // Receipts first (parents), then the commissions that point at them.
        $receiptFor = [];
        foreach (collect($plan)->where('status', 'paid')->groupBy('agent') as $agentId => $rows) {
            $last = DB::table('commission_receipts')->orderByDesc('id')->value('Ref');
            $num = $last ? ((int) preg_replace('/^CR-/', '', $last) + 1) : 1;
            $receiptFor[$agentId] = $ctx->insert('commission_receipts', [
                'sales_agent_id' => $agentId,
                'Ref' => $this->uniqueValue('commission_receipts', 'Ref', 'CR-'.str_pad((string) $num, 6, '0', STR_PAD_LEFT)),
                'amount' => $rows->sum('amount'),
                'paid_at' => now()->subDays(2)->toDateString(),
                'payment_method_id' => DB::table('payment_methods')->where('name', 'Cash')->value('id'),
                'notes' => 'Monthly commission payout',
            ]);
            $receipts++;
        }

        foreach ($plan as $p) {
            $ctx->insert('sale_commissions', [
                'sale_id' => $p['sale']->id,
                'sales_agent_id' => $p['agent'],
                'commission_program_id' => $programId,
                'commission_rule_id' => $p['rule'],
                'base_amount' => (float) $p['sale']->GrandTotal,
                'commission_amount' => $p['amount'],
                'status' => $p['status'],
                'commission_receipt_id' => $p['status'] === 'paid' ? $receiptFor[$p['agent']] : null,
                'calculated_at' => now()->subDays(random_int(1, 20)),
            ]);
            $commissions++;
        }

        return array_filter([
            'Sales agents' => 3,
            'Commission programs' => 2,
            'Commission rules' => 3,
            'Sale commissions' => $commissions,
            'Commission receipts' => $receipts,
        ]);
    }
}
