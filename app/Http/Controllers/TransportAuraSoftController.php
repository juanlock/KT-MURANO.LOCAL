<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\AuraSoftImport;
use App\Support\CompanyFiles;
use App\Support\ModuleFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Integración -> Producción AuraSoft (2026-11-23): the files uploaded and what each one brought (AuraSoftImport).
 * Permission transport_aurasoft_import (IT Admin, Manager); the acting company (a SuperAdmin picks one).
 *
 *  GET    transport/aurasoft?page&limit&SortField&SortType&search   the files, newest first
 *  POST   transport/aurasoft {file}                                  upload one
 *  GET    transport/aurasoft/{id}                                    the file's summary
 *  GET    transport/aurasoft/{id}/rows?page&limit&search&action      what the file brought
 *  GET    transport/aurasoft/{id}/file                               the Excel as uploaded
 *  DELETE transport/aurasoft/{id}                                    remove an import (the earlier rows become current again)
 */
class TransportAuraSoftController extends Controller
{
    use ScopesToCompany;

    private function company(Request $request): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'transport_aurasoft_import'), 403, __('You do not have permission for this.'));
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, __('Pick a company in the header first.'));
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    public function index(Request $request)
    {
        $cid = $this->company($request);
        $sortable = ['created_at', 'original_name', 'period_from', 'period_to', 'rows_total', 'rows_new', 'production'];
        $field = in_array($request->input('SortField'), $sortable, true) ? $request->input('SortField') : 'created_at';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $q = DB::table('transport_aurasoft_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.created_by')->where('i.company_id', $cid)
            ->when(trim((string) $request->input('search')) !== '', fn ($q) => $q->where('i.original_name', 'like', '%'.trim($request->input('search')).'%'));
        $total = (clone $q)->count();
        $limit = max(1, min(200, (int) $request->input('limit', 20)));
        $rows = $q->orderBy('i.'.$field, $dir)->orderByDesc('i.id')->forPage(max(1, (int) $request->input('page', 1)), $limit)
            ->get(['i.id', 'i.kind', 'i.original_name', 'i.period_from', 'i.period_to', 'i.rows_total', 'i.rows_new', 'i.rows_updated', 'i.rows_same', 'i.rows_skipped',
                'i.seats', 'i.ticket_income', 'i.production', 'i.created_at', 'u.username as uploaded_by']);

        // The departures held now (the latest version of each), for the header figures.
        $current = DB::table('transport_aurasoft_rows')->where('company_id', $cid)->where('is_current', 1)
            ->selectRaw('count(*) n, min(trip_date) d1, max(trip_date) d2, coalesce(sum(seats),0) seats, coalesce(sum(production),0) production')->first();

        return response()->json(['rows' => $rows, 'totalRows' => $total, 'current' => $current]);
    }

    public function store(Request $request)
    {
        $cid = $this->company($request);
        $request->validate(['file' => 'required|file|max:20480|mimes:xlsx,xls']);
        try {
            $import = AuraSoftImport::run($cid, $request->file('file'), $request->user('api')?->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            abort(422, __('The file could not be read as Excel.'));
        }

        return response()->json($import);
    }

    public function show(Request $request, int $id)
    {
        $cid = $this->company($request);
        $i = DB::table('transport_aurasoft_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.created_by')->where('i.company_id', $cid)->where('i.id', $id)
            ->first(['i.*', 'u.username as uploaded_by']);
        abort_unless($i, 404);
        $i->columns = json_decode((string) $i->columns, true) ?: [];
        $i->skipped = json_decode((string) $i->skipped, true) ?: [];
        // A "Liquidación General" (2026-11-24): its totals by kind of money, points of sale and cashiers.
        if ($i->kind === 'liquidation') {
            $l = DB::table('transport_aurasoft_liquidations')->where('import_id', $id);
            $i->totals = (clone $l)->selectRaw('COUNT(DISTINCT point) points, COUNT(DISTINCT cashier) cashiers, SUM(tickets) tickets, SUM(parcels) parcels, SUM(excess) excess,
                SUM(collected) collected, SUM(income) income, SUM(card) card, SUM(web) web, SUM(channel) channel, SUM(delivery) delivery, SUM(cash_out) cash_out,
                SUM(expenses) expenses, SUM(net) net')->first();

            return response()->json($i);
        }
        $rows = DB::table('transport_aurasoft_rows')->where('import_id', $id);
        $i->channels = [];
        foreach ((clone $rows)->pluck('channels') as $json) {
            foreach (json_decode((string) $json, true) ?: [] as $k => $n) {
                $i->channels[$k] = ($i->channels[$k] ?? 0) + (int) $n;
            }
        }
        arsort($i->channels);
        $i->parcel_income = (float) (clone $rows)->sum('parcel_income');
        $i->excess_income = (float) (clone $rows)->sum('excess_income');
        $i->buses = (clone $rows)->distinct()->count('plate');
        $i->buses_unknown = (clone $rows)->whereNull('vehicle_id')->distinct()->pluck('plate')->values();

        return response()->json($i);
    }

    public function rows(Request $request, int $id)
    {
        $cid = $this->company($request);
        $kind = DB::table('transport_aurasoft_imports')->where('company_id', $cid)->where('id', $id)->value('kind');
        abort_unless($kind, 404);
        if ($kind === 'liquidation') {
            return $this->liquidationRows($request, $cid, $id);
        }
        $sortable = ['trip_date', 'plate', 'route', 'seats', 'ticket_income', 'parcel_income', 'production', 'action'];
        $field = in_array($request->input('SortField'), $sortable, true) ? $request->input('SortField') : 'trip_date';
        $dir = strtolower((string) $request->input('SortType')) === 'desc' ? 'desc' : 'asc';
        $s = trim((string) $request->input('search'));
        $q = DB::table('transport_aurasoft_rows as r')->leftJoin('vehicles as v', 'v.id', '=', 'r.vehicle_id')->where('r.company_id', $cid)->where('r.import_id', $id)
            ->when(in_array($request->input('action'), ['new', 'updated', 'same'], true), fn ($q) => $q->where('r.action', $request->input('action')))
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('r.plate', 'like', "%$s%")->orWhere('r.route', 'like', "%$s%")->orWhere('v.name', 'like', "%$s%")));
        $total = (clone $q)->count();
        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $rows = $q->orderBy('r.'.$field, $dir)->orderBy('r.departure_time')->orderBy('r.plate')->forPage(max(1, (int) $request->input('page', 1)), $limit)
            ->get(['r.id', 'r.row_number', 'r.plate', 'r.trip_date', 'r.departure_time', 'r.route', 'r.seats', 'r.channels', 'r.ticket_income',
                'r.parcels', 'r.parcel_income', 'r.excess_income', 'r.production', 'r.action', 'r.is_current', 'v.name as bus']);
        foreach ($rows as $r) {
            $r->channels = json_decode((string) $r->channels, true) ?: [];
            // A changed departure carries what it was before (2026-11-24: "indica si algún registro tiene cambio").
            if ($r->action === 'updated') {
                $key = DB::table('transport_aurasoft_rows')->where('id', $r->id)->value('departure_key');
                $r->before = DB::table('transport_aurasoft_rows')->where('company_id', $cid)->where('departure_key', $key)->where('id', '<', $r->id)
                    ->where('action', '!=', 'same')->orderByDesc('id')->first(['seats', 'ticket_income', 'parcel_income', 'excess_income', 'production', 'import_id']);
            }
        }

        return response()->json(['rows' => $rows, 'totalRows' => $total]);
    }

    /** A "Liquidación General" file's rows: one per day x point of sale x cashier, changed ones with their earlier figures. */
    private function liquidationRows(Request $request, int $cid, int $id)
    {
        $sortable = ['sale_date', 'branch', 'point', 'cashier', 'tickets', 'parcels', 'income', 'card', 'expenses', 'net', 'action'];
        $field = in_array($request->input('SortField'), $sortable, true) ? $request->input('SortField') : 'sale_date';
        $dir = strtolower((string) $request->input('SortType')) === 'desc' ? 'desc' : 'asc';
        $s = trim((string) $request->input('search'));
        $q = DB::table('transport_aurasoft_liquidations')->where('company_id', $cid)->where('import_id', $id)
            ->when(in_array($request->input('action'), ['new', 'updated', 'same'], true), fn ($q) => $q->where('action', $request->input('action')))
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('branch', 'like', "%$s%")->orWhere('point', 'like', "%$s%")->orWhere('cashier', 'like', "%$s%")));
        $total = (clone $q)->count();
        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $rows = $q->orderBy($field, $dir)->orderBy('branch')->orderBy('point')->orderBy('cashier')->forPage(max(1, (int) $request->input('page', 1)), $limit)
            ->get(['id', 'row_key', 'sale_date', 'branch', 'point', 'cashier', 'tickets', 'parcels', 'excess', 'collected', 'income', 'card', 'web', 'channel',
                'cash_out', 'expenses', 'net', 'action', 'is_current']);
        foreach ($rows as $r) {
            if ($r->action === 'updated') {
                $r->before = DB::table('transport_aurasoft_liquidations')->where('company_id', $cid)->where('row_key', $r->row_key)->where('id', '<', $r->id)
                    ->where('action', '!=', 'same')->orderByDesc('id')->first(['tickets', 'income', 'expenses', 'net', 'import_id']);
            }
            unset($r->row_key);
        }

        return response()->json(['rows' => $rows, 'totalRows' => $total, 'kind' => 'liquidation']);
    }

    public function file(Request $request, int $id)
    {
        $cid = $this->company($request);
        $i = DB::table('transport_aurasoft_imports')->where('company_id', $cid)->where('id', $id)->first();
        abort_unless($i && CompanyFiles::owns($i->file, $cid, 'transport/aurasoft'), 404);

        return CompanyFiles::download($i->file, $i->original_name);
    }

    public function destroy(Request $request, int $id)
    {
        $cid = $this->company($request);
        abort_unless(DB::table('transport_aurasoft_imports')->where('company_id', $cid)->where('id', $id)->exists(), 404);
        AuraSoftImport::remove($cid, $id);

        return response()->json(['ok' => true]);
    }
}
