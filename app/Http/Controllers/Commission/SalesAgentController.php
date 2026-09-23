<?php

namespace App\Http\Controllers\Commission;

use App\Http\Controllers\BaseController;
use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\CommissionProgram;
use App\Models\SalesAgent;
use App\Services\LinkedUserService;
use Illuminate\Http\Request;

class SalesAgentController extends BaseController
{
    use ScopesToCompany;

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', CommissionProgram::class);
        $perPage = $request->limit ?? 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?? 'id';
        $dir = $request->SortType ?? 'desc';

        $companyId = $this->resolveActingCompanyId($request);
        $query = SalesAgent::with('user:id,firstname,lastname,email')
            ->withCount('saleCommissions')
            ->where('company_id', $companyId)
            ->where('deleted_at', null);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }

        $totalRows = $query->count();
        if ($perPage === '-1') {
            $perPage = $totalRows;
        }
        $agents = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        return $this->sendResponse([
            'agents' => $agents,
            'totalRows' => $totalRows,
        ], '');
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', CommissionProgram::class);
        $request->validate([
            'name' => 'required|string|max:192',
            'code' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:192',
            'phone' => 'nullable|string|max:64',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $companyId = $this->resolveActingCompanyId($request);

        // Every sales agent gets a matching users row (type=sales_agent,
        // role "Sales Agent" -- Sales + Commissions only), same as
        // Employees/Clients via LinkedUserService. sales_agents.user_id is
        // still a nullable/unique FK for it, but nothing sets it manually
        // anymore -- this is the one place that provisions it.
        $userId = LinkedUserService::provision(
            $companyId, 'sales_agent', 'Sales Agent', $request->name, $request->email, $request->phone
        );

        $agent = SalesAgent::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'code' => $request->code,
            'email' => $request->email,
            'phone' => $request->phone,
            'user_id' => $userId,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $request->notes,
        ]);

        return $this->sendResponse($agent, __('Created successfully'));
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', CommissionProgram::class);
        $agent = SalesAgent::with('user:id,firstname,lastname,email')->findOrFail($id);
        abort_unless((int) $agent->company_id === $this->resolveActingCompanyId($request), 404);
        return $this->sendResponse($agent, '');
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CommissionProgram::class);
        $request->validate([
            'name' => 'required|string|max:192',
            'code' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:192',
            'phone' => 'nullable|string|max:64',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $agent = SalesAgent::findOrFail($id);
        abort_unless((int) $agent->company_id === $this->resolveActingCompanyId($request), 404);
        // user_id is not editable here -- it's set once at creation by
        // LinkedUserService::provision() and never manually reassigned.
        $agent->update([
            'name' => $request->name,
            'code' => $request->code,
            'email' => $request->email,
            'phone' => $request->phone,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $request->notes,
        ]);

        return $this->sendResponse($agent->fresh(), __('Updated successfully'));
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', CommissionProgram::class);
        $agent = SalesAgent::findOrFail($id);
        abort_unless((int) $agent->company_id === $this->resolveActingCompanyId($request), 404);
        $agent->delete();
        return $this->sendResponse(null, __('Deleted successfully'));
    }

    /** List agents for dropdowns (id, name, code). */
    public function listForSelect(Request $request)
    {
        $agents = SalesAgent::where('company_id', $this->resolveActingCompanyId($request))
            ->where('deleted_at', null)->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        return $this->sendResponse($agents, '');
    }
}
