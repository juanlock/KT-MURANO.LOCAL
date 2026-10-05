<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenses;
use App\Models\Role;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\utils\helpers;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ExpensesController extends BaseController
{
    // Multicompany: company-aware warehouse scope -- see the trait.
    use \App\Http\Controllers\Concerns\ScopesReportsToCompany;

    use ScopesToCompany;

    // -------------- Show All  Expenses -----------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Expense::class);

        // Recurring expenses whose date has come are created first, so the
        // list is complete even when the daily scheduler isn't running.
        RecurringExpenses::generateDue((int) $this->resolveActingCompanyId($request) ?: null);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        $user = Auth::user();
        $is_all_warehouses = $this->reportAllWarehouses();
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = $this->reportWarehouseIdList();
        }
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        // Filter fields With Params to retrieve
        $columns = [0 => 'Ref', 1 => 'warehouse_id', 2 => 'date', 3 => 'expense_category_id', 4 => 'account_id', 5 => 'payment_method_id'];
        $param = [0 => 'like', 1 => '=', 2 => '=', 3 => '=', 4 => '=', 5 => '='];
        $data = [];

        // Check If User Has Permission View  All Records
        $Expenses = Expense::with('expense_category', 'warehouse', 'account')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

            if (! $is_all_warehouses) {
                $Expenses->whereIn('warehouse_id', $warehouse_ids);
            }

        // Date range (the list's Start date / to filter), YYYY-MM-DD each end.
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
            $d = (string) $request->input($key, '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $Expenses->whereDate('date', $op, $d);
            }
        }

        // Multiple Filter
        $Filtred = $helpers->filter($Expenses, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('date', 'LIKE', "%{$request->search}%")
                        ->orWhere('details', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('expense_category', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('payment_method', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('account', function ($q) use ($request) {
                                $q->where('account_name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });
        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $Expenses = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Expenses as $Expense) {

            $item['id'] = $Expense->id;
            $item['date'] = $Expense->date;
            $item['Ref'] = $Expense->Ref;
            $item['details'] = $Expense->details;
            $item['amount'] = $Expense->amount;
            $item['payment_method'] = $Expense['payment_method'] ? $Expense['payment_method']->name : '---';
            $item['warehouse_name'] = $Expense['warehouse']->name;
            $item['category_name'] = $Expense['expense_category']->name;
            // Group (expense-list columns); the name is translated client-side
            // (translations table, lib/localName).
            $item['category_group'] = $Expense['expense_category']->expense_group;
            $item['category_group_es'] = $Expense['expense_category']->expense_group_es;
            $item['account_name'] = $Expense['account'] ? $Expense['account']->account_name : 'N/D';
            // Set when the expense comes from HRM Payroll (read-only here).
            $item['payroll_id'] = $Expense->payroll_id;
            // Created automatically by a recurring expense (Expenses -> Recurring).
            $item['recurring_expense_id'] = $Expense->recurring_expense_id;

            // Get documents count
            $item['documents_count'] = DB::table('expense_documents')
                ->where('expense_id', $Expense->id)
                ->whereNull('deleted_at')
                ->count();

            $data[] = $item;
        }

        // The acting company's categories (per company), with the Spanish
        // names and groups so the dropdown follows the user's language.
        $Expenses_category = ExpenseCategory::where('deleted_at', '=', null)
            ->where('company_id', $this->resolveActingCompanyId($request))
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'reference', 'expense_group', 'expense_group_es', 'is_common', 'period', 'period_es', 'approximate_amount']);
        $accounts = Account::where('deleted_at', '=', null)->get(['id', 'account_name']);
        $payment_methods = PaymentMethod::active()->where('deleted_at', '=', null)->get(['id', 'name']);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($this->reportAllWarehouses()) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = $this->reportWarehouseIdList();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'expenses' => $data,
            'Expenses_category' => $Expenses_category,
            'warehouses' => $warehouses,
            'accounts' => $accounts,
            'totalRows' => $totalRows,
            'payment_methods' => $payment_methods,
            'recurring_count' => RecurringExpense::where('company_id', $this->resolveActingCompanyId($request))->count(),
        ]);

    }

    // -------------- Store New Expense -----------\\

    /**
     * Expense categories are per company: the category must belong to the
     * company of the expense's location (Warehouse has company_id).
     */
    private function abortIfCategoryOfOtherCompany($categoryId, $warehouseId): void
    {
        $companyId = Warehouse::whereKey($warehouseId)->value('company_id');
        $catCompany = ExpenseCategory::whereKey($categoryId)->whereNull('deleted_at')->value('company_id');
        abort_if($companyId && $catCompany && (int) $catCompany !== (int) $companyId, 422, 'That expense category belongs to another company.');
    }

    /**
     * A payroll's expense (App\Services\PayrollExpenseSync) follows its
     * payroll -- which also owns the bank balance -- so it's changed or
     * deleted from HRM -> Payroll, never here.
     */
    private function abortIfPayrollExpense(Expense $expense): void
    {
        abort_if(! empty($expense->payroll_id), 422, 'This expense comes from HRM Payroll. Edit or delete the payroll instead.');
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Expense::class);

        \DB::transaction(function () use ($request) {
            request()->validate([
                'expense.date' => 'required',
                'expense.warehouse_id' => 'required',
                'expense.category_id' => 'required',
                'expense.details' => 'required',
                'expense.amount' => 'required',
                'expense.payment_method_id' => 'required',
            ]);
            $this->abortIfCategoryOfOtherCompany($request['expense']['category_id'], $request['expense']['warehouse_id']);

            $expense = Expense::create([
                'user_id' => Auth::user()->id,
                'date' => $request['expense']['date'],
                'Ref' => $this->getNumberOrder(),
                'payment_method_id' => $request['expense']['payment_method_id'],
                'warehouse_id' => $request['expense']['warehouse_id'],
                'expense_category_id' => $request['expense']['category_id'],
                'account_id' => $request['expense']['account_id'],
                'details' => $request['expense']['details'],
                'amount' => $request['expense']['amount'],
            ]);

            $account = Account::find($request['expense']['account_id']);

            if ($account) {
                $account->update([
                    'balance' => $account->balance - $request['expense']['amount'],
                ]);
            }

            // "How often?" = same amount every period: save the schedule;
            // this expense is the first one, the next ones are created on
            // their dates (App\Services\RecurringExpenses).
            $repeat = (array) ($request['expense']['repeat'] ?? []);
            if (in_array($repeat['frequency'] ?? null, RecurringExpense::FREQUENCIES, true)) {
                $end = ! empty($repeat['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $repeat['end_date']) ? $repeat['end_date'] : null;
                $plan = RecurringExpense::create([
                    'company_id' => (int) Warehouse::whereKey($expense->warehouse_id)->value('company_id'),
                    'user_id' => Auth::user()->id,
                    'warehouse_id' => $expense->warehouse_id,
                    'expense_category_id' => $expense->expense_category_id,
                    'payment_method_id' => $expense->payment_method_id,
                    'account_id' => $expense->account_id ?: null,
                    'amount' => $expense->amount,
                    'details' => $expense->details,
                    'frequency' => $repeat['frequency'],
                    'start_date' => Carbon::parse($expense->date)->toDateString(),
                    'next_date' => RecurringExpenses::advance(Carbon::parse($expense->date)->toDateString(), $repeat['frequency']),
                    'end_date' => $end,
                    'is_active' => true,
                    'last_generated_on' => Carbon::parse($expense->date)->toDateString(),
                    'generated_count' => 1,
                ]);
                $expense->update(['recurring_expense_id' => $plan->id]);
            }

        }, 10);

        // Created from a bill in Email Office (?email=): link that email to it
        // (as the expense's bill; its reminders / payments can join its history).
        if ($request->filled('expense.office_email_id') && \Illuminate\Support\Facades\Schema::hasTable('office_emails')) {
            $newest = Expense::where('user_id', Auth::user()->id)->latest('id')->value('id');
            \App\Models\OfficeEmail::whereKey((int) $request->input('expense.office_email_id'))
                ->where('company_id', (int) $this->resolveActingCompanyId($request))
                ->update(['linked_type' => 'expense', 'linked_id' => $newest, 'category' => 'bill', 'link_role' => 'bill']);
        }

        // A first date in the past: catch up the periods since then.
        RecurringExpenses::generateDue((int) $this->resolveActingCompanyId($request) ?: null);

        return response()->json(['success' => true]);
    }

    // ------------- Recurring expenses (Expenses -> Recurring) ----------\\

    /** The acting company's recurring expenses. */
    public function recurringIndex(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Expense::class);
        $rows = RecurringExpense::with(['expense_category:id,name', 'warehouse:id,name', 'payment_method:id,name'])
            ->where('company_id', $this->resolveActingCompanyId($request))
            ->orderByDesc('is_active')->orderBy('next_date')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'category_name' => $p->expense_category->name ?? '—',
                'warehouse_name' => $p->warehouse->name ?? '—',
                'payment_method' => $p->payment_method->name ?? '—',
                'details' => $p->details,
                'amount' => $p->amount,
                'frequency' => $p->frequency,
                'start_date' => $p->start_date,
                'next_date' => $p->next_date,
                'end_date' => $p->end_date,
                'is_active' => $p->is_active,
                'generated_count' => $p->generated_count,
            ]);

        return response()->json(['recurring' => $rows]);
    }

    private function findRecurring(Request $request, $id): RecurringExpense
    {
        return RecurringExpense::where('company_id', $this->resolveActingCompanyId($request))->findOrFail($id);
    }

    /** Pause / resume, or change the amount / end date of the next ones. */
    public function recurringUpdate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Expense::class);
        $plan = $this->findRecurring($request, $id);
        $v = $request->validate([
            'is_active' => 'sometimes|boolean',
            'amount' => 'sometimes|numeric|min:0.01',
            'end_date' => 'sometimes|nullable|date_format:Y-m-d',
        ]);
        // Resuming after a pause starts from the next date still ahead, so
        // the paused periods aren't created all at once.
        if (($v['is_active'] ?? false) && ! $plan->is_active) {
            $today = Carbon::today()->toDateString();
            while ($plan->next_date < $today) {
                $plan->next_date = RecurringExpenses::advance($plan->next_date, $plan->frequency);
            }
        }
        $plan->fill($v)->save();

        return response()->json(['success' => true]);
    }

    /** Stop for good. Expenses already created stay (delete them one by one if needed). */
    public function recurringDestroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Expense::class);
        $this->findRecurring($request, $id)->delete();

        return response()->json(['success' => true]);
    }

    // ------------- Get Expense Documents ----------\\
    public function getDocuments($expenseId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Expense::class);

        $expense = Expense::findOrFail($expenseId);

        $documents = DB::table('expense_documents')
            ->where('expense_id', $expenseId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'documents' => $documents,
            'status' => true,
        ]);
    }

    // ------------- Upload Expense Documents ----------\\
    public function uploadDocuments(Request $request, $expenseId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Expense::class);

        $expense = Expense::findOrFail($expenseId);

        // Expense has no company_id column directly -- resolve it via the
        // expense's own warehouse (Warehouse has company_id), falling back
        // to the acting request's company when the warehouse can't be
        // resolved (e.g. no warehouse set on the expense).
        $companyId = optional(Warehouse::find($expense->warehouse_id))->company_id;
        if (! $companyId) {
            $companyId = $this->resolveActingCompanyId($request);
        }

        $request->validate([
            'documents.*' => 'required|file|max:10240', // Max 10MB per file
        ]);

        $uploadedDocuments = [];

        if ($request->hasFile('documents')) {
            // Company-scoped: files/company_{id}/expense_documents, not
            // one shared folder for the whole install.
            $uploadRel = \App\Support\CompanyFiles::dir((int) $companyId, 'expense_documents');
            $uploadPath = public_path($uploadRel);

            foreach ($request->file('documents') as $file) {
                // Capture metadata BEFORE moving the file (tmp file is still readable)
                $originalName = $file->getClientOriginalName();
                $size = $file->getSize();
                $mimeType = $file->getMimeType();

                $filename = time() . '_' . Str::random(10) . '_' . $originalName;

                // Move file to public/files/company_{id}/expense_documents
                $file->move($uploadPath, $filename);

                $relativePath = $uploadRel.'/'.$filename;

                $documentId = DB::table('expense_documents')->insertGetId([
                    'expense_id' => $expenseId,
                    'name' => $originalName,
                    'path' => $relativePath,
                    'size' => $size,
                    'mime_type' => $mimeType,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $uploadedDocuments[] = $documentId;
            }
        }

        return response()->json([
            'message' => 'Documents uploaded successfully',
            'documents' => $uploadedDocuments,
            'status' => true,
        ]);
    }

    // ------------- Download Expense Document ----------\\
    public function downloadDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Expense::class);

        $document = DB::table('expense_documents')
            ->where('id', $documentId)
            ->whereNull('deleted_at')
            ->first();

        if (! $document) {
            return response()->json([
                'message' => 'Document not found in database',
                'status' => false,
            ], 404);
        }

        $filePath = public_path($document->path);

        if (! file_exists($filePath)) {
            return response()->json([
                'message' => 'Physical file not found on server',
                'status' => false,
                'path' => $document->path,
            ], 404);
        }

        return response()->download($filePath, $document->name);
    }

    // ------------- Delete Expense Document ----------\\
    public function deleteDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'delete', Expense::class);

        $document = DB::table('expense_documents')
            ->where('id', $documentId)
            ->whereNull('deleted_at')
            ->first();

        if (! $document) {
            return response()->json([
                'message' => 'Document not found',
                'status' => false,
            ], 404);
        }

        // Soft delete
        DB::table('expense_documents')
            ->where('id', $documentId)
            ->update(['deleted_at' => Carbon::now()]);

        // Optionally delete the physical file
        $filePath = public_path($document->path);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return response()->json([
            'message' => 'Document deleted successfully',
            'status' => true,
        ]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // -------------- Update  Expense -----------\\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Expense::class);
        \DB::transaction(function () use ($request, $id) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $expense = Expense::findOrFail($id);
            $this->abortIfPayrollExpense($expense);

            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($expense);

            if (! $view_records) {
                // Check If User->id === expense->id
                $this->authorizeForUser($request->user('api'), 'check_record', $expense);
            }

            request()->validate([
                'expense.date' => 'required',
                'expense.warehouse_id' => 'required',
                'expense.category_id' => 'required',
                'expense.details' => 'required',
                'expense.amount' => 'required',
                'expense.payment_method_id' => 'required',
            ]);
            $this->abortIfCategoryOfOtherCompany($request['expense']['category_id'], $request['expense']['warehouse_id']);

            $account = Account::find($expense->account_id);

            if ($account) {
                $account->update([
                    'balance' => $account->balance + $expense->amount,
                ]);
            }

            Expense::whereId($id)->update([
                'date' => $request['expense']['date'],
                'payment_method_id' => $request['expense']['payment_method_id'],
                'warehouse_id' => $request['expense']['warehouse_id'],
                'expense_category_id' => $request['expense']['category_id'],
                'account_id' => $request['expense']['account_id'] ? $request['expense']['account_id'] : null,
                'details' => $request['expense']['details'],
                'amount' => $request['expense']['amount'],
            ]);

            $account = Account::find($request['expense']['account_id']);
            if ($account) {
                $account->update([
                    'balance' => $account->balance - $request['expense']['amount'],
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete Expense -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Expense::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $expense = Expense::findOrFail($id);
        $this->abortIfPayrollExpense($expense);

        // Check If User Has Permission view All Records
        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($expense);

        if (! $view_records) {
            // Check If User->id === expense->id
            $this->authorizeForUser($request->user('api'), 'check_record', $expense);
        }

        Expense::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        $account = Account::where('id', $expense->account_id)->exists();

        if ($account) {
            // Account exists, perform the update
            $account = Account::find($expense->account_id);
            $account->update([
                'balance' => $account->balance + $expense->amount,
            ]);
        }

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Expense::class);
        $selectedIds = $request->selectedIds;
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();

        foreach ($selectedIds as $expense_id) {
            $expense = Expense::findOrFail($expense_id);
            if (! empty($expense->payroll_id)) {
                continue; // payroll expenses are deleted with their payroll
            }

            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($expense);

            if (! $view_records) {
                // Check If User->id === expense->id
                $this->authorizeForUser($request->user('api'), 'check_record', $expense);
            }
            Expense::whereId($expense_id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            $account = Account::where('id', $expense->account_id)->exists();

            if ($account) {
                // Account exists, perform the update
                $account = Account::find($expense->account_id);
                $account->update([
                    'balance' => $account->balance + $expense->amount,
                ]);
            }
        }

        return response()->json(['success' => true]);
    }

    // --------------- Reference Number of Expense ----------------\\

    public function getNumberOrder()
    {

        $last = DB::table('expenses')->latest('id')->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            // Refs from imports/demo data may not follow the EXP_n format
            // (e.g. "DEMO-EXP-0015") â€” fall back to the trailing number.
            if (count($nwMsg) >= 2 && is_numeric($nwMsg[1])) {
                $code = $nwMsg[0].'_'.($nwMsg[1] + 1);
            } elseif (preg_match('/(\d+)$/', $item, $m)) {
                $code = 'EXP_'.((int) $m[1] + 1);
            } else {
                $code = 'EXP_'.($last->id + 1);
            }
        } else {
            $code = 'EXP_1111';
        }

        return $code;

    }

    // ---------------- Show Form Create Expense ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Expense::class);

        // get warehouses assigned to user -- only the acting company's (company boundary)
        $cid = (int) $this->resolveActingCompanyId($request);
        if ($this->reportAllWarehouses()) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->where('company_id', $cid)->get(['id', 'name']);
        } else {
            $warehouses_id = $this->reportWarehouseIdList();
            $warehouses = Warehouse::where('deleted_at', '=', null)->where('company_id', $cid)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        // The acting company's categories (per company), with the Spanish
        // names and groups so the dropdown follows the user's language.
        $Expenses_category = ExpenseCategory::where('deleted_at', '=', null)
            ->where('company_id', $cid)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'reference', 'expense_group', 'expense_group_es', 'is_common', 'period', 'period_es', 'approximate_amount']);
        $accounts = Account::where('deleted_at', '=', null)->get(['id', 'account_name']);
        $payment_methods = PaymentMethod::active()->where('deleted_at', '=', null)->get(['id', 'name']);

        // Defaults -> Expenses: what a new expense starts with (when still valid).
        $settings = \App\Models\Setting::forCompany($cid);
        $defPm = (int) ($settings->expense_default_payment_method_id ?? 0);
        $defAcc = (int) ($settings->expense_default_account_id ?? 0);

        return response()->json([
            'Expenses_category' => $Expenses_category,
            'warehouses' => $warehouses,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
            'default_payment_method_id' => $payment_methods->contains('id', $defPm) ? $defPm : null,
            'default_account_id' => $accounts->contains('id', $defAcc) ? $defAcc : null,
        ]);
    }

    /**
     * POST expenses/defaults {payment_method_id?, account_id?} -- "Make
     * default" on the expense form: saves Defaults -> Expenses / Payments for
     * the acting company (same permission as System Settings).
     */
    public function saveDefaults(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', \App\Models\Setting::class);
        $v = $request->validate(['payment_method_id' => 'nullable|integer', 'account_id' => 'nullable|integer']);
        $settings = \App\Models\Setting::forCompany((int) $this->resolveActingCompanyId($request));
        if (array_key_exists('payment_method_id', $v)) {
            abort_if($v['payment_method_id'] && ! PaymentMethod::whereKey($v['payment_method_id'])->whereNull('deleted_at')->exists(), 422, 'Unknown payment method.');
            $settings->expense_default_payment_method_id = $v['payment_method_id'] ?: null;
        }
        if (array_key_exists('account_id', $v)) {
            abort_if($v['account_id'] && ! Account::whereKey($v['account_id'])->whereNull('deleted_at')->exists(), 422, 'Unknown account.');
            $settings->expense_default_account_id = $v['account_id'] ?: null;
        }
        $settings->save();

        return response()->json(['success' => true]);
    }

    // ------------- Show Form Edit Expense -----------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Expense::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Expense = Expense::where('deleted_at', '=', null)->findOrFail($id);

        // Check If User Has Permission view All Records
        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($Expense);

        if (! $view_records) {
            // Check If User->id === Expense->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Expense);
        }

        if ($Expense->warehouse_id) {
            if (Warehouse::where('id', $Expense->warehouse_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $data['warehouse_id'] = $Expense->warehouse_id;
            } else {
                $data['warehouse_id'] = '';
            }
        } else {
            $data['warehouse_id'] = '';
        }

        if ($Expense->account_id) {
            if (Account::where('id', $Expense->account_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $data['account_id'] = $Expense->account_id;
            } else {
                $data['account_id'] = '';
            }
        } else {
            $data['account_id'] = '';
        }

        if ($Expense->expense_category_id) {
            if (ExpenseCategory::where('id', $Expense->expense_category_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $data['category_id'] = $Expense->expense_category_id;
            } else {
                $data['category_id'] = '';
            }
        } else {
            $data['category_id'] = '';
        }

        $data['date'] = $Expense->date;
        $data['amount'] = $Expense->amount;
        $data['details'] = $Expense->details;
        $data['payment_method_id'] = $Expense->payment_method_id;
        // Made by a recurring expense: the form says so (edits change only this one).
        $data['recurring_expense_id'] = $Expense->recurring_expense_id;

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($this->reportAllWarehouses()) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = $this->reportWarehouseIdList();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        // The acting company's categories (per company), with the Spanish
        // names and groups so the dropdown follows the user's language.
        $Expenses_category = ExpenseCategory::where('deleted_at', '=', null)
            ->where('company_id', $this->resolveActingCompanyId($request))
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'reference', 'expense_group', 'expense_group_es', 'is_common', 'period', 'period_es', 'approximate_amount']);
        $accounts = Account::where('deleted_at', '=', null)->get(['id', 'account_name']);
        $payment_methods = PaymentMethod::active()->where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'expense' => $data,
            'expense_Category' => $Expenses_category,
            'warehouses' => $warehouses,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
        ]);
    }
}
