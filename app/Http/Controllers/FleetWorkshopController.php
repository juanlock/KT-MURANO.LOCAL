<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\CompanyNames;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The mechanic's workshop (user's request, 2026-11-04): spare parts with their stock, the parts used on
 * each job (FleetMaintenanceController moves the stock) and the maintenance plan: what each
 * vehicle needs, every so many km and / or days, and what is due.
 * Permissions: fleet_parts, fleet_plan.
 */
class FleetWorkshopController extends Controller
{
    use ScopesToCompany;

    private function allow(Request $request, string $perm): void
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), $perm), 403);
    }

    private function company(Request $request): int
    {
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');

        return $cid;
    }

    // ------------------------------------------------------------------ parts

    public function parts(Request $request)
    {
        $this->allow($request, 'fleet_parts');
        $list = $this->listCompanyId($request);
        $rows = DB::table('vehicle_parts as p')->leftJoin('providers as v', 'v.id', '=', 'p.provider_id')->whereNull('p.deleted_at')
            ->when($list !== null, fn ($q) => $q->where('p.company_id', $list))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x->where('p.name', 'like', '%'.$request->input('search').'%')->orWhere('p.code', 'like', '%'.$request->input('search').'%')))
            ->when($request->boolean('low'), fn ($q) => $q->whereColumn('p.stock', '<=', 'p.min_stock'))
            ->orderBy('p.name')->get(['p.*', 'v.name as provider'])->map(fn ($p) => (array) $p)->all();

        return response()->json(['parts' => CompanyNames::attach($rows), 'providers' => DB::table('providers')->when($list !== null, fn ($q) => $q->where('company_id', $list))->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])]);
    }

    public function savePart(Request $request, ?int $id = null)
    {
        $this->allow($request, 'fleet_parts');
        $cid = $this->company($request);
        $v = $request->validate(['name' => 'required|string|max:150', 'code' => 'nullable|string|max:40', 'unit' => 'nullable|string|max:20', 'min_stock' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0', 'provider_id' => 'nullable|integer']);
        if (! empty($v['provider_id'])) {
            abort_unless(DB::table('providers')->where('id', $v['provider_id'])->where('company_id', $cid)->exists(), 422, 'Choose a supplier of the company.');
        }
        $set = ['name' => strip_tags($v['name']), 'code' => isset($v['code']) ? strip_tags($v['code']) : null, 'unit' => $v['unit'] ?? 'und', 'min_stock' => $v['min_stock'] ?? 0,
            'unit_cost' => $v['unit_cost'] ?? 0, 'provider_id' => $v['provider_id'] ?? null, 'updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('vehicle_parts')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->update($set), 404);
        } else {
            $id = DB::table('vehicle_parts')->insertGetId($set + ['company_id' => $cid, 'stock' => 0, 'created_at' => now()]);
        }

        return response()->json(['id' => $id]);
    }

    public function deletePart(Request $request, int $id)
    {
        $this->allow($request, 'fleet_parts');
        $cid = $this->company($request);
        abort_unless(DB::table('vehicle_parts')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->update(['deleted_at' => now()]), 404);

        return response()->json(['success' => true]);
    }

    /** Stock in (bought, at its cost: the part's cost becomes the average) or an adjustment. */
    public function movePart(Request $request, int $id)
    {
        $this->allow($request, 'fleet_parts');
        $cid = $this->company($request);
        $v = $request->validate(['kind' => 'required|in:in,adjust', 'quantity' => 'required|numeric|not_in:0', 'unit_cost' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:190']);
        DB::transaction(function () use ($v, $id, $cid, $request) {
            $part = DB::table('vehicle_parts')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($part, 404);
            $qty = (float) $v['quantity'];
            abort_if($v['kind'] === 'in' && $qty <= 0, 422, 'A purchase adds stock.');
            abort_if((float) $part->stock + $qty < 0, 422, 'There is not that much in stock.');
            $set = ['stock' => (float) $part->stock + $qty, 'updated_at' => now()];
            $cost = (float) ($v['unit_cost'] ?? $part->unit_cost);
            if ($v['kind'] === 'in' && isset($v['unit_cost'])) {
                $set['unit_cost'] = round((max(0, (float) $part->stock) * (float) $part->unit_cost + $qty * $cost) / max(0.01, max(0, (float) $part->stock) + $qty), 2);
            }
            DB::table('vehicle_parts')->where('id', $id)->update($set);
            DB::table('vehicle_part_moves')->insert(['part_id' => $id, 'kind' => $v['kind'], 'quantity' => $qty, 'unit_cost' => $cost, 'note' => isset($v['note']) ? strip_tags($v['note']) : null,
                'user_id' => $request->user('api')->id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['success' => true]);
    }

    public function partMoves(Request $request, int $id)
    {
        $this->allow($request, 'fleet_parts');
        $cid = $this->company($request);
        abort_unless(DB::table('vehicle_parts')->where('id', $id)->where('company_id', $cid)->exists(), 404);

        return response()->json(['moves' => DB::table('vehicle_part_moves as m')->leftJoin('vehicle_maintenances as w', 'w.id', '=', 'm.maintenance_id')->leftJoin('vehicles as v', 'v.id', '=', 'w.vehicle_id')
            ->where('m.part_id', $id)->orderByDesc('m.id')->limit(100)->get(['m.id', 'm.kind', 'm.quantity', 'm.unit_cost', 'm.note', 'm.created_at', 'w.title', 'v.name as vehicle'])]);
    }

    // ------------------------------------------------------------------ plan

    public function plans(Request $request)
    {
        $this->allow($request, 'fleet_plan');
        $list = $this->listCompanyId($request);
        $rows = self::due(DB::table('vehicle_maintenance_plans as p')->join('vehicles as v', 'v.id', '=', 'p.vehicle_id')->whereNull('p.deleted_at')->whereNull('v.deleted_at')
            ->when($list !== null, fn ($q) => $q->where('p.company_id', $list))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('p.vehicle_id', (int) $request->input('vehicle_id')))
            ->orderBy('v.name')->orderBy('p.title')->get(['p.*', 'v.name as vehicle', 'v.plate_number', 'v.odometer']));

        return response()->json(['plans' => CompanyNames::attach($rows)]);
    }

    /** Each plan row with when it falls due: next km / date, what's left, overdue or soon. */
    public static function due($plans): array
    {
        $today = Carbon::today();

        return collect($plans)->map(function ($p) use ($today) {
            $row = (array) $p;
            $nextKm = $p->every_km ? (float) ($p->last_km ?? 0) + (int) $p->every_km : null;
            $kmLeft = $nextKm !== null ? round($nextKm - (float) $p->odometer) : null;
            $nextDate = $p->every_days && $p->last_date ? Carbon::parse($p->last_date)->addDays((int) $p->every_days) : null;
            $daysLeft = $nextDate ? $today->diffInDays($nextDate, false) : null;
            $state = 'ok';
            if (($kmLeft !== null && $kmLeft <= 0) || ($daysLeft !== null && $daysLeft < 0)) {
                $state = 'overdue';
            } elseif (($kmLeft !== null && $kmLeft <= 2000) || ($daysLeft !== null && $daysLeft <= 15)) {
                $state = 'soon';
            }

            return $row + ['next_km' => $nextKm, 'km_left' => $kmLeft, 'next_date' => $nextDate?->toDateString(), 'days_left' => $daysLeft, 'state' => $state];
        })->values()->all();
    }

    public function savePlan(Request $request, ?int $id = null)
    {
        $this->allow($request, 'fleet_plan');
        $cid = $this->company($request);
        $v = $request->validate(['vehicle_id' => 'required|integer', 'title' => 'required|string|max:150', 'type' => 'required|in:service,repair,tyres,inspection,insurance,other',
            'every_km' => 'nullable|integer|min:1', 'every_days' => 'nullable|integer|min:1', 'last_km' => 'nullable|numeric|min:0', 'last_date' => 'nullable|date', 'active' => 'boolean']);
        abort_if(empty($v['every_km']) && empty($v['every_days']), 422, 'Say every how many km or days.');
        $bus = DB::table('vehicles')->where('id', $v['vehicle_id'])->where('company_id', $cid)->whereNull('deleted_at')->first(['id', 'odometer']);
        abort_unless($bus, 422, 'Choose a vehicle of the company.');
        $set = ['vehicle_id' => $v['vehicle_id'], 'title' => strip_tags($v['title']), 'type' => $v['type'], 'every_km' => $v['every_km'] ?? null, 'every_days' => $v['every_days'] ?? null,
            'last_km' => $v['last_km'] ?? $bus->odometer, 'last_date' => $v['last_date'] ?? now()->toDateString(), 'active' => (bool) ($v['active'] ?? true), 'updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('vehicle_maintenance_plans')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->update($set), 404);
        } else {
            $id = DB::table('vehicle_maintenance_plans')->insertGetId($set + ['company_id' => $cid, 'created_at' => now()]);
        }

        return response()->json(['id' => $id]);
    }

    public function deletePlan(Request $request, int $id)
    {
        $this->allow($request, 'fleet_plan');
        $cid = $this->company($request);
        abort_unless(DB::table('vehicle_maintenance_plans')->where('id', $id)->where('company_id', $cid)->whereNull('deleted_at')->update(['deleted_at' => now()]), 404);

        return response()->json(['success' => true]);
    }
}
