<?php

namespace App\Http\Controllers;

use App\Models\Client as CustomerModel;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ShopifyLink;
use App\Models\ShopifyLog;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncRun;
use App\Services\Shopify\SyncService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Running syncs, watching them, and inspecting what got linked.
 *
 * A sync runs inside the request that starts it — same as the existing
 * WooCommerce module, so this needs no queue worker to be useful. What makes it
 * survivable is that progress is written to the run row as it goes: the browser
 * polls `status`, and a run whose process dies stops sending heartbeats and is
 * reported as stale rather than hanging on "running" for ever.
 */
class ShopifySyncController extends BaseController
{
    private const DIRECTIONS = ['push', 'pull'];

    /** Orders come from the shop; pushing an ERP sale back would invent one. */
    private const PULL_ONLY = ['orders'];

    public function start(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', ShopifyStore::class);

        $request->validate([
            'store_id' => 'required|exists:shopify_stores,id',
            'entity' => 'required|in:'.implode(',', ShopifyStore::ENTITIES),
            'direction' => 'required|in:'.implode(',', self::DIRECTIONS),
            'since' => 'nullable|date',
        ]);

        $store = ShopifyStore::whereNull('deleted_at')->findOrFail($request->store_id);
        $entity = $request->entity;
        $direction = $request->direction;

        if ($store->status !== 'connected') {
            return response()->json([
                'success' => false,
                'message' => 'This store is not connected. Test the connection before syncing.',
            ], 422);
        }
        if (! $store->syncs($entity)) {
            return response()->json([
                'success' => false,
                'message' => ucfirst($entity).' sync is switched off for this store.',
            ], 422);
        }
        if ($direction === 'push' && in_array($entity, self::PULL_ONLY, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Orders are pull-only — Shopify is where they are placed.',
            ], 422);
        }

        // One run per store+entity at a time. Two concurrent product pushes
        // would race on the same link rows and double-create in the shop.
        $active = ShopifySyncRun::where('store_id', $store->id)
            ->where('entity', $entity)
            ->where('status', 'running')
            ->first();

        if ($active && ! $active->isStale()) {
            return response()->json([
                'success' => false,
                'message' => 'A '.$entity.' sync is already running for this store.',
                'run' => $active->toPublicArray(),
            ], 409);
        }
        if ($active) {
            // Its worker is gone; close it out so it stops blocking.
            $active->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => 'The process handling this run stopped responding.',
            ]);
        }

        @ini_set('max_execution_time', '1800');
        @ini_set('memory_limit', '512M');

        $run = SyncService::for($store)->run($entity, $direction, [
            'dry_run' => $request->boolean('dry_run'),
            'only_unsynced' => $request->boolean('only_unsynced'),
            'since' => $request->since,
        ], optional($request->user('api'))->id);

        return response()->json([
            'success' => $run->status === 'completed',
            'run' => $run->toPublicArray(),
        ], 200);
    }

    /** Progress for one run — the endpoint the UI polls. */
    public function status(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $run = ShopifySyncRun::findOrFail($id);

        return response()->json(['run' => $run->toPublicArray()]);
    }

    /**
     * Ask a run to stop. It is a request, not a kill: the worker notices between
     * batches and finishes cleanly, so nothing is left half-written.
     */
    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ShopifyStore::class);

        $run = ShopifySyncRun::findOrFail($id);
        if ($run->isFinished()) {
            return response()->json(['success' => false, 'message' => 'That run has already finished.'], 422);
        }

        $run->update(['cancel_requested' => true]);
        ShopifyLog::write($run->store_id, 'sync.cancel', 'Cancel requested for run #'.$run->id, 'warning', [], $run->id, $run->entity);

        return response()->json(['success' => true, 'run' => $run->fresh()->toPublicArray()]);
    }

    /** Run history, filterable by store and entity. */
    public function runs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $dir = strtolower((string) ($request->SortType ?: 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $sortable = ['id' => 'id', 'entity' => 'entity', 'status' => 'status', 'started_at' => 'started_at'];
        $order = $sortable[$request->SortField ?? 'id'] ?? 'id';

        $query = ShopifySyncRun::query()
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->store_id))
            ->when($request->filled('entity'), fn ($q) => $q->where('entity', $request->entity))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->direction));

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $rows = $query->with('store')->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get()
            ->map(function ($run) {
                $row = $run->toPublicArray();
                $row['store_name'] = $run->store ? $run->store->name : null;

                return $row;
            });

        return response()->json(['runs' => $rows, 'totalRows' => $totalRows]);
    }

    /** The most recent run per entity for a store — powers the sync centre. */
    public function latest(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $request->validate(['store_id' => 'required|exists:shopify_stores,id']);

        $latest = [];
        foreach (ShopifyStore::ENTITIES as $entity) {
            $run = ShopifySyncRun::where('store_id', $request->store_id)
                ->where('entity', $entity)
                ->orderByDesc('id')
                ->first();
            $latest[$entity] = $run ? $run->toPublicArray() : null;
        }

        return response()->json(['latest' => $latest]);
    }

    // -------------------------------------------------------------- mappings --

    /**
     * The link table, joined back to the local record so a human can read it.
     *
     * This is the page people open when something looks wrong, so it answers the
     * two questions that matter: what is this ERP record called, and which
     * Shopify record does it point at.
     */
    public function mappings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $perPage = $request->limit ?: 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $dir = strtolower((string) ($request->SortType ?: 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $query = ShopifyLink::query()
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->store_id))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->entity_type))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('shopify_id', 'LIKE', "%{$s}%")
                        ->orWhere('shopify_handle', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $links = $query->offset($offSet)->limit($perPage)->orderBy('id', $dir)->get();
        $names = $this->localNamesFor($links);

        $rows = $links->map(fn ($link) => [
            'id' => $link->id,
            'store_id' => $link->store_id,
            'entity_type' => $link->entity_type,
            'local_id' => $link->local_id,
            'local_name' => $names[$link->entity_type][$link->local_id] ?? null,
            'shopify_id' => $link->shopify_id,
            'shopify_handle' => $link->shopify_handle,
            'secondary_id' => $link->secondary_id,
            'last_synced_at' => $link->last_synced_at ? $link->last_synced_at->toDateTimeString() : null,
        ]);

        return response()->json(['mappings' => $rows, 'totalRows' => $totalRows]);
    }

    /** Resolve local display names in one query per entity type, not per row. */
    private function localNamesFor($links): array
    {
        $byType = $links->groupBy('entity_type')->map(fn ($g) => $g->pluck('local_id')->unique()->values());
        $names = [];

        foreach ($byType as $type => $ids) {
            if ($ids->isEmpty()) {
                continue;
            }

            switch ($type) {
                case 'product':
                case 'inventory_item':
                    $names[$type] = Product::whereIn('id', $ids)->pluck('name', 'id')->toArray();
                    break;
                case 'variant':
                    $names[$type] = DB::table('product_variants')->whereIn('id', $ids)->pluck('name', 'id')->toArray();
                    break;
                case 'customer':
                    $names[$type] = CustomerModel::whereIn('id', $ids)->pluck('name', 'id')->toArray();
                    break;
                case 'order':
                    $names[$type] = Sale::whereIn('id', $ids)->pluck('Ref', 'id')->toArray();
                    break;
                case 'collection':
                    $names[$type] = DB::table('categories')->whereIn('id', $ids)->pluck('name', 'id')->toArray();
                    break;
                default:
                    $names[$type] = [];
            }
        }

        return $names;
    }

    /**
     * Break one mapping.
     *
     * Deliberately local-only: it forgets the pairing, it does not delete
     * anything in Shopify or in the ERP. The next sync will re-match or
     * re-create, which is the recovery path when two records got paired wrongly.
     */
    public function unlink(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ShopifyStore::class);

        $link = ShopifyLink::findOrFail($id);
        $link->delete();

        ShopifyLog::write($link->store_id, 'mapping.unlink',
            'Unlinked '.$link->entity_type.' #'.$link->local_id.' from Shopify '.$link->shopify_id,
            'warning', ['link_id' => $id], null, $link->entity_type);

        return response()->json(['success' => true]);
    }

    /** Pair an ERP record with a Shopify id by hand. */
    public function link(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', ShopifyStore::class);

        $request->validate([
            'store_id' => 'required|exists:shopify_stores,id',
            'entity_type' => 'required|in:product,variant,inventory_item,customer,order,collection',
            'local_id' => 'required|integer|min:1',
            'shopify_id' => 'required|string|max:64',
        ]);

        $clash = ShopifyLink::where('store_id', $request->store_id)
            ->where('entity_type', $request->entity_type)
            ->where(function ($q) use ($request) {
                $q->where('local_id', $request->local_id)
                    ->orWhere('shopify_id', (string) $request->shopify_id);
            })->first();

        if ($clash) {
            return response()->json([
                'success' => false,
                'message' => 'One of those records is already mapped. Unlink it first.',
            ], 422);
        }

        ShopifyLink::link(
            (int) $request->store_id,
            $request->entity_type,
            (int) $request->local_id,
            $request->shopify_id
        );

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ logs --

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $perPage = $request->limit ?: 25;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $query = ShopifyLog::query()
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->store_id))
            ->when($request->filled('level'), fn ($q) => $q->where('level', $request->level))
            ->when($request->filled('entity'), fn ($q) => $q->where('entity', $request->entity))
            ->when($request->filled('run_id'), fn ($q) => $q->where('run_id', $request->run_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                return $q->where(function ($q) use ($s) {
                    $q->where('message', 'LIKE', "%{$s}%")->orWhere('action', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $rows = $query->offset($offSet)->limit($perPage)->orderByDesc('id')->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'store_id' => $l->store_id,
                'run_id' => $l->run_id,
                'entity' => $l->entity,
                'action' => $l->action,
                'level' => $l->level,
                'message' => $l->message,
                'context' => $l->context,
                'created_at' => $l->created_at ? $l->created_at->toDateTimeString() : null,
            ]);

        return response()->json(['logs' => $rows, 'totalRows' => $totalRows]);
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ShopifyStore::class);

        $query = ShopifyLog::query()
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->store_id));

        // Without a store filter, keep the last 7 days so a stray click cannot
        // wipe the evidence of the failure someone is mid-way through debugging.
        if (! $request->boolean('all')) {
            $query->where('created_at', '<', Carbon::now()->subDays(7));
        }

        $deleted = $query->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    // ------------------------------------------------------------- dashboard --

    /** Cross-store overview. */
    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $stores = ShopifyStore::whereNull('deleted_at')->get();
        $today = Carbon::today();

        $runs = ShopifySyncRun::where('created_at', '>=', $today->copy()->subDays(30));

        $byEntity = collect(ShopifyStore::ENTITIES)->map(function ($entity) {
            $linkType = [
                'products' => 'product', 'inventory' => 'inventory_item', 'customers' => 'customer',
                'orders' => 'order', 'collections' => 'collection', 'fulfillments' => 'order',
            ][$entity] ?? $entity;

            return [
                'entity' => $entity,
                'linked' => (int) ShopifyLink::where('entity_type', $linkType)->count(),
                'runs' => (int) ShopifySyncRun::where('entity', $entity)->count(),
                'failed' => (int) ShopifySyncRun::where('entity', $entity)->where('status', 'failed')->count(),
            ];
        });

        // 14-day activity, zero-filled so quiet days show as zero not a gap.
        $activityRaw = ShopifySyncRun::where('created_at', '>=', $today->copy()->subDays(13))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->selectRaw('DATE(created_at) as d, COUNT(*) as runs, COALESCE(SUM(created_items + updated_items),0) as records')
            ->get()->keyBy('d');

        $activity = [];
        for ($i = 13; $i >= 0; $i--) {
            $key = $today->copy()->subDays($i)->toDateString();
            $row = $activityRaw->get($key);
            $activity[] = [
                'd' => $key,
                'runs' => (int) ($row->runs ?? 0),
                'records' => (int) ($row->records ?? 0),
            ];
        }

        $recentRuns = ShopifySyncRun::with('store')->orderByDesc('id')->limit(8)->get()
            ->map(function ($run) {
                $row = $run->toPublicArray();
                $row['store_name'] = $run->store ? $run->store->name : null;

                return $row;
            });

        $recentErrors = ShopifyLog::where('level', 'error')->orderByDesc('id')->limit(8)->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'store_id' => $l->store_id,
                'entity' => $l->entity,
                'action' => $l->action,
                'message' => $l->message,
                'created_at' => $l->created_at ? $l->created_at->toDateTimeString() : null,
            ]);

        return response()->json([
            'stores_total' => $stores->count(),
            'stores_connected' => $stores->where('status', 'connected')->count(),
            'stores_error' => $stores->where('status', 'error')->count(),
            'linked_records' => (int) ShopifyLink::count(),
            'orders_imported' => (int) ShopifyLink::where('entity_type', 'order')->count(),
            'runs_30d' => (int) (clone $runs)->count(),
            'runs_failed_30d' => (int) (clone $runs)->where('status', 'failed')->count(),
            'errors_7d' => (int) ShopifyLog::where('level', 'error')
                ->where('created_at', '>=', $today->copy()->subDays(7))->count(),
            'running_now' => ShopifySyncRun::where('status', 'running')->get()
                ->reject(fn ($r) => $r->isStale())->count(),
            'by_entity' => $byEntity,
            'activity' => $activity,
            'stores' => $stores->map(function ($s) {
                $row = $s->toPublicArray();
                $row['linked_records'] = (int) ShopifyLink::where('store_id', $s->id)->count();

                return $row;
            })->values(),
            'recent_runs' => $recentRuns,
            'recent_errors' => $recentErrors,
        ]);
    }
}
