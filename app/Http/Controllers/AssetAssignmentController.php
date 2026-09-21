<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetAssignment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Custody of assets: who has what, since when, and when it is due back.
 *
 * The asset's own `assigned_to_id` is a cache of "the open assignment", and
 * this controller is the only thing that writes it. Every path that opens or
 * closes an assignment updates both inside one transaction, so the register
 * and the asset row can never drift apart.
 */
class AssetAssignmentController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', AssetAssignment::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = strtolower((string) ($request->SortType ?: 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $sortable = [
            'id' => 'asset_assignments.id',
            'assigned_on' => 'asset_assignments.assigned_on',
            'due_back_on' => 'asset_assignments.due_back_on',
            'returned_on' => 'asset_assignments.returned_on',
            'status' => 'asset_assignments.status',
            'asset_name' => 'assets.name',
            'asset_tag' => 'assets.tag',
            'user_name' => 'users.firstname',
        ];
        $order = $sortable[$order] ?? 'asset_assignments.id';

        $query = AssetAssignment::leftJoin('assets', 'assets.id', '=', 'asset_assignments.asset_id')
            ->leftJoin('users', 'users.id', '=', 'asset_assignments.user_id')
            ->whereNull('asset_assignments.deleted_at')
            ->select(
                'asset_assignments.*',
                'assets.name as asset_name',
                'assets.tag as asset_tag',
                'users.firstname as user_firstname',
                'users.lastname as user_lastname'
            )
            ->when($request->filled('asset_id'), fn ($q) => $q->where('asset_assignments.asset_id', $request->asset_id))
            ->when($request->filled('user_id'), fn ($q) => $q->where('asset_assignments.user_id', $request->user_id))
            ->when($request->filled('status') && $request->status !== 'overdue',
                fn ($q) => $q->where('asset_assignments.status', $request->status))
            // "Overdue" is a view of open rows, not a stored status.
            ->when($request->status === 'overdue', fn ($q) => $q->where('asset_assignments.status', 'assigned')
                ->whereNotNull('asset_assignments.due_back_on')
                ->whereDate('asset_assignments.due_back_on', '<', Carbon::today()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('assets.name', 'LIKE', "%{$s}%")
                        ->orWhere('assets.tag', 'LIKE', "%{$s}%")
                        ->orWhere('users.firstname', 'LIKE', "%{$s}%")
                        ->orWhere('users.lastname', 'LIKE', "%{$s}%")
                        ->orWhere('asset_assignments.notes', 'LIKE', "%{$s}%");
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
                'user_id' => $row->user_id,
                'user_name' => trim(($row->user_firstname ?: '').' '.($row->user_lastname ?: '')),
                'assigned_on' => $row->assigned_on ? $row->assigned_on->format('Y-m-d') : null,
                'due_back_on' => $row->due_back_on ? $row->due_back_on->format('Y-m-d') : null,
                'returned_on' => $row->returned_on ? $row->returned_on->format('Y-m-d') : null,
                'condition_out' => $row->condition_out,
                'condition_in' => $row->condition_in,
                'notes' => $row->notes,
                'status' => $row->status,
                'is_overdue' => $row->isOverdue(),
                'days_held' => $row->daysHeld(),
            ];
        }

        return response()->json([
            'assignments' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', AssetAssignment::class);

        $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'user_id' => 'required|exists:users,id',
            'assigned_on' => 'required|date',
            'due_back_on' => 'nullable|date|after_or_equal:assigned_on',
        ]);

        return DB::transaction(function () use ($request) {
            // Lock the asset so two people cannot check the same one out at once.
            $asset = Asset::whereNull('deleted_at')->lockForUpdate()->find($request->asset_id);
            if (! $asset) {
                return response()->json(['success' => false, 'message' => 'Asset not found.'], 404);
            }
            if ($asset->disposal_date) {
                return response()->json([
                    'success' => false,
                    'message' => 'This asset has been disposed of and cannot be assigned.',
                ], 422);
            }

            $open = AssetAssignment::where('asset_id', $asset->id)
                ->whereNull('deleted_at')
                ->where('status', 'assigned')
                ->first();
            if ($open) {
                return response()->json([
                    'success' => false,
                    'message' => 'This asset is already assigned. Check it in before assigning it again.',
                ], 422);
            }

            AssetAssignment::create([
                'asset_id' => $asset->id,
                'user_id' => $request->user_id,
                'assigned_on' => $request->assigned_on,
                'due_back_on' => $request->due_back_on,
                'condition_out' => $request->condition_out,
                'notes' => $request->notes,
                'status' => 'assigned',
                'created_by' => $request->user('api')->id ?? null,
            ]);

            $asset->update(['assigned_to_id' => $request->user_id]);

            return response()->json(['success' => true], 200);
        });
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', AssetAssignment::class);

        $assignment = AssetAssignment::whereNull('deleted_at')->findOrFail($id);

        $request->validate([
            'assigned_on' => 'required|date',
            'due_back_on' => 'nullable|date|after_or_equal:assigned_on',
        ]);

        // Only the paperwork is editable here. Which asset it is and who holds
        // it change through assign / check-in, so the two sides stay in step.
        $assignment->update([
            'assigned_on' => $request->assigned_on,
            'due_back_on' => $request->due_back_on,
            'condition_out' => $request->condition_out,
            'notes' => $request->notes,
        ]);

        return response()->json(['success' => true], 200);
    }

    /** Hand the asset back: closes the spell and frees the asset. */
    public function checkin(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', AssetAssignment::class);

        $request->validate([
            'returned_on' => 'required|date',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $assignment = AssetAssignment::whereNull('deleted_at')->lockForUpdate()->findOrFail($id);

            if ($assignment->status === 'returned') {
                return response()->json([
                    'success' => false,
                    'message' => 'This assignment has already been checked in.',
                ], 422);
            }
            if (Carbon::parse($request->returned_on)->lt(Carbon::parse($assignment->assigned_on))) {
                return response()->json([
                    'success' => false,
                    'message' => 'The return date cannot be before the assignment date.',
                ], 422);
            }

            $assignment->update([
                'returned_on' => $request->returned_on,
                'condition_in' => $request->condition_in,
                'status' => 'returned',
            ]);

            $asset = Asset::find($assignment->asset_id);
            if ($asset && (int) $asset->assigned_to_id === (int) $assignment->user_id) {
                $asset->update(['assigned_to_id' => null]);
            }

            return response()->json(['success' => true], 200);
        });
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', AssetAssignment::class);

        return DB::transaction(function () use ($id) {
            $assignment = AssetAssignment::whereNull('deleted_at')->findOrFail($id);

            // Deleting an open spell has to release the asset too, or it stays
            // stuck against a person with no record explaining why.
            if ($assignment->status === 'assigned') {
                $asset = Asset::find($assignment->asset_id);
                if ($asset && (int) $asset->assigned_to_id === (int) $assignment->user_id) {
                    $asset->update(['assigned_to_id' => null]);
                }
            }

            $assignment->update(['deleted_at' => Carbon::now()]);

            return response()->json(['success' => true], 200);
        });
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', AssetAssignment::class);

        DB::transaction(function () use ($request) {
            foreach ($request->selectedIds ?: [] as $id) {
                $assignment = AssetAssignment::whereNull('deleted_at')->find($id);
                if (! $assignment) {
                    continue;
                }
                if ($assignment->status === 'assigned') {
                    $asset = Asset::find($assignment->asset_id);
                    if ($asset && (int) $asset->assigned_to_id === (int) $assignment->user_id) {
                        $asset->update(['assigned_to_id' => null]);
                    }
                }
                $assignment->update(['deleted_at' => Carbon::now()]);
            }
        });

        return response()->json(['success' => true], 200);
    }
}
