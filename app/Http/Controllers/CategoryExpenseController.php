<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ExpenseCategory;
use App\Support\TranslationKeys;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Expense categories, per company (multicompany rule): an Admin works on
 * their own company's, a SuperAdmin on the company picked in the header.
 * The name is translated in Settings -> Translations (key = the name,
 * App\Support\TranslationKeys); reference = a short explanation. Each has the expense-list columns (account type,
 * group, typical account, period, approximate amount, most common) and the
 * account in the company's chart its expenses post to (coa_code). The
 * company's default set (App\Support\DefaultAccounting) is visible to all of
 * its users; other categories keep the record_view rule (own vs all).
 */
class CategoryExpenseController extends BaseController
{
    use ScopesToCompany;

    public const ACCOUNT_TYPES = ['cogs', 'operating_expense', 'other_expense', 'asset'];

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:192',
            'reference' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'account_type' => 'nullable|in:'.implode(',', self::ACCOUNT_TYPES),
            'expense_group' => 'nullable|string|max:120',
            'expense_group_es' => 'nullable|string|max:120',
            'typical_account' => 'nullable|string|max:192',
            'period' => 'nullable|string|max:120',
            'period_es' => 'nullable|string|max:120',
            'approximate_amount' => 'nullable|string|max:192',
            'is_common' => 'nullable|boolean',
            'coa_code' => 'nullable|string|max:64',
        ];
    }

    private function fields(Request $request, int $companyId): array
    {
        $v = $request->validate($this->rules());
        // The account must exist in the company's chart.
        if (! empty($v['coa_code'])) {
            abort_unless(DB::table('acc_chart_of_accounts')->where('company_id', $companyId)->where('code', $v['coa_code'])->exists(), 422, 'That account is not in this company\'s chart of accounts.');
        }

        return [
            'name' => $v['name'],
            'reference' => $v['reference'] ?? null,
            'description' => $v['description'] ?? null,
            'account_type' => $v['account_type'] ?? null,
            'expense_group' => $v['expense_group'] ?? null,
            'expense_group_es' => $v['expense_group_es'] ?? null,
            'typical_account' => $v['typical_account'] ?? null,
            'period' => $v['period'] ?? null,
            'period_es' => $v['period_es'] ?? null,
            'approximate_amount' => $v['approximate_amount'] ?? null,
            'is_common' => (bool) ($v['is_common'] ?? false),
            'coa_code' => $v['coa_code'] ?? null,
        ];
    }

    /** A category of the acting company (404 for another company's). */
    private function findOwn(Request $request, $id): ExpenseCategory
    {
        return ExpenseCategory::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->findOrFail($id);
    }

    // -------------- Get All Expense Categories ---------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ExpenseCategory::class);
        $user = Auth::user();
        $view_records = $user->hasRecordView();
        $cid = $this->resolveActingCompanyId($request);
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = in_array($request->SortField, ['name', 'reference', 'expense_group', 'account_type', 'period', 'is_common', 'coa_code', 'sort_order', 'id'], true) ? $request->SortField : 'sort_order';
        $dir = strtolower((string) $request->SortType) === 'desc' ? 'desc' : 'asc';

        $ExpenseCategory = ExpenseCategory::where('deleted_at', '=', null)
            ->where('company_id', $cid)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    // Own categories + the company's default set.
                    return $query->where('user_id', '=', Auth::user()->id)->orWhere('sort_order', '>', 0);
                }
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    $s = $request->search;

                    return $query->where('name', 'LIKE', "%{$s}%")
                        ->orWhere('reference', 'LIKE', "%{$s}%")
                        ->orWhere('expense_group', 'LIKE', "%{$s}%")
                        ->orWhere('expense_group_es', 'LIKE', "%{$s}%")
                        ->orWhere('description', 'LIKE', "%{$s}%");
                });
            })
            ->when($request->filled('expense_group'), fn ($q) => $q->where('expense_group', $request->expense_group));

        $totalRows = $ExpenseCategory->count();
        if ($perPage == '-1' || ! $perPage) {
            $perPage = max(1, $totalRows);
        }
        $ExpenseCategory = $ExpenseCategory->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->orderBy('id')
            ->get();

        return response()->json([
            'Expenses_category' => $ExpenseCategory,
            'totalRows' => $totalRows,
            // For the form: the company's expense/asset accounts (Account
            // picker) and the groups already in use.
            'accounts' => DB::table('acc_chart_of_accounts')->where('company_id', $cid)->where('level', '>', 0)
                ->whereIn('type', ['expense', 'asset'])->where('is_active', 1)->orderBy('code')
                ->get(['code', 'name', 'name_es', 'type']),
            'groups' => DB::table('expense_categories')->where('company_id', $cid)->whereNull('deleted_at')
                ->whereNotNull('expense_group')->select('expense_group', 'expense_group_es')->distinct()->orderBy('expense_group')->get(),
        ]);
    }

    // -------------- Store New Category ---------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', ExpenseCategory::class);
        $cid = $this->resolveActingCompanyId($request);

        $fields = $this->fields($request, $cid);
        // The name as a translation key, so it can be translated in Settings.
        TranslationKeys::ensure($fields['name']);
        ExpenseCategory::create($fields + [
            'company_id' => $cid,
            'user_id' => Auth::user()->id,
        ]);

        return response()->json(['success' => true], 200);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // -------------- Update Category ---------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ExpenseCategory::class);
        $user = Auth::user();
        $view_records = $user->hasRecordView();
        $ExpenseCategory = $this->findOwn($request, $id);

        if (! $view_records) {
            $this->authorizeForUser($request->user('api'), 'check_record', $ExpenseCategory);
        }

        $fields = $this->fields($request, (int) $ExpenseCategory->company_id);
        TranslationKeys::ensure($fields['name']);
        $ExpenseCategory->update($fields);

        return response()->json(['success' => true], 200);
    }

    // -------------- Delete Category ---------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ExpenseCategory::class);
        $user = Auth::user();
        $view_records = $user->hasRecordView();
        $ExpenseCategory = $this->findOwn($request, $id);

        if (! $view_records) {
            $this->authorizeForUser($request->user('api'), 'check_record', $ExpenseCategory);
        }
        $ExpenseCategory->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true], 200);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ExpenseCategory::class);
        $selectedIds = $request->selectedIds;
        $user = Auth::user();
        $view_records = $user->hasRecordView();

        foreach ($selectedIds as $category_id) {
            $ExpenseCategory = $this->findOwn($request, $category_id);

            if (! $view_records) {
                $this->authorizeForUser($request->user('api'), 'check_record', $ExpenseCategory);
            }
            $ExpenseCategory->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true], 200);
    }
}
