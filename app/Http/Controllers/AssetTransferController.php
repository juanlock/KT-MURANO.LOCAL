<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetTransfer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Moves between warehouses.
 *
 * A transfer is a fact about the past, so the rows are append-only: there is
 * no update. Correcting a mistake means deleting the row, which also rewinds
 * the asset to where it came from.
 */
class AssetTransferController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', AssetTransfer::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = strtolower((string) ($request->SortType ?: 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $sortable = [
            'id' => 'asset_transfers.id',
            'transfer_date' => 'asset_transfers.transfer_date',
            'asset_name' => 'assets.name',
            'asset_tag' => 'assets.tag',
            'from_warehouse_name' => 'from_wh.name',
            'to_warehouse_name' => 'to_wh.name',
        ];
        $order = $sortable[$order] ?? 'asset_transfers.id';

        $query = AssetTransfer::leftJoin('assets', 'assets.id', '=', 'asset_transfers.asset_id')
            ->tap(fn ($q) => $this->scopeToWarehousesEitherEnd(
                $q, 'asset_transfers.from_warehouse_id', 'asset_transfers.to_warehouse_id'))
            ->leftJoin('warehouses as from_wh', 'from_wh.id', '=', 'asset_transfers.from_warehouse_id')
            ->leftJoin('warehouses as to_wh', 'to_wh.id', '=', 'asset_transfers.to_warehouse_id')
            ->whereNull('asset_transfers.deleted_at')
            ->select(
                'asset_transfers.*',
                'assets.name as asset_name',
                'assets.tag as asset_tag',
                'from_wh.name as from_warehouse_name',
                'to_wh.name as to_warehouse_name'
            )
            ->when($request->filled('asset_id'), fn ($q) => $q->where('asset_transfers.asset_id', $request->asset_id))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('asset_transfers.from_warehouse_id', $request->warehouse_id)
                    ->orWhere('asset_transfers.to_warehouse_id', $request->warehouse_id);
            }))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('asset_transfers.transfer_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('asset_transfers.transfer_date', '<=', $request->to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('assets.name', 'LIKE', "%{$s}%")
                        ->orWhere('assets.tag', 'LIKE', "%{$s}%")
                        ->orWhere('from_wh.name', 'LIKE', "%{$s}%")
                        ->orWhere('to_wh.name', 'LIKE', "%{$s}%")
                        ->orWhere('asset_transfers.reason', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $rows = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id' => $row->id,
                'asset_id' => $row->asset_id,
                'asset_name' => $row->asset_name,
                'asset_tag' => $row->asset_tag,
                'from_warehouse_id' => $row->from_warehouse_id,
                'from_warehouse_name' => $row->from_warehouse_name,
                'to_warehouse_id' => $row->to_warehouse_id,
                'to_warehouse_name' => $row->to_warehouse_name,
                'transfer_date' => $row->transfer_date ? $row->transfer_date->format('Y-m-d') : null,
                'reason' => $row->reason,
                'notes' => $row->notes,
            ];
        }

        return response()->json([
            'transfers' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', AssetTransfer::class);

        $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'to_warehouse_id' => 'required|exists:warehouses,id',
            'transfer_date' => 'required|date',
        ]);

        // Moving an asset is a two-warehouse action: the destination must be
        // one of the caller's, and so must the asset's current home.
        $this->abortIfWarehouseDenied($request->to_warehouse_id);

        return DB::transaction(function () use ($request) {
            $asset = Asset::whereNull('deleted_at')->lockForUpdate()->find($request->asset_id);
            if (! $asset) {
                return response()->json(['success' => false, 'message' => 'Asset not found.'], 404);
            }
            if ($asset->warehouse_id) {
                $this->abortIfWarehouseDenied($asset->warehouse_id);
            }
            if ((int) $asset->warehouse_id === (int) $request->to_warehouse_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'The asset is already in that warehouse.',
                ], 422);
            }

            AssetTransfer::create([
                'asset_id' => $asset->id,
                // Taken from the asset, never from the request: the client's
                // idea of where the asset is may be a stale page.
                'from_warehouse_id' => $asset->warehouse_id,
                'to_warehouse_id' => $request->to_warehouse_id,
                'transfer_date' => $request->transfer_date,
                'reason' => $request->reason,
                'notes' => $request->notes,
                'created_by' => $request->user('api')->id ?? null,
            ]);

            $asset->update(['warehouse_id' => $request->to_warehouse_id]);

            return response()->json(['success' => true], 200);
        });
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', AssetTransfer::class);

        return DB::transaction(function () use ($id) {
            $transfer = AssetTransfer::whereNull('deleted_at')->findOrFail($id);
            $this->abortIfTransferDenied($transfer->from_warehouse_id, $transfer->to_warehouse_id);
            $this->undo($transfer);

            return response()->json(['success' => true], 200);
        });
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', AssetTransfer::class);

        DB::transaction(function () use ($request) {
            // One at a time, newest first, so undoing a run of moves walks the
            // asset back along the chain instead of stranding it.
            $transfers = AssetTransfer::whereNull('deleted_at')
                ->whereIn('id', $request->selectedIds ?: [])
                ->tap(fn ($q) => $this->scopeToWarehousesEitherEnd(
                    $q, 'from_warehouse_id', 'to_warehouse_id'))
                ->orderBy('transfer_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            foreach ($transfers as $transfer) {
                $this->undo($transfer);
            }
        });

        return response()->json(['success' => true], 200);
    }

    /**
     * Delete one transfer, returning the asset to where it came from — but only
     * if this is still its most recent move. Undoing an older one would teleport
     * the asset out of where it currently is.
     */
    private function undo(AssetTransfer $transfer): void
    {
        $asset = Asset::whereNull('deleted_at')->lockForUpdate()->find($transfer->asset_id);

        if ($asset) {
            $latest = AssetTransfer::where('asset_id', $transfer->asset_id)
                ->whereNull('deleted_at')
                ->orderBy('transfer_date', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if ($latest && (int) $latest->id === (int) $transfer->id) {
                $asset->update(['warehouse_id' => $transfer->from_warehouse_id]);
            }
        }

        $transfer->update(['deleted_at' => Carbon::now()]);
    }
}
