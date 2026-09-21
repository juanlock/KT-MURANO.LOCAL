<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\ServiceJob;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's first screen: how the account stands, what needs attention,
 * and where the spend is going — the portal dashboard, read from the
 * customer's side of the counter.
 */
class PortalDashboardController extends Controller
{
    private const TREND_MONTHS = 12;

    private const QUOTE_OPEN = ['pending', 'sent', 'requested', 'draft'];
    private const JOB_CLOSED = ['completed', 'delivered', 'cancelled', 'declined'];
    private const CONTRACT_ACTIVE = ['active', 'signed'];

    /**
     * GET /api/portal/dashboard
     */
    public function index(Request $request)
    {
        $portalClient = Auth::guard('portal')->user();
        $this->assertPortalActive($portalClient);

        $clientId = $portalClient->client_id;
        $client = Client::query()->whereKey($clientId)->first(['id', 'name', 'email', 'phone', 'opening_balance']);

        $sales = fn () => Sale::query()->whereNull('deleted_at')->where('client_id', $clientId)->where('statut', 'completed');

        // ── Lifetime ───────────────────────────────────────────────────
        $totalInvoices = (clone $sales())->count();
        $totalAmount = (float) (clone $sales())->sum('GrandTotal');
        $totalPaid = (float) (clone $sales())->sum('paid_amount');
        $salesDue = round($totalAmount - $totalPaid, 4);
        $openingBalance = (float) ($client->opening_balance ?? 0);
        $totalDue = round($salesDue + $openingBalance, 4);
        $unpaidCount = (clone $sales())->whereRaw('GrandTotal - paid_amount > 0.0001')->count();

        // ── This month against last month ─────────────────────────────
        $month = $this->periodSummary($sales, now()->startOfMonth(), now()->endOfMonth());
        $previous = $this->periodSummary($sales, now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth());

        // ── Trend: invoiced and paid, month by month ───────────────────
        $byMonth = $this->byMonth($sales, $clientId);

        // ── Payment mix (lifetime) ─────────────────────────────────────
        $byMethod = $this->byMethod($clientId);

        // ── What the customer buys most ────────────────────────────────
        $topProducts = $this->topProducts($clientId);

        // ── Right now ──────────────────────────────────────────────────
        $live = $this->live($clientId, $unpaidCount);

        // ── Latest activity ────────────────────────────────────────────
        $recentInvoices = (clone $sales())
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'Ref', 'date', 'GrandTotal', 'paid_amount', 'payment_statut'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'Ref' => $s->Ref,
                'date' => $s->date,
                'GrandTotal' => (float) $s->GrandTotal,
                'paid_amount' => (float) $s->paid_amount,
                'due' => round((float) $s->GrandTotal - (float) $s->paid_amount, 4),
                'payment_status' => $s->payment_statut,
            ])->values();

        $lastPayment = DB::table('payment_sales')
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->whereNull('payment_sales.deleted_at')
            ->whereNull('sales.deleted_at')
            ->where('sales.client_id', $clientId)
            ->orderByDesc('payment_sales.date')->orderByDesc('payment_sales.id')
            ->first(['payment_sales.date', 'payment_sales.montant']);

        return response()->json([
            'client' => ['name' => $client->name ?? '', 'email' => $client->email ?? '', 'phone' => $client->phone ?? ''],

            // Lifetime figures (kept flat for compatibility with older builds)
            'total_invoices' => $totalInvoices,
            'total_amount' => $totalAmount,
            'total_paid' => $totalPaid,
            'sales_due' => $salesDue,
            'opening_balance' => $openingBalance,
            'total_due' => $totalDue,
            'unpaid_count' => $unpaidCount,
            'average_invoice' => $totalInvoices > 0 ? round($totalAmount / $totalInvoices, 4) : 0,
            'last_payment' => $lastPayment ? ['date' => $lastPayment->date, 'amount' => (float) $lastPayment->montant] : null,

            'month' => $month + [
                'change_invoiced' => $this->change($month['invoiced'], $previous['invoiced']),
                'change_paid' => $this->change($month['paid'], $previous['paid']),
                'change_invoices' => $this->change($month['invoices'], $previous['invoices']),
                'label' => now()->format('F Y'),
            ],
            'by_month' => $byMonth,
            'by_method' => $byMethod,
            'top_products' => $topProducts,
            'live' => $live,
            'alerts' => $this->alerts($clientId, $unpaidCount, $salesDue, $live),
            'recent_invoices' => $recentInvoices,
        ]);
    }

    /**
     * GET /api/portal/notifications — the topbar bell.
     */
    public function notifications(Request $request)
    {
        $portalClient = Auth::guard('portal')->user();
        $this->assertPortalActive($portalClient);

        $clientId = $portalClient->client_id;
        $sales = fn () => Sale::query()->whereNull('deleted_at')->where('client_id', $clientId)->where('statut', 'completed');
        $unpaidCount = (clone $sales())->whereRaw('GrandTotal - paid_amount > 0.0001')->count();
        $due = round((float) (clone $sales())->sum('GrandTotal') - (float) (clone $sales())->sum('paid_amount'), 4);
        $live = $this->live($clientId, $unpaidCount);

        $items = [];
        if ($unpaidCount > 0) {
            $items[] = [
                'tone' => 'warning', 'icon' => 'ti-file-invoice',
                'title' => __('portal.n_unpaid_invoices', ['count' => $unpaidCount]),
                'meta' => __('portal.amount_due') . ': ' . number_format($due, 2),
                'url' => '/invoices',
            ];
        }
        if ($live['pending_quotations'] > 0) {
            $items[] = [
                'tone' => 'accent', 'icon' => 'ti-file-description',
                'title' => __('portal.n_quotations_awaiting', ['count' => $live['pending_quotations']]),
                'meta' => __('portal.quotations'),
                'url' => '/quotations',
            ];
        }
        if ($live['upcoming_appointments'] > 0) {
            $items[] = [
                'tone' => 'accent', 'icon' => 'ti-calendar-event',
                'title' => __('portal.n_upcoming_appointments', ['count' => $live['upcoming_appointments']]),
                'meta' => __('portal.appointments'),
                'url' => '/appointments',
            ];
        }

        return response()->json(['items' => $items, 'due_total' => $due]);
    }

    // ------------------------------------------------------------------

    private function periodSummary(callable $sales, Carbon $from, Carbon $to): array
    {
        $q = $sales()->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $count = (clone $q)->count();
        $invoiced = (float) (clone $q)->sum('GrandTotal');
        $paid = (float) (clone $q)->sum('paid_amount');

        return [
            'invoices' => $count,
            'invoiced' => $invoiced,
            'paid' => $paid,
            'due' => round($invoiced - $paid, 4),
            'average' => $count > 0 ? round($invoiced / $count, 4) : 0,
        ];
    }

    private function change(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function byMonth(callable $sales, int $clientId): array
    {
        $start = now()->subMonthsNoOverflow(self::TREND_MONTHS - 1)->startOfMonth();

        $rows = $sales()
            ->where('date', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as ym, SUM(GrandTotal) as gross, SUM(paid_amount) as paid, COUNT(*) as invoices")
            ->groupBy('ym')
            ->pluck('gross', 'ym');

        $paid = $sales()
            ->where('date', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as ym, SUM(paid_amount) as paid")
            ->groupBy('ym')
            ->pluck('paid', 'ym');

        $out = [];
        $cursor = $start->copy();
        for ($i = 0; $i < self::TREND_MONTHS; $i++) {
            $ym = $cursor->format('Y-m');
            $out[] = [
                'label' => $cursor->format('M y'),
                'gross' => (float) ($rows[$ym] ?? 0),
                'paid' => (float) ($paid[$ym] ?? 0),
            ];
            $cursor->addMonthNoOverflow();
        }

        return $out;
    }

    private function byMethod(int $clientId): array
    {
        $rows = DB::table('payment_sales')
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->join('payment_methods', 'payment_sales.payment_method_id', '=', 'payment_methods.id')
            ->whereNull('payment_sales.deleted_at')
            ->whereNull('sales.deleted_at')
            ->where('sales.client_id', $clientId)
            ->groupBy('payment_methods.name')
            ->orderByDesc('total')
            ->limit(6)
            ->get([DB::raw('payment_methods.name as label'), DB::raw('SUM(payment_sales.montant) as total')]);

        $sum = (float) $rows->sum('total');

        return $rows->map(fn ($r) => [
            'label' => $r->label,
            'total' => (float) $r->total,
            'share' => $sum > 0 ? (int) round(((float) $r->total / $sum) * 100) : 0,
        ])->values()->all();
    }

    private function topProducts(int $clientId): array
    {
        if (!Schema::hasTable('sale_details')) {
            return [];
        }

        $rows = DB::table('sale_details')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereNull('sales.deleted_at')
            ->where('sales.client_id', $clientId)
            ->where('sales.statut', 'completed')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('revenue')
            ->limit(6)
            ->get([DB::raw('products.name as name'), DB::raw('SUM(sale_details.quantity) as qty'), DB::raw('SUM(sale_details.total) as revenue')]);

        $top = (float) ($rows->max('revenue') ?: 0);

        return $rows->map(fn ($r) => [
            'name' => $r->name,
            'qty' => (float) $r->qty,
            'revenue' => (float) $r->revenue,
            'share' => $top > 0 ? (int) round(((float) $r->revenue / $top) * 100) : 0,
        ])->values()->all();
    }

    private function live(int $clientId, int $unpaidCount): array
    {
        $pendingQuotations = Quotation::query()->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->whereIn('statut', self::QUOTE_OPEN)
            ->count();

        $upcoming = ServiceJob::query()->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->whereNotNull('scheduled_date')
            ->where('scheduled_date', '>=', now())
            ->whereNotIn('status', self::JOB_CLOSED)
            ->count();

        $contracts = Contract::query()->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->whereIn('status', self::CONTRACT_ACTIVE)
            ->count();

        return [
            'open_invoices' => $unpaidCount,
            'pending_quotations' => $pendingQuotations,
            'upcoming_appointments' => $upcoming,
            'active_contracts' => $contracts,
        ];
    }

    private function alerts(int $clientId, int $unpaidCount, float $due, array $live): array
    {
        $alerts = [];

        if ($unpaidCount > 0 && $due > 0) {
            $alerts[] = [
                'tone' => 'orange', 'icon' => 'alert-triangle',
                'title' => __('portal.n_unpaid_invoices', ['count' => $unpaidCount]),
                'detail' => __('portal.amount_due') . ': ' . number_format($due, 2),
                'url' => '/invoices', 'action' => __('portal.view_invoices'),
            ];
        }

        if ($live['pending_quotations'] > 0) {
            $alerts[] = [
                'tone' => 'blue', 'icon' => 'file-description',
                'title' => __('portal.n_quotations_awaiting', ['count' => $live['pending_quotations']]),
                'detail' => __('portal.quotations_subtitle'),
                'url' => '/quotations', 'action' => __('portal.view'),
            ];
        }

        $next = ServiceJob::query()->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->whereNotNull('scheduled_date')
            ->where('scheduled_date', '>=', now())
            ->whereNotIn('status', self::JOB_CLOSED)
            ->orderBy('scheduled_date')
            ->first(['id', 'Ref', 'service_item', 'scheduled_date']);

        if ($next) {
            $alerts[] = [
                'tone' => 'teal', 'icon' => 'calendar-event',
                'title' => __('portal.next_appointment'),
                'detail' => trim(($next->service_item ?: $next->Ref) . ' · ' . optional($next->scheduled_date)->format('Y-m-d H:i')),
                'url' => '/appointments/' . $next->id, 'action' => __('portal.view'),
            ];
        }

        return $alerts;
    }

    private function assertPortalActive($portalClient): void
    {
        if ((int) $portalClient->status !== 1) {
            abort(403, __('portal.portal_disabled'));
        }
    }
}
