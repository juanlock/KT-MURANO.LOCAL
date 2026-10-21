<?php

namespace App\Http\Controllers;

use App\Models\Tray;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TraysController extends Controller
{
    // Multicompany: stations (trays) belong to a company (trays.company_id).
    // An Admin sees / edits their company's only; a SuperAdmin the company
    // picked in the header (all of them when none is picked).
    use \App\Http\Controllers\Concerns\ScopesReportsToCompany;

    /** A station the user's company may change (404 otherwise). */
    private function ownTray($id): Tray
    {
        return $this->rc(Tray::query(), 'trays.company_id')->whereNull('deleted_at')->findOrFail($id);
    }

    // ------------ GET ALL Trays -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Tray::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $trays = $this->rc(Tray::query(), 'trays.company_id')->where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $trays->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $trays = $trays->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();
        // superadmin/AdminSystem: every company (2026-10-21) -- each row says whose it is.
        $trays = \App\Support\CompanyNames::attach($trays);

        return response()->json([
            'trays' => $trays,
            'totalRows' => $totalRows,
        ]);
    }

    // ---------------- STORE NEW Tray -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Tray::class);

        request()->validate([
            'name' => 'required',
        ]);
        $companyId = $this->resolveActingCompanyId($request);
        abort_unless($companyId, 422, 'Pick a company in the header first.');

        $tray = new Tray;
        $tray->company_id = $companyId;
        $tray->name = $request['name'];
        $tray->description = $request['description'];
        $tray->save();

        return response()->json([
            'success' => true,
            'tray' => $tray,
        ], 201);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //
    }

    // ---------------- UPDATE Tray -------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Tray::class);

        request()->validate([
            'name' => 'required',
        ]);

        $this->ownTray($id)->update([
            'name' => $request['name'],
            'description' => $request['description'],
        ]);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Tray -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Tray::class);

        Tray::whereKey($this->ownTray($id)->id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Tray::class);

        $selectedIds = $request->selectedIds;
        $this->rc(Tray::query(), 'trays.company_id')->whereIn('id', (array) $selectedIds)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }
}
