<?php

namespace App\Http\Controllers;

use App\Models\Tray;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TraysController extends Controller
{
    // ------------ GET ALL Trays -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Tray::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $trays = Tray::where('deleted_at', '=', null)
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

        $tray = new Tray;
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

        Tray::whereId($id)->update([
            'name' => $request['name'],
            'description' => $request['description'],
        ]);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Tray -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Tray::class);

        Tray::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Tray::class);

        $selectedIds = $request->selectedIds;
        foreach ($selectedIds as $tray_id) {
            Tray::whereId($tray_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);
    }
}
