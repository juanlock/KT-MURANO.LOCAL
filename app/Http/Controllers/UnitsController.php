<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Product;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UnitsController extends BaseController
{
    use ScopesToCompany;

    // -------------- show All Units -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Unit::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $data = [];

        // superadmin/AdminSystem: every company (2026-10-21) -- null = no company filter.
        $Units = $this->scopeListToCompany(Unit::where('deleted_at', '=', null), $request)

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('ShortName', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $Units->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $Units = $Units->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Units as $unit) {
            $unit_data['id'] = $unit->id;
            $unit_data['name'] = $unit->name;
            $unit_data['ShortName'] = $unit->ShortName;
            $unit_data['operator'] = $unit->operator;
            $unit_data['operator_value'] = $unit->operator_value;

            if ($unit->base_unit !== null) {
                $unit_base = Unit::where('id', $unit->base_unit)->where('deleted_at', null)->first();
                $unit_data['base_unit_name'] = $unit_base['name'];
                $unit_data['base_unit'] = $unit_base['id'];
            } else {
                $unit_data['base_unit_name'] = '';
                $unit_data['base_unit'] = '';
            }

            $unit_data['company_id'] = $unit->company_id;
            $data[] = $unit_data;
        }
        // superadmin/AdminSystem: every company (2026-10-21) -- each row says whose it is.
        $data = \App\Support\CompanyNames::attach($data);

        $Units_base = Unit::where('base_unit', null)
            ->where('deleted_at', null)
            ->orderBy('id', 'DESC')
            ->get(['id', 'name']);

        return response()->json([
            'Units' => $data,
            'Units_base' => $Units_base,
            'totalRows' => $totalRows,
        ]);

    }

    // -------------- STORE NEW UNIT -----------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Unit::class);

        request()->validate([
            'name' => 'required',
            'ShortName' => 'required',
        ]);

        if ($request->base_unit == '') {
            $operator = '*';
            $operator_value = 1;
        } else {
            $operator = $request->operator;
            $operator_value = $request->operator_value;
        }

        $unit = Unit::create([
            'company_id' => $this->resolveActingCompanyId($request),
            'name' => $request['name'],
            'ShortName' => $request['ShortName'],
            'base_unit' => $request['base_unit'],
            'operator' => $operator,
            'operator_value' => $operator_value,
        ]);

        // `unit` lets callers (product form quick-create) select it right away.
        return response()->json(['success' => true, 'unit' => $unit]);

    }

    // -------------- UPDATE UNIT -----------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Unit::class);
        abort_unless(
            optional(Unit::find($id))->company_id === $this->resolveActingCompanyId($request),
            404
        );

        request()->validate([
            'name' => 'required',
            'ShortName' => 'required',
        ]);

        if ($request->base_unit == '' || $request->base_unit == $id) {
            $operator = '*';
            $operator_value = 1;
            $base_unit = null;
        } else {
            $operator = $request->operator;
            $operator_value = $request->operator_value;
            $base_unit = $request['base_unit'];
        }

        Unit::whereId($id)->update([
            'name' => $request['name'],
            'ShortName' => $request['ShortName'],
            'base_unit' => $base_unit,
            'operator' => $operator,
            'operator_value' => $operator_value,
        ]);

        return response()->json(['success' => true]);

    }

    // -------------- REMOVE UNIT -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Unit::class);
        abort_unless(
            optional(Unit::find($id))->company_id === $this->resolveActingCompanyId($request),
            404
        );

        $Sub_Unit_exist = Unit::where('base_unit', $id)->where('deleted_at', null)->exists();
        if (! $Sub_Unit_exist) {
            Unit::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            return response()->json(['success' => true]);
        } else {
            // 200 here would read as success to the caller and the UI would
            // claim the unit was deleted while it is still in the list.
            return response()->json([
                'success' => false,
                'message' => __('This unit cannot be deleted while sub-units still use it.'),
            ], 422);
        }

    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Unit::class);

        $request->validate([
            'selectedIds' => 'required|array',
            'selectedIds.*' => 'integer',
        ]);

        $selectedIds = $request->selectedIds;
        $companyId = $this->resolveActingCompanyId($request);

        // Deleting a base unit together with all of its sub-units is fine, so
        // only sub-units left OUTSIDE the selection block the delete.
        // Scoped to the acting company -- otherwise another company's
        // sub-unit sharing a base_unit id could block/leak into this delete.
        $blocking = Unit::whereIn('base_unit', $selectedIds)
            ->whereNotIn('id', $selectedIds)
            ->where('deleted_at', null)
            ->where('company_id', $companyId)
            ->pluck('base_unit')
            ->unique();

        if ($blocking->isNotEmpty()) {
            $names = Unit::whereIn('id', $blocking)->where('company_id', $companyId)->pluck('name')->implode(', ');

            // Nothing is deleted, so the table stays in step with the message.
            return response()->json([
                'success' => false,
                'message' => "These units cannot be deleted while sub-units still use them: {$names}",
            ], 422);
        }

        Unit::whereIn('id', $selectedIds)
            ->where('deleted_at', null)
            ->where('company_id', $companyId)
            ->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    // -------------- Get Units SubBase ------------------\\

    public function Get_Units_SubBase(request $request)
    {
        $units = Unit::where('deleted_at', null)->where(function ($query) use ($request) {
            return $query->when($request->filled('id'), function ($query) use ($request) {
                return $query->where('id', $request->id)
                    ->orWhere('base_unit', $request->id);
            });
        })->get();

        return response()->json($units);
    }

    // -------------- Get Sales Units ------------------\\

    public function Get_sales_units(request $request)
    {

        $product_unit_id = Product::with('unit')->where(function ($query) use ($request) {
            return $query->when($request->filled('id'), function ($query) use ($request) {
                return $query->where('id', $request->id);
            });
        })->first();

        $units = Unit::where('base_unit', $product_unit_id->unit_id)
            ->orWhere('id', $product_unit_id->unit_id)
            ->get();

        return response()->json($units);
    }
}
