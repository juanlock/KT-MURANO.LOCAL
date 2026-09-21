<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\StorePickupBranch;
use App\Models\StoreSetting;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Branches offered for order collection. The list is always the store's
 * warehouses; a row here just adds the shopper-facing details and the
 * on-off switch, so the admin edits it as one grid.
 */
class PickupBranchesController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $storeIds = StoreSetting::storeWarehouseIds();
        $rows = StorePickupBranch::whereIn('warehouse_id', $storeIds)->get()->keyBy('warehouse_id');

        $branches = Warehouse::whereIn('id', $storeIds)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(function (Warehouse $w) use ($rows) {
                $row = $rows->get($w->id);

                return [
                    'warehouse_id' => (int) $w->id,
                    'name' => $w->name,
                    'city' => $w->city,
                    'country' => $w->country,
                    'active' => $row ? (bool) $row->active : false,
                    'address' => $row->address ?? null,
                    'hours' => $row->hours ?? null,
                    'contact' => $row->contact ?? $w->mobile,
                    'notes' => $row->notes ?? null,
                    'sort_order' => (int) ($row->sort_order ?? 0),
                ];
            })->values();

        return response()->json(['branches' => $branches]);
    }

    /** Bulk upsert: the grid posts every row it rendered. */
    public function save(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $request->validate([
            'branches' => ['present', 'array'],
            'branches.*.warehouse_id' => ['required', 'integer'],
            'branches.*.active' => ['nullable', 'boolean'],
            'branches.*.address' => ['nullable', 'string', 'max:255'],
            'branches.*.hours' => ['nullable', 'string', 'max:191'],
            'branches.*.contact' => ['nullable', 'string', 'max:100'],
            'branches.*.notes' => ['nullable', 'string', 'max:1000'],
            'branches.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $storeIds = StoreSetting::storeWarehouseIds();

        DB::transaction(function () use ($data, $storeIds) {
            foreach ($data['branches'] as $b) {
                $wid = (int) $b['warehouse_id'];
                if (! in_array($wid, $storeIds, true)) {
                    continue;
                }

                // withTrashed: warehouse_id is unique, so a previously
                // removed row must be revived rather than re-inserted.
                $row = StorePickupBranch::withTrashed()->firstOrNew(['warehouse_id' => $wid]);
                $row->fill([
                    'active' => (bool) ($b['active'] ?? false),
                    'address' => $b['address'] ?? null,
                    'hours' => $b['hours'] ?? null,
                    'contact' => $b['contact'] ?? null,
                    'notes' => $b['notes'] ?? null,
                    'sort_order' => (int) ($b['sort_order'] ?? 0),
                ]);
                $row->deleted_at = null;
                $row->save();
            }
        });

        return response()->json(['success' => true]);
    }
}
