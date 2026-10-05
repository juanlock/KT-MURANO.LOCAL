<?php

namespace App\Http\Controllers\AccountingV2;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountingV2\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * NEW FEATURE - SAFE ADDITION
 *
 * Per company (multicompany rule): each company has its own chart
 * (company_id; default set from App\Support\DefaultAccounting). Accounts
 * with no company are the older shared ones still used by existing journal
 * entries -- listed for everyone (marked shared), editable by a SuperAdmin
 * only. A SuperAdmin works on the company picked in the header.
 */
class ChartOfAccountsController extends Controller
{
    use ScopesToCompany;

    /** Accounts the acting company may see: its own + the shared ones. */
    private function scoped(Request $request)
    {
        $cid = $this->resolveActingCompanyId($request);

        return ChartOfAccount::query()->where(fn ($q) => $q->where('company_id', $cid)->orWhereNull('company_id'));
    }

    /** An account the acting user may change: own company's; shared ones for a SuperAdmin. */
    private function findEditable(Request $request, int $id): ChartOfAccount
    {
        $coa = $this->scoped($request)->findOrFail($id);
        abort_if($coa->company_id === null && $request->user('api')->type !== 'SuperAdmin', 403, 'Shared accounts can only be changed by a SuperAdmin.');

        return $coa;
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        if (! Schema::hasTable('acc_chart_of_accounts')) {
            return response()->json(['data' => [], 'totalRows' => 0]);
        }
        $query = $this->scoped($request);
        // Filters
        if ($request->filled('type')) {
            $query->where('type', $request->get('type'));
        }
        if ($request->has('active')) {
            $query->where('is_active', (int) $request->get('active') === 1);
        }
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")->orWhere('name', 'LIKE', "%{$search}%")->orWhere('name_es', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        $perPage = (int) ($request->get('limit') ?: 10);
        if ($perPage === -1 || $perPage >= 100000) {
            $perPage = $totalRows > 0 ? $totalRows : 10;
        }
        $page = max(1, (int) ($request->get('page') ?: 1));
        $offSet = ($page * $perPage) - $perPage;
        $order = in_array($request->get('SortField'), ['code', 'name', 'name_es', 'type', 'is_active'], true) ? $request->get('SortField') : 'code';
        $dir = strtolower((string) $request->get('SortType', 'asc')) === 'desc' ? 'desc' : 'asc';

        // The company's own accounts first, then the shared ones.
        $rows = $query->orderByRaw('company_id IS NULL')->orderBy($order, $dir)->offset($offSet)->limit($perPage)->get();

        return response()->json([
            'data' => $rows,
            'totalRows' => $totalRows,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        $cid = $this->resolveActingCompanyId($request);
        $this->validate($request, [
            'code' => 'required|max:64',
            'name' => 'required|max:192',
            'name_es' => 'nullable|max:192',
            'type' => 'required|in:asset,liability,equity,income,expense',
        ]);
        abort_if(ChartOfAccount::where('company_id', $cid)->where('code', $request->get('code'))->exists(), 422, 'This company already has an account with that code.');
        $coa = ChartOfAccount::create([
            'company_id' => $cid,
            'account_id' => $request->get('account_id'),
            'code' => $request->get('code'),
            'name' => $request->get('name'),
            'name_es' => $request->get('name_es'),
            'type' => $request->get('type'),
            'parent_id' => $this->validParent($request, $cid),
            'level' => (int) $request->get('level', $request->filled('parent_id') ? 1 : 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($coa, 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        $coa = $this->findEditable($request, $id);
        $this->validate($request, [
            'code' => 'sometimes|max:64',
            'name' => 'sometimes|max:192',
            'name_es' => 'nullable|max:192',
            'type' => 'sometimes|in:asset,liability,equity,income,expense',
        ]);
        if ($request->filled('code') && $request->get('code') !== $coa->code) {
            abort_if(ChartOfAccount::where('company_id', $coa->company_id)->where('code', $request->get('code'))->whereKeyNot($coa->id)->exists(), 422, 'This company already has an account with that code.');
        }
        $data = $request->only(['account_id', 'code', 'name', 'name_es', 'type', 'level', 'is_active']);
        if ($request->exists('parent_id')) {
            $data['parent_id'] = $this->validParent($request, $coa->company_id, $coa->id);
        }
        $coa->update($data);

        return response()->json($coa);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'chart_of_accounts', Account::class);

        $coa = $this->findEditable($request, $id);
        $coa->delete();

        return response()->json(['success' => true]);
    }

    /** parent_id only when it's an account of the same company (not itself). */
    private function validParent(Request $request, ?int $companyId, ?int $selfId = null): ?int
    {
        $pid = (int) $request->get('parent_id');
        if (! $pid || $pid === $selfId) {
            return null;
        }

        return ChartOfAccount::whereKey($pid)->where('company_id', $companyId)->exists() ? $pid : null;
    }
}
