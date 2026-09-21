<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetMaintenance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Servicing, repairs and inspections.
 *
 * Moving a job to "in progress" takes the asset off the floor, and completing
 * it puts the asset back — so the status you see on the asset list is a
 * consequence of the work log rather than something someone remembered to set.
 */
class AssetMaintenanceController extends BaseController
{
    private const TYPES = ['service', 'repair', 'inspection', 'calibration', 'upgrade', 'other'];

    private const STATUSES = ['scheduled', 'in_progress', 'completed', 'cancelled'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', AssetMaintenance::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = strtolower((string) ($request->SortType ?: 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $sortable = [
            'id' => 'asset_maintenances.id',
            'title' => 'asset_maintenances.title',
            'type' => 'asset_maintenances.type',
            'status' => 'asset_maintenances.status',
            'cost' => 'asset_maintenances.cost',
            'scheduled_date' => 'asset_maintenances.scheduled_date',
            'completed_date' => 'asset_maintenances.completed_date',
            'next_due_date' => 'asset_maintenances.next_due_date',
            'asset_name' => 'assets.name',
            'asset_tag' => 'assets.tag',
        ];
        $order = $sortable[$order] ?? 'asset_maintenances.id';

        $query = AssetMaintenance::leftJoin('assets', 'assets.id', '=', 'asset_maintenances.asset_id')
            ->whereNull('asset_maintenances.deleted_at')
            ->select('asset_maintenances.*', 'assets.name as asset_name', 'assets.tag as asset_tag')
            ->when($request->filled('asset_id'), fn ($q) => $q->where('asset_maintenances.asset_id', $request->asset_id))
            ->when($request->filled('type'), fn ($q) => $q->where('asset_maintenances.type', $request->type))
            ->when($request->filled('status') && $request->status !== 'overdue',
                fn ($q) => $q->where('asset_maintenances.status', $request->status))
            ->when($request->status === 'overdue', fn ($q) => $q->whereIn('asset_maintenances.status', ['scheduled', 'in_progress'])
                ->whereDate('asset_maintenances.scheduled_date', '<', Carbon::today()))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('asset_maintenances.scheduled_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('asset_maintenances.scheduled_date', '<=', $request->to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('asset_maintenances.title', 'LIKE', "%{$s}%")
                        ->orWhere('asset_maintenances.vendor', 'LIKE', "%{$s}%")
                        ->orWhere('assets.name', 'LIKE', "%{$s}%")
                        ->orWhere('assets.tag', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = (clone $query)->count();
        // The footer totals describe the whole filtered set, not just this page.
        $totals = (clone $query)->selectRaw('COALESCE(SUM(asset_maintenances.cost),0) as total_cost')->first();

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
                'type' => $row->type,
                'title' => $row->title,
                'vendor' => $row->vendor,
                'scheduled_date' => $row->scheduled_date ? $row->scheduled_date->format('Y-m-d') : null,
                'completed_date' => $row->completed_date ? $row->completed_date->format('Y-m-d') : null,
                'next_due_date' => $row->next_due_date ? $row->next_due_date->format('Y-m-d') : null,
                'cost' => round((float) $row->cost, 2),
                'status' => $row->status,
                'notes' => $row->notes,
                'is_overdue' => $row->isOverdue(),
                'downtime_days' => $row->downtimeDays(),
            ];
        }

        return response()->json([
            'maintenances' => $data,
            'totalRows' => $totalRows,
            'totals' => ['cost' => round((float) ($totals->total_cost ?? 0), 2)],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', AssetMaintenance::class);

        $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'title' => 'required|string|max:191',
            'type' => 'required|in:'.implode(',', self::TYPES),
            'scheduled_date' => 'required|date',
            'completed_date' => 'nullable|date|after_or_equal:scheduled_date',
            'next_due_date' => 'nullable|date',
            'cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:'.implode(',', self::STATUSES),
        ]);

        return DB::transaction(function () use ($request) {
            $status = $request->status ?: 'scheduled';

            $record = AssetMaintenance::create([
                'asset_id' => $request->asset_id,
                'type' => $request->type,
                'title' => $request->title,
                'vendor' => $request->vendor,
                'scheduled_date' => $request->scheduled_date,
                'completed_date' => $status === 'completed'
                    ? ($request->completed_date ?: Carbon::today()->toDateString())
                    : null,
                'cost' => $request->cost ?: 0,
                'next_due_date' => $request->next_due_date,
                'status' => $status,
                'notes' => $request->notes,
                'created_by' => $request->user('api')->id ?? null,
            ]);

            $this->syncAssetStatus($record->asset_id);

            return response()->json(['success' => true], 200);
        });
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', AssetMaintenance::class);

        $record = AssetMaintenance::whereNull('deleted_at')->findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:191',
            'type' => 'required|in:'.implode(',', self::TYPES),
            'scheduled_date' => 'required|date',
            'completed_date' => 'nullable|date|after_or_equal:scheduled_date',
            'next_due_date' => 'nullable|date',
            'cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:'.implode(',', self::STATUSES),
        ]);

        return DB::transaction(function () use ($request, $record) {
            $status = $request->status ?: $record->status;

            $record->update([
                'type' => $request->type,
                'title' => $request->title,
                'vendor' => $request->vendor,
                'scheduled_date' => $request->scheduled_date,
                // A job that is no longer complete must lose its completion
                // date, otherwise the downtime figure keeps counting a job
                // that was reopened.
                'completed_date' => $status === 'completed'
                    ? ($request->completed_date ?: $record->completed_date ?: Carbon::today()->toDateString())
                    : null,
                'cost' => $request->cost ?: 0,
                'next_due_date' => $request->next_due_date,
                'status' => $status,
                'notes' => $request->notes,
            ]);

            $this->syncAssetStatus($record->asset_id);

            return response()->json(['success' => true], 200);
        });
    }

    /** Quick status change from the list, without opening the form. */
    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', AssetMaintenance::class);

        $request->validate([
            'status' => 'required|in:'.implode(',', self::STATUSES),
        ]);

        return DB::transaction(function () use ($request, $id) {
            $record = AssetMaintenance::whereNull('deleted_at')->findOrFail($id);

            $record->update([
                'status' => $request->status,
                'completed_date' => $request->status === 'completed'
                    ? ($record->completed_date ?: Carbon::today()->toDateString())
                    : null,
            ]);

            $this->syncAssetStatus($record->asset_id);

            return response()->json(['success' => true], 200);
        });
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', AssetMaintenance::class);

        $record = AssetMaintenance::whereNull('deleted_at')->findOrFail($id);
        $record->update(['deleted_at' => Carbon::now()]);
        $this->syncAssetStatus($record->asset_id);

        return response()->json(['success' => true], 200);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', AssetMaintenance::class);

        foreach ($request->selectedIds ?: [] as $id) {
            $record = AssetMaintenance::whereNull('deleted_at')->find($id);
            if ($record) {
                $record->update(['deleted_at' => Carbon::now()]);
                $this->syncAssetStatus($record->asset_id);
            }
        }

        return response()->json(['success' => true], 200);
    }

    /**
     * Keep the asset's own status honest: it reads "maintenance" exactly while
     * a job is in progress. Retired and disposed assets are left alone — those
     * states outrank a service booking.
     */
    private function syncAssetStatus($assetId): void
    {
        $asset = Asset::whereNull('deleted_at')->find($assetId);
        if (! $asset || $asset->status === 'retired' || $asset->disposal_date) {
            return;
        }

        $busy = AssetMaintenance::where('asset_id', $assetId)
            ->whereNull('deleted_at')
            ->where('status', 'in_progress')
            ->exists();

        if ($busy && $asset->status !== 'maintenance') {
            $asset->update(['status' => 'maintenance']);
        } elseif (! $busy && $asset->status === 'maintenance') {
            $asset->update(['status' => 'in_use']);
        }
    }
}
