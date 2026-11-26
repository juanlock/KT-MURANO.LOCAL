<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\JelafSync;
use App\Support\JelafClient;
use App\Support\ModuleFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Integración -> Jelaf automático (2026-11-26): the Jelaf reports Murano imports every night at 22:00, their last run and
 * history, and "Importar ahora" for a date range (the same import as the night). Permission transport_aurasoft_import.
 *
 *  GET  transport/jelaf                 reports + last run of each + whether the Jelaf login is set on this server
 *  GET  transport/jelaf/runs?page&limit&SortField&SortType&search   the history
 *  POST transport/jelaf/run {report, from, to}
 */
class TransportJelafController extends Controller
{
    use ScopesToCompany;

    private function company(Request $request): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'transport_aurasoft_import'), 403, __('You do not have permission for this.'));
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');
        ModuleFlags::abortUnlessEnabled($cid, 'transport');

        return $cid;
    }

    public function index(Request $request)
    {
        $cid = $this->company($request);
        $reports = [];
        foreach (JelafSync::REPORTS as $key => [$name, $what]) {
            $last = DB::table('transport_jelaf_runs')->where('company_id', $cid)->where('report', $key)->orderByDesc('id')->first();
            $lastOk = DB::table('transport_jelaf_runs')->where('company_id', $cid)->where('report', $key)->where('status', 'ok')->orderByDesc('id')->first();
            $reports[] = ['key' => $key, 'name' => $name, 'what' => $what, 'last' => $last, 'last_ok' => $lastOk,
                'max_days' => $key === 'boarding' ? 7 : 31];
        }

        return ['reports' => $reports, 'login_ready' => JelafClient::envFile() !== null, 'night_at' => '22:00'];
    }

    public function runs(Request $request)
    {
        $cid = $this->company($request);
        $sortable = ['started_at', 'report', 'status', 'date_from', 'rows'];
        $field = in_array($request->input('SortField'), $sortable, true) ? $request->input('SortField') : 'started_at';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $q = DB::table('transport_jelaf_runs as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->where('r.company_id', $cid);
        if ($s = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('r.report', 'like', "%$s%")->orWhere('r.status', 'like', "%$s%")->orWhere('r.message', 'like', "%$s%"));
        }
        $total = (clone $q)->count();
        $limit = max(1, min(200, (int) $request->input('limit', 20)));
        $rows = $q->orderBy("r.$field", $dir)->orderByDesc('r.id')->forPage(max(1, (int) $request->input('page', 1)), $limit)
            ->get(['r.*', DB::raw("TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) as user_name")])
            ->map(function ($r) {
                $r->summary = json_decode((string) $r->summary, true);
                $r->report_name = JelafSync::REPORTS[$r->report][0] ?? $r->report;

                return $r;
            });

        return response()->json(['rows' => $rows, 'totalRows' => $total]);
    }

    public function run(Request $request)
    {
        $cid = $this->company($request);
        $d = $request->validate(['report' => 'required|in:'.implode(',', array_keys(JelafSync::REPORTS)), 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        abort_if($d['to'] > now()->toDateString(), 422, __('The range cannot end in the future.'));
        $max = $d['report'] === 'boarding' ? 7 : 31;
        abort_if((strtotime($d['to']) - strtotime($d['from'])) / 86400 + 1 > $max, 422, __('Up to :n days at a time.', ['n' => $max]));
        abort_unless(JelafClient::envFile(), 422, __('The Jelaf login is not set on this server.'));
        @set_time_limit(600);

        return ['run' => JelafSync::run($cid, $d['report'], $d['from'], $d['to'], 'manual', (int) $request->user('api')->id)];
    }
}
