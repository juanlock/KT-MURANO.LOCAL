<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Payroll;
use App\Services\PayrollExpenseSync;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PayrollController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesToCompany;

    /**
     * Multicompany: the acting company (an Admin's own; a SuperAdmin's
     * switcher pick), null = a SuperAdmin on "all companies".
     */
    private function payrollCompany(Request $request): ?int
    {
        $cid = $this->resolveActingCompanyId($request);
        $isSA = optional($request->user('api'))->type === 'SuperAdmin';

        return $isSA && ($cid === 999 || ! $request->filled('company_id')) ? null : $cid;
    }

    /** A payroll of the acting company, or 404. */
    private function scopedPayroll(Request $request, $id): Payroll
    {
        $cid = $this->payrollCompany($request);

        return Payroll::whereNull('deleted_at')
            ->when($cid, fn ($q) => $q->whereIn('employee_id', Employee::where('company_id', $cid)->select('id')))
            ->findOrFail($id);
    }

    // ----------- GET ALL Payrolls --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Payroll::class);
        // superadmin/AdminSystem: every company (2026-10-21)
        $companyId = $this->listCompanyId($request);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $data = [];

        $payrolls = Payroll::with('account', 'employee')->where('deleted_at', '=', null)
            // Multicompany: only the acting company's employees' payrolls.
            ->when($companyId, fn ($q) => $q->whereIn('employee_id', Employee::where('company_id', $companyId)->select('id')))

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('account', function ($q) use ($request) {
                                $q->where('account_name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('employee', function ($q) use ($request) {
                                $q->where('username', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });
        $totalRows = $payrolls->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $payrolls_data = $payrolls->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        // superadmin/AdminSystem: every company (2026-10-21) -- each row says whose it is.
        $rowCompany = fn ($p) => $p->company_id ?: optional($p->employee)->company_id;
        $companyNames = \App\Models\Company::whereIn('id', $payrolls_data->map($rowCompany)->filter()->unique())->pluck('name', 'id');

        foreach ($payrolls_data as $payroll) {
            $rc = $rowCompany($payroll);
            $item['company_name'] = $rc == \App\Models\Setting::TEMPLATE_COMPANY_ID ? 'AdminSystem' : ($companyNames[$rc] ?? null);

            $item['id'] = $payroll->id;
            $item['Ref'] = $payroll->Ref;
            $item['account_id'] = $payroll->account_id;
            $item['employee_id'] = $payroll->employee_id;
            $item['account_name'] = $payroll['account'] ? $payroll['account']->account_name : '---';
            $emp = $payroll['employee'];
            $item['employee_name'] = $emp ? (trim($emp->firstname.' '.$emp->lastname) ?: $emp->username) : '---';
            $item['date'] = $payroll->date;
            $item['amount'] = $payroll->amount;
            $item['payment_method_id'] = $payroll->payment_method_id;
            $item['payment_method'] = optional($payroll->payment_method)->name;
            $item['payment_status'] = $payroll->payment_status;
            // Weekly run: the period and how it was worked out.
            $item['period_start'] = $payroll->period_start;
            $item['period_end'] = $payroll->period_end;
            $item['pay_type'] = $payroll->pay_type;
            $item['hours'] = $payroll->hours;
            $item['rate'] = $payroll->rate;
            $item['commission_base'] = $payroll->commission_base;
            $item['commission_rate'] = $payroll->commission_rate;
            $item['notes'] = $payroll->notes;

            $data[] = $item;
        }

        $accounts = Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']);
        $employees = Employee::where('deleted_at', '=', null)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('firstname')->get(['id', 'username', 'firstname', 'lastname', 'pay_type', 'hourly_rate', 'commission_rate', 'pay_effective_from']);
        $payment_methods = PaymentMethod::active()->where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'payrolls' => $data,
            'totalRows' => $totalRows,
            'accounts' => $accounts,
            'employees' => $employees,
            'payment_methods' => $payment_methods,
        ]);
    }

    public function create(Request $request)
    {
        //
    }

    // ------- Weekly payroll run: preview (nothing saved) -------\\
    public function weeklyPreview(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Payroll::class);
        $companyId = $this->resolveActingCompanyId($request);
        abort_if($companyId === 999, 422, 'Choose a company in the switcher first.');
        [$from, $to] = \App\Services\PayrollRun::week($request->input('date') ?: now()->toDateString());

        return response()->json([
            'period_start' => $from,
            'period_end' => $to,
            'rows' => \App\Services\PayrollRun::preview($companyId, $from, $to),
        ]);
    }

    // ------- Weekly payroll run: create the week's payrolls -------\\
    public function weeklyCreate(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Payroll::class);
        $request->validate(['date' => 'required|date', 'payment_method_id' => 'required|integer']);
        $companyId = $this->resolveActingCompanyId($request);
        abort_if($companyId === 999, 422, 'Choose a company in the switcher first.');
        [$from, $to] = \App\Services\PayrollRun::week($request->input('date'));
        $accountId = $request->filled('account_id') ? (int) $request->input('account_id') : null;

        $made = \App\Services\PayrollRun::create($companyId, $from, $to, (int) Auth::id(), (int) $request->input('payment_method_id'), $accountId,
            $request->input('paid', true) !== false && $request->input('paid', true) !== 'false');

        return response()->json(['success' => true, 'created' => count($made), 'total' => round(array_sum(array_map(fn ($p) => (float) $p->amount, $made)), 2)]);
    }

    // ----------- Store new Payroll --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Payroll::class);

        request()->validate([
            'date' => 'required',
            'employee_id' => 'required',
            'amount' => 'required',
            'payment_method_id' => 'required',
        ]);

        // Multicompany: only an employee of the acting company.
        $companyId = $this->payrollCompany($request);
        $employeeCompany = Employee::whereKey($request['employee_id'])->whereNull('deleted_at')->value('company_id');
        abort_unless($employeeCompany && (! $companyId || (int) $employeeCompany === $companyId), 422, 'Employee not found in this company.');

        \DB::transaction(function () use ($request, $employeeCompany) {

            $payroll = Payroll::create([
                'user_id' => Auth::user()->id,
                'company_id' => (int) $employeeCompany,
                'Ref' => $this->getNumberOrder(),
                'date' => $request['date'],
                'employee_id' => $request['employee_id'],
                'account_id' => $request['account_id'] ? $request['account_id'] : null,
                'amount' => $request['amount'],
                'payment_method_id' => $request['payment_method_id'],
                'payment_status' => 'paid',
            ]);

            $account = Account::where('id', $request['account_id'])->exists();

            if ($account) {
                // Account exists, perform the update
                $account = Account::find($request['account_id']);
                $account->update([
                    'balance' => $account->balance - $request['amount'],
                ]);
            }

            // Accounting: linked "Salaries and wages" expense + journal entry.
            PayrollExpenseSync::sync($payroll);

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    public function edit(Request $request, $id)
    {
        //

    }

    // -----------Update Payroll --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Payroll::class);

        request()->validate([
            'date' => 'required',
            'employee_id' => 'required',
            'amount' => 'required',
            'payment_method_id' => 'required',
        ]);

        $this->scopedPayroll($request, $id);
        $companyId = $this->payrollCompany($request);
        $employeeCompany = Employee::whereKey($request['employee_id'])->whereNull('deleted_at')->value('company_id');
        abort_unless($employeeCompany && (! $companyId || (int) $employeeCompany === $companyId), 422, 'Employee not found in this company.');

        \DB::transaction(function () use ($id, $request) {

            $payroll = Payroll::findOrFail($id);

            // delete old balance
            $account = Account::where('id', $payroll->account_id)->exists();

            if ($account) {
                // Account exists, perform the update
                $account = Account::find($payroll->account_id);
                $account->update([
                    'balance' => $account->balance + $payroll->amount,
                ]);
            }

            Payroll::whereId($id)->update([
                'date' => $request['date'],
                'employee_id' => $request['employee_id'],
                'account_id' => $request['account_id'] ? $request['account_id'] : null,
                'amount' => $request['amount'],
                'payment_method_id' => $request['payment_method_id'],
            ]);

            // update new account
            $new_account = Account::where('id', $request['account_id'])->exists();

            if ($new_account) {
                // Account exists, perform the update
                $new_account = Account::find($request['account_id']);
                $new_account->update([
                    'balance' => $new_account->balance - $request['amount'],
                ]);
            }

            PayrollExpenseSync::sync($payroll->fresh());

        }, 10);

        return response()->json(['success' => true]);
    }

    // ----------- Delete  Payroll --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Payroll::class);
        $this->scopedPayroll($request, $id);
        \DB::transaction(function () use ($id) {
            $payroll = Payroll::findOrFail($id);

            Payroll::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            $account = Account::where('id', $payroll->account_id)->exists();

            if ($account) {
                // Account exists, perform the update
                $account = Account::find($payroll->account_id);
                $account->update([
                    'balance' => $account->balance + $payroll->amount,
                ]);
            }

            PayrollExpenseSync::remove($payroll);

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Reference Number of Adjustement  -----------\\

    public function getNumberOrder()
    {

        $last = DB::table('payrolls')->latest('id')->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            $inMsg = $nwMsg[1] + 1;
            $code = $nwMsg[0].'_'.$inMsg;
        } else {
            $code = 'PS_1';
        }

        return $code;

    }
}
