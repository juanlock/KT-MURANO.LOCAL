<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class CashRegisterController extends BaseController
{
    // Multicompany: company-aware warehouse scope -- see the trait.
    use \App\Http\Controllers\Concerns\ScopesReportsToCompany;

    public function openRegister(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'opening_balance' => 'required|numeric',
            'notes' => 'nullable|string',
            'pin' => 'nullable|string|max:20',
        ]);

        $user_id = Auth::user()->id;

        if ($fail = $this->pinFailure($data['warehouse_id'], $data['pin'] ?? null)) {
            return $fail;
        }

        $existing = CashRegister::where('user_id', $user_id)
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('status', 'open')
            ->first();
        if ($existing) {
            return response()->json(['success' => false, 'message' => 'Register already open'], 409);
        }

        $register = CashRegister::create([
            'user_id' => $user_id,
            'warehouse_id' => $data['warehouse_id'],
            'opening_balance' => $data['opening_balance'],
            'status' => 'open',
            'opened_at' => Carbon::now(),
            'notes' => $data['notes'] ?? null,
        ]);

        // "Register opened" notice to the company's admins, after the response
        // (the cashier never waits on SMTP; a mail failure never blocks the POS).
        $registerId = $register->id;
        dispatch(static function () use ($registerId) {
            if ($r = CashRegister::find($registerId)) {
                app(self::class)->emailOpened($r);
            }
        })->afterResponse();

        return response()->json(['success' => true, 'register' => $register]);
    }

    public function getCurrentRegister(Request $request, $userId)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $warehouseId = $request->query('warehouse_id');
        $query = CashRegister::with('user', 'warehouse')
            ->where('user_id', $userId)
            ->where('status', 'open');
        // If a specific warehouse is selected, filter; otherwise return the latest open register across warehouses
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        $register = $query->orderByDesc('id')->first();

        return response()->json(['success' => true, 'register' => $register]);
    }

    public function cashInOut(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $data = $request->validate([
            'register_id' => 'required|integer|exists:cash_registers,id',
            'type' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $register = CashRegister::findOrFail($data['register_id']);
        if ($register->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Register is closed'], 409);
        }

        if ($data['type'] === 'in') {
            $register->cash_in = ($register->cash_in ?? 0) + $data['amount'];
        } else {
            $register->cash_out = ($register->cash_out ?? 0) + $data['amount'];
        }
        if (! empty($data['notes'])) {
            $register->notes = trim($register->notes."\n".'['.Carbon::now()->toDateTimeString().'] Cash '.$data['type'].': '.number_format($data['amount'], 2));
        }
        $register->save();

        return response()->json(['success' => true, 'register' => $register]);
    }

    public function closeRegister(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $data = $request->validate([
            'register_id' => 'required|integer|exists:cash_registers,id',
            'counted_cash' => 'required|numeric',
            'closing_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'pin' => 'nullable|string|max:20',
        ]);

        $register = CashRegister::findOrFail($data['register_id']);
        $this->assertRegisterAccess($register);
        if ($register->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Register already closed'], 409);
        }
        if ($fail = $this->pinFailure($register->warehouse_id, $data['pin'] ?? null)) {
            return $fail;
        }

        $now = Carbon::now();
        $summary = $this->registerSummary($register, $now);

        // Only cash payments end up in the drawer (Zelle, card... don't).
        $expectedCash = $summary['expected_cash'];
        $counted = (float) $data['counted_cash'];
        $difference = $counted - $expectedCash;

        $register->total_sales = $summary['total_sales'];
        $register->closing_balance = $data['closing_balance'] ?? $counted;
        $register->difference = $difference;
        $register->status = 'closed';
        $register->closed_at = $now;

        if (! empty($data['notes'])) {
            $register->notes = trim(($register->notes ?? '')."\n".$data['notes']);
        }
        $register->save();

        $summary['counted_cash'] = $counted;
        $summary['difference'] = $difference;
        $summary['closed_at'] = $now->format('Y-m-d H:i:s');
        $summary['notes'] = $data['notes'] ?? null;

        // POS Settings -> "Email the admins when a register is closed".
        $email = $this->emailCloseSummary($register, $summary);

        return response()->json([
            'success' => true,
            'register' => $register,
            'summary' => $summary,
            'email' => $email,
        ]);
    }

    // Live figures for an open register (POS "Exit" / close confirmation).
    public function summary(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $register = CashRegister::findOrFail($id);
        $this->assertRegisterAccess($register);

        return response()->json(['success' => true, 'summary' => $this->registerSummary($register, Carbon::now())]);
    }

    /**
     * POS Settings -> "Open register with PIN" (company of the warehouse):
     * the register is opened / closed by the cashier's own PIN (users.pin,
     * unique per company). Opening: the POS PIN pad already switched the
     * session to the PIN's user (PosPinController), which leaves a 5-minute
     * confirmation for that user + location -- consumed here. Closing: the
     * PIN of the signed-in cashier is sent with the close. Returns an error
     * response, or null when fine / not required.
     */
    private function pinFailure($warehouseId, $pin)
    {
        $companyId = Warehouse::where('id', $warehouseId)->value('company_id');
        $settings = \App\Models\Setting::forCompany($companyId);
        if (! ($settings && $settings->pos_register_pin_required)) {
            return null;
        }

        $user = Auth::user();
        if (($pin === null || $pin === '') && (int) \Illuminate\Support\Facades\Cache::get('pos_pin_ok:'.$user->id) === (int) $warehouseId) {
            \Illuminate\Support\Facades\Cache::forget('pos_pin_ok:'.$user->id);

            return null;
        }
        if ($user->pin === null || $user->pin === '') {
            return response()->json(['success' => false, 'code' => 'no_pin', 'message' => 'You don\'t have a PIN yet. Ask an admin to set one on your user (Users > Edit > PIN).'], 422);
        }
        if (! $user->pinMatches($pin)) {
            return response()->json(['success' => false, 'code' => 'wrong_pin', 'message' => 'Wrong PIN. Use the PIN of the cashier signed in to the POS.'], 422);
        }

        return null;
    }

    // Multicompany: an Admin only touches registers of their own company's warehouses.
    private function assertRegisterAccess(CashRegister $register): void
    {
        $user = Auth::user();
        if ($user && $user->type !== 'SuperAdmin') {
            $companyId = Warehouse::where('id', $register->warehouse_id)->value('company_id');
            abort_unless((int) $companyId === (int) $user->company_id, 403);
        }
    }

    /**
     * What the register did between opening and $until: POS sales made by the
     * cashier in that warehouse, payments per method, services vs products,
     * and the cash that should be in the drawer.
     */
    private function registerSummary(CashRegister $register, Carbon $until): array
    {
        // Sales rung up on this register (sales.cash_register_id) -- by the
        // cashier or by anyone who confirmed a sale with their PIN -- plus,
        // for sales from before that link existed, the cashier's own POS
        // sales at this location while it was open.
        $saleIds = Sale::whereNull('deleted_at')
            ->where('is_pos', 1)
            ->where(function ($q) use ($register, $until) {
                $q->where('cash_register_id', $register->id)
                    ->orWhere(function ($q) use ($register, $until) {
                        $q->whereNull('cash_register_id')
                            ->where('user_id', $register->user_id)
                            ->where('warehouse_id', $register->warehouse_id)
                            ->whereBetween('created_at', [$register->opened_at, $until]);
                    });
            })
            ->pluck('id');

        $totalSales = (float) Sale::whereIn('id', $saleIds)->sum('GrandTotal');

        // Sales per user (who made them) -- several when sales were confirmed with a PIN.
        $byUser = Sale::whereIn('sales.id', $saleIds)
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->groupBy('sales.user_id', 'users.firstname', 'users.lastname', 'users.username')
            ->orderByDesc(DB::raw('SUM(sales.GrandTotal)'))
            ->get(['sales.user_id', 'users.firstname', 'users.lastname', 'users.username', DB::raw('COUNT(*) as count'), DB::raw('SUM(sales.GrandTotal) as total')])
            ->map(fn ($r) => [
                'user_id' => (int) $r->user_id,
                'name' => trim(($r->firstname ?? '').' '.($r->lastname ?? '')) ?: ($r->username ?? ('#'.$r->user_id)),
                'username' => $r->username ?? '',
                'count' => (int) $r->count,
                'total' => round((float) $r->total, 3),
            ])->values()->all();

        $payments = DB::table('payment_sales')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_sales.payment_method_id')
            ->whereIn('payment_sales.sale_id', $saleIds)
            ->whereNull('payment_sales.deleted_at')
            ->groupBy('payment_methods.name')
            ->orderBy('payment_methods.name')
            ->get([DB::raw("COALESCE(payment_methods.name, 'Other') as method"), DB::raw('SUM(payment_sales.montant) as amount'), DB::raw('COUNT(*) as count')])
            ->map(fn ($p) => ['method' => $p->method, 'amount' => round((float) $p->amount, 3), 'count' => (int) $p->count])
            ->values()->all();

        $cashSales = collect($payments)->filter(fn ($p) => strtolower($p['method']) === 'cash')->sum('amount');

        $lines = DB::table('sale_details')
            ->join('products', 'products.id', '=', 'sale_details.product_id')
            ->whereIn('sale_details.sale_id', $saleIds)
            ->selectRaw("SUM(CASE WHEN products.type = 'is_service' THEN sale_details.total ELSE 0 END) as services_total,
                SUM(CASE WHEN products.type = 'is_service' THEN sale_details.quantity ELSE 0 END) as services_qty,
                SUM(CASE WHEN products.type <> 'is_service' THEN sale_details.total ELSE 0 END) as products_total,
                SUM(CASE WHEN products.type <> 'is_service' THEN sale_details.quantity ELSE 0 END) as products_qty")
            ->first();

        $opening = (float) ($register->opening_balance ?? 0);
        $cashIn = (float) ($register->cash_in ?? 0);
        $cashOut = (float) ($register->cash_out ?? 0);

        $user = $register->user()->first();
        $warehouse = Warehouse::find($register->warehouse_id);

        return [
            'register_id' => $register->id,
            'cashier' => $user ? trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: $user->username : '',
            'cashier_username' => $user->username ?? '',
            'warehouse' => $warehouse->name ?? '',
            'company_id' => $warehouse->company_id ?? null,
            'opened_at' => optional($register->opened_at)->format('Y-m-d H:i:s'),
            'until' => $until->format('Y-m-d H:i:s'),
            'sales_count' => $saleIds->count(),
            'total_sales' => round($totalSales, 3),
            'by_user' => $byUser,
            'payments' => $payments,
            'cash_sales' => round((float) $cashSales, 3),
            'services_total' => round((float) ($lines->services_total ?? 0), 3),
            'services_qty' => (float) ($lines->services_qty ?? 0),
            'products_total' => round((float) ($lines->products_total ?? 0), 3),
            'products_qty' => (float) ($lines->products_qty ?? 0),
            'opening_balance' => $opening,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => round($opening + $cashIn - $cashOut + (float) $cashSales, 3),
        ];
    }

    // Emails the register close-out to the company's admin users (fallback:
    // the company email). Never fails the close: returns what happened.
    private function emailCloseSummary(CashRegister $register, array $s): array
    {
        $companyId = $s['company_id'];
        $settings = \App\Models\Setting::forCompany($companyId);
        if ($settings && isset($settings->pos_close_register_email) && ! $settings->pos_close_register_email) {
            return ['sent' => false, 'skipped' => true, 'to' => []];
        }

        $sym = ($settings && $settings->currency_id) ? (DB::table('currencies')->where('id', $settings->currency_id)->value('symbol') ?: '') : '';
        $money = fn ($v) => $sym.number_format((float) $v, 2);
        $fmt = fn ($d) => $d ? Carbon::parse($d)->format('M j, Y g:i A') : '';
        $e = fn ($v) => e((string) $v);
        $minutes = (int) abs(Carbon::parse($s['opened_at'])->diffInMinutes(Carbon::parse($s['closed_at'])));
        $duration = intdiv($minutes, 60).'h '.($minutes % 60).'m';

        $row = fn ($label, $value, $strong = false) => '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee;color:#555">'.$label.'</td>'
            .'<td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;'.($strong ? 'font-weight:700' : '').'">'.$value.'</td></tr>';

        $payRows = '';
        foreach ($s['payments'] as $p) {
            $payRows .= $row($e($p['method']).' ('.$p['count'].')', $money($p['amount']));
        }
        if ($payRows === '') {
            $payRows = $row('No payments', $money(0));
        }
        $diffColor = abs($s['difference']) < 0.005 ? '#1e7a44' : '#a83232';
        // Sales per user (incl. sales other users confirmed with their PIN).
        $userRows = '';
        foreach ($s['by_user'] ?? [] as $u) {
            $userRows .= $row($e($u['name']).($u['username'] ? ' ('.$e($u['username']).')' : '').' &middot; '.$u['count'].' sale'.($u['count'] === 1 ? '' : 's'), $money($u['total']));
        }

        $body = '<h2 style="margin:0 0 4px">Register #'.$register->id.' closed</h2>'
            .'<p style="margin:0 0 16px;color:#666">'.$e($s['cashier']).' &middot; '.$e($s['warehouse']).'</p>'
            .'<table style="border-collapse:collapse;width:100%;max-width:520px;font-family:Arial,sans-serif;font-size:14px">'
            .$row('Cashier', $e($s['cashier']).' ('.$e($s['cashier_username']).')')
            .$row('Opened', $fmt($s['opened_at']))
            .$row('Closed', $fmt($s['closed_at']))
            .$row('Time open', $duration)
            .$row('Sales', $s['sales_count'].' &middot; '.$money($s['total_sales']), true)
            .$row('Services', $money($s['services_total']).' ('.rtrim(rtrim(number_format($s['services_qty'], 2), '0'), '.').' items)')
            .$row('Products', $money($s['products_total']).' ('.rtrim(rtrim(number_format($s['products_qty'], 2), '0'), '.').' items)')
            .($userRows !== '' ? '<tr><td colspan="2" style="padding:12px 10px 4px;font-weight:700">Sales by user</td></tr>'.$userRows : '')
            .'<tr><td colspan="2" style="padding:12px 10px 4px;font-weight:700">Payments</td></tr>'
            .$payRows
            .'<tr><td colspan="2" style="padding:12px 10px 4px;font-weight:700">Cash drawer</td></tr>'
            .$row('Opening cash', $money($s['opening_balance']))
            .$row('Cash sales', $money($s['cash_sales']))
            .$row('Cash in / out', $money($s['cash_in']).' / '.$money($s['cash_out']))
            .$row('Expected in drawer', $money($s['expected_cash']), true)
            .$row('Counted', $money($s['counted_cash']), true)
            .$row('Difference', '<span style="color:'.$diffColor.'">'.$money($s['difference']).'</span>', true)
            .'</table>'
            .(! empty($s['notes']) ? '<p style="margin-top:16px"><strong>Notes:</strong> '.nl2br($e($s['notes'])).'</p>' : '');

        return $this->sendAdminMail((int) $companyId,
            'Register #'.$register->id.' closed - '.$s['cashier'].' - '.$money($s['total_sales']), $body);
    }

    // Register opened: who, where, when, opening cash, note -> the company's admins.
    public function emailOpened(CashRegister $register): array
    {
        $warehouse = Warehouse::find($register->warehouse_id);
        $companyId = (int) optional($warehouse)->company_id;
        $settings = \App\Models\Setting::forCompany($companyId);
        if ($settings && isset($settings->pos_close_register_email) && ! $settings->pos_close_register_email) {
            return ['sent' => false, 'skipped' => true, 'to' => []];
        }
        $u = User::find($register->user_id);
        $cashier = $u ? (trim(($u->firstname ?? '').' '.($u->lastname ?? '')) ?: $u->username) : '';
        $sym = ($settings && $settings->currency_id) ? (DB::table('currencies')->where('id', $settings->currency_id)->value('symbol') ?: '') : '';
        $e = fn ($v) => e((string) $v);
        $row = fn ($label, $value) => '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee;color:#555">'.$label.'</td>'
            .'<td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;font-weight:600">'.$value.'</td></tr>';

        $body = '<h2 style="margin:0 0 4px">Register #'.$register->id.' opened</h2>'
            .'<p style="margin:0 0 16px;color:#666">'.$e($cashier).' &middot; '.$e(optional($warehouse)->name).'</p>'
            .'<table style="border-collapse:collapse;width:100%;max-width:520px;font-family:Arial,sans-serif;font-size:14px">'
            .$row('Cashier', $e($cashier).($u ? ' ('.$e($u->username).')' : ''))
            .$row('Location', $e(optional($warehouse)->name))
            .$row('Opened', Carbon::parse($register->opened_at)->format('M j, Y g:i A'))
            .$row('Opening cash', $sym.number_format((float) $register->opening_balance, 2))
            .'</table>'
            .(! empty($register->notes) ? '<p style="margin-top:16px"><strong>Notes:</strong> '.nl2br($e($register->notes)).'</p>' : '');

        return $this->sendAdminMail($companyId, 'Register #'.$register->id.' opened - '.$cashier.' - '.optional($warehouse)->name, $body);
    }

    /**
     * Sends a notice to the company's admins -- every admin user of the
     * company (+ the SuperAdmin copy when that company enabled it), see
     * CompanyMail::recipients -- through the company's own mail server.
     * Never throws: returns what happened.
     */
    private function sendAdminMail(int $companyId, string $subject, string $body): array
    {
        $recipients = \App\Support\CompanyMail::recipients($companyId);
        $to = array_column(array_filter($recipients, fn ($r) => ! $r['copy']), 'email');
        $copy = array_column(array_filter($recipients, fn ($r) => $r['copy']), 'email');
        if (! $to && ! $copy) {
            return ['sent' => false, 'to' => [], 'error' => 'No admin email found for this company.'];
        }

        try {
            [$mailer, , $companyName] = \App\Support\CompanyMail::mailer($companyId);
            $pending = $mailer->to($to ?: $copy);
            if ($to && $copy) {
                $pending->bcc($copy); // SuperAdmin copy, not shown to the company's admins
            }
            $pending->send(new \App\Mail\CustomEmail([
                'subject' => $subject,
                'body' => $body,
                'company_name' => $companyName,
            ]));

            return ['sent' => true, 'to' => $to, 'copy' => $copy];
        } catch (\Throwable $ex) {
            \Log::warning('Register email failed: '.$ex->getMessage(), ['subject' => $subject]);

            return ['sent' => false, 'to' => $to, 'error' => 'Email could not be sent: '.$ex->getMessage()];
        }
    }

    public function report(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'cash_register_report', Sale::class);

        // Normalize date filter to Y-m-d (avoid timezone/invalid input issues)
        $today = Carbon::today();
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->toDateString()
            : $today->copy()->subDays(6)->toDateString();
        $to = $request->filled('to')
            ? Carbon::parse($request->to)->toDateString()
            : $today->toDateString();
        if ($from > $to) {
            $from = $to;
        }

        // Pagination + Sorting (align with Report_Sales)
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'opened_at';
        $dir = $request->SortType ?: 'desc';

        $allowedSorts = ['opened_at', 'closed_at', 'opening_balance', 'closing_balance', 'cash_in', 'cash_out', 'total_sales', 'difference', 'status', 'warehouse_id', 'user_id'];
        if (! in_array($order, $allowedSorts, true)) {
            $order = 'opened_at';
        }

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $is_all_warehouses = $this->reportAllWarehouses();
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = $this->reportWarehouseIdList();
        }

        $query = CashRegister::with(['user:id,firstname,lastname,username', 'warehouse:id,name'])
            ->where(function ($q) use ($view_records) {
                if (! $view_records) {
                    return $q->where('user_id', Auth::user()->id);
                }
            });
        if (! $is_all_warehouses) {
            $query->whereIn('warehouse_id', $warehouse_ids);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $query->whereDate('opened_at', '>=', $from);
        $query->whereDate('opened_at', '<=', $to);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('status', 'like', "%$search%")
                    ->orWhere('opening_balance', 'like', "%$search%")
                    ->orWhere('closing_balance', 'like', "%$search%")
                    ->orWhere('cash_in', 'like', "%$search%")
                    ->orWhere('cash_out', 'like', "%$search%")
                    ->orWhere('total_sales', 'like', "%$search%")
                    ->orWhere('difference', 'like', "%$search%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('firstname', 'like', "%$search%")
                            ->orWhere('lastname', 'like', "%$search%")
                            ->orWhere('username', 'like', "%$search%");
                    })
                    ->orWhereHas('warehouse', function ($wq) use ($search) {
                        $wq->where('name', 'like', "%$search%");
                    });
            });
        }

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $items = $query->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $data = [];
        foreach ($items as $r) {
            $item['id'] = $r->id;
            $item['user_id'] = $r->user_id;
            $item['warehouse_id'] = $r->warehouse_id;
            $item['cashier_firstname'] = optional($r->user)->firstname;
            $item['cashier_lastname'] = optional($r->user)->lastname;
            $item['cashier_username'] = optional($r->user)->username;
            $item['cashier_name'] = optional($r->user)->username;
            $item['warehouse_name'] = optional($r->warehouse)->name;
            $item['opened_at'] = optional($r->opened_at)->format('Y-m-d H:i:s');
            $item['closed_at'] = $r->closed_at ? optional($r->closed_at)->format('Y-m-d H:i:s') : null;
            $item['status'] = $r->status;
            $item['opening_balance'] = number_format((float) $r->opening_balance, 2, '.', '');
            $item['cash_in'] = number_format((float) $r->cash_in, 2, '.', '');
            $item['cash_out'] = number_format((float) $r->cash_out, 2, '.', '');
            $item['total_sales'] = number_format((float) $r->total_sales, 2, '.', '');
            $item['closing_balance'] = is_null($r->closing_balance) ? null : number_format((float) $r->closing_balance, 2, '.', '');
            $item['difference'] = is_null($r->difference) ? null : number_format((float) $r->difference, 2, '.', '');
            $data[] = $item;
        }

        // Users & Warehouses for filters (mirror sales report)
        $users = $this->rc(User::query(), 'users.company_id')->where('deleted_at', '=', null)->get(['id', 'username', 'firstname', 'lastname']);

        $user_auth = auth()->user();
        if ($this->reportAllWarehouses()) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = $this->reportWarehouseIdList();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'totalRows' => $totalRows,
            'registers' => $data,
            'users' => $users,
            'warehouses' => $warehouses,
        ]);
    }
}
