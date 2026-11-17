<?php

namespace App\Http\Controllers\CallCenter;

use App\Http\Controllers\SalesController;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Support\CallCenter;
use App\Support\PublicUrl;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Call center: agents' shifts, the floor indicators, billing to the clients and the telephone exchange (2026-11-17).
 *
 *  GET  callcenter/shifts?week               POST callcenter/shifts {user_ids, days, start_time, end_time, campaign_id}
 *  DELETE callcenter/shifts/{id}             POST callcenter/shifts/copy {week}
 *  GET  callcenter/dashboard?from&to&campaign_id
 *  GET  callcenter/billing                   POST callcenter/billing/preview      POST callcenter/billing
 *  GET  callcenter/telephony                 POST callcenter/telephony            POST callcenter/telephony/token
 *  POST cc-hook/{token}  (public: the exchange sends each call)
 */
class CallCenterAdminController extends CallCenterBase
{
    // ------------------------------------------------------------------ shifts

    private function monday(?string $week): Carbon
    {
        $d = $week && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) ? Carbon::parse($week) : now();

        return $d->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** Worked / paused seconds and the first start per user and day, from the sessions. */
    private function attendance(int $cid, Carbon $from, Carbon $to, ?int $campaignId = null): array
    {
        $out = [];
        $rows = DB::table('cc_agent_sessions')->where('company_id', $cid)->where('started_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))->get();
        foreach ($rows as $s) {
            $start = Carbon::parse($s->started_at)->max($from);
            // An open session ends at its last sign of life when the screen went quiet (offline), else now.
            $seen = $s->last_seen_at ? Carbon::parse($s->last_seen_at) : null;
            $end = $s->ended_at ? Carbon::parse($s->ended_at)
                : ($seen && $seen->lt(now()->subMinutes(CallCenter::OFFLINE_MINUTES)) ? $seen : now());
            $end = $end->min($to)->min(now());
            if ($end <= $start) {
                continue;
            }
            $day = $start->toDateString();
            $k = $s->user_id.'|'.$day;
            $out[$k] ??= ['work' => 0, 'pause' => 0, 'first' => null, 'last' => null];
            $out[$k][$s->kind === 'pause' ? 'pause' : 'work'] += $start->diffInSeconds($end, true);
            if ($s->kind === 'work' && (! $out[$k]['first'] || $start->format('H:i:s') < $out[$k]['first'])) {
                $out[$k]['first'] = $start->format('H:i:s');
            }
            if (! $out[$k]['last'] || $end->format('H:i:s') > $out[$k]['last']) {
                $out[$k]['last'] = $end->format('H:i:s');
            }
        }

        return $out;
    }

    public function shifts(Request $request)
    {
        $this->allow($request, 'callcenter_shifts');
        $cid = $this->company($request);
        $mon = $this->monday($request->input('week'));
        $sun = $mon->copy()->addDays(7);
        $agents = $this->agents($cid);
        $planned = DB::table('cc_shifts')->where('company_id', $cid)->whereBetween('day', [$mon->toDateString(), $sun->copy()->subDay()->toDateString()])->get();
        $att = $this->attendance($cid, $mon, $sun);
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $mon->copy()->addDays($i)->toDateString();
        }
        $grid = [];
        foreach ($agents as $a) {
            $cells = [];
            foreach ($days as $day) {
                $p = $planned->first(fn ($s) => (int) $s->user_id === (int) $a->id && $s->day === $day);
                $x = $att[$a->id.'|'.$day] ?? null;
                $late = null;
                if ($p && $x && $x['first']) {
                    $diff = (int) round((strtotime($day.' '.$x['first']) - strtotime($day.' '.$p->start_time)) / 60);
                    $late = $diff > 5 ? $diff : 0;
                }
                $cells[$day] = [
                    'shift' => $p ? ['id' => (int) $p->id, 'start' => substr($p->start_time, 0, 5), 'end' => substr($p->end_time, 0, 5),
                        'campaign_id' => $p->campaign_id ? (int) $p->campaign_id : null, 'notes' => $p->notes,
                        'hours' => round((strtotime($p->end_time) - strtotime($p->start_time)) / 3600, 2)] : null,
                    'worked_sec' => (int) ($x['work'] ?? 0), 'paused_sec' => (int) ($x['pause'] ?? 0),
                    'first' => $x ? ($x['first'] ? substr($x['first'], 0, 5) : null) : null, 'last' => $x ? substr((string) $x['last'], 0, 5) : null,
                    'late_min' => $late, 'absent' => $p && ! $x && $day < now()->toDateString(),
                ];
            }
            $grid[] = ['user_id' => (int) $a->id, 'name' => $a->username, 'cells' => $cells,
                'planned_h' => round(array_sum(array_map(fn ($c) => $c['shift']['hours'] ?? 0, $cells)), 1),
                'worked_h' => round(array_sum(array_column($cells, 'worked_sec')) / 3600, 1),
                'paused_h' => round(array_sum(array_column($cells, 'paused_sec')) / 3600, 1)];
        }

        return response()->json(['week' => $mon->toDateString(), 'days' => $days, 'rows' => $grid]);
    }

    public function saveShifts(Request $request)
    {
        $this->allow($request, 'callcenter_shifts');
        $cid = $this->company($request);
        $d = $request->validate(['user_ids' => 'required|array|min:1', 'user_ids.*' => 'integer', 'days' => 'required|array|min:1', 'days.*' => 'date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time', 'campaign_id' => 'nullable|integer', 'notes' => 'nullable|string|max:255']);
        $valid = $this->agents($cid)->pluck('id')->map(fn ($i) => (int) $i)->all();
        abort_if(array_diff(array_map('intval', $d['user_ids']), $valid), 422, __('Pick agents of this company.'));
        DB::transaction(function () use ($d, $cid) {
            foreach ($d['user_ids'] as $uid) {
                foreach ($d['days'] as $day) {
                    DB::table('cc_shifts')->updateOrInsert(['user_id' => (int) $uid, 'day' => $day], [
                        'company_id' => $cid, 'start_time' => $d['start_time'], 'end_time' => $d['end_time'], 'campaign_id' => $d['campaign_id'] ?? null,
                        'notes' => $d['notes'] ?? null, 'updated_at' => now(), 'created_at' => now(),
                    ]);
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    public function deleteShift(Request $request, int $id)
    {
        $this->allow($request, 'callcenter_shifts');
        $cid = $this->company($request);
        abort_unless(DB::table('cc_shifts')->where('company_id', $cid)->where('id', $id)->delete(), 404);

        return response()->json(['ok' => true]);
    }

    /** The week before copied into this one (days already planned are kept). */
    public function copyShifts(Request $request)
    {
        $this->allow($request, 'callcenter_shifts');
        $cid = $this->company($request);
        $mon = $this->monday($request->input('week'));
        $prev = DB::table('cc_shifts')->where('company_id', $cid)
            ->whereBetween('day', [$mon->copy()->subDays(7)->toDateString(), $mon->copy()->subDay()->toDateString()])->get();
        $n = 0;
        DB::transaction(function () use ($prev, $cid, &$n) {
            foreach ($prev as $s) {
                $day = Carbon::parse($s->day)->addDays(7)->toDateString();
                if (DB::table('cc_shifts')->where('user_id', $s->user_id)->where('day', $day)->exists()) {
                    continue;
                }
                DB::table('cc_shifts')->insert(['company_id' => $cid, 'user_id' => $s->user_id, 'day' => $day, 'start_time' => $s->start_time,
                    'end_time' => $s->end_time, 'campaign_id' => $s->campaign_id, 'notes' => $s->notes, 'created_at' => now(), 'updated_at' => now()]);
                $n++;
            }
        });

        return response()->json(['copied' => $n]);
    }

    // ------------------------------------------------------------------ floor indicators

    public function dashboard(Request $request)
    {
        $this->allow($request, 'callcenter_dashboard');
        $cid = $this->company($request);
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfDay();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();
        $campaignId = $request->filled('campaign_id') ? (int) $request->campaign_id : null;
        $loc = $this->locale();
        $contacts = CallCenter::contactResults();
        $in = "'".implode("','", $contacts)."'";

        $calls = DB::table('cc_calls')->where('cc_calls.company_id', $cid)->whereBetween('cc_calls.started_at', [$from, $to])
            ->when($campaignId, fn ($q) => $q->where('cc_calls.campaign_id', $campaignId));
        $k = (clone $calls)->selectRaw("COUNT(*) n, SUM(result IN ($in)) contacts, SUM(result = 'sale') sales, SUM(sale_amount) amount,
            AVG(CASE WHEN result IN ($in) THEN duration_sec END) aht, SUM(duration_sec) talk")->first();
        $att = $this->attendance($cid, $from, $to->copy()->addSecond(), $campaignId);
        $workSec = array_sum(array_column($att, 'work'));
        $pauseSec = array_sum(array_column($att, 'pause'));

        // Agents now: working on a lead / waiting / on a pause / offline.
        $agents = $this->agents($cid);
        $open = DB::table('cc_agent_sessions')->where('company_id', $cid)->whereNull('ended_at')->get()->keyBy('user_id');
        $held = DB::table('cc_campaign_leads as x')->join('cc_leads as l', 'l.id', '=', 'x.lead_id')->where('x.company_id', $cid)->where('x.status', 'locked')
            ->get(['x.locked_by', 'x.locked_at', 'l.first_name', 'l.last_name', 'x.campaign_id'])->keyBy('locked_by');
        $campNames = DB::table('cc_campaigns')->where('company_id', $cid)->pluck('name', 'id');
        $lastCall = DB::table('cc_calls')->where('company_id', $cid)->whereDate('started_at', now()->toDateString())
            ->selectRaw('user_id, MAX(ended_at) last_at, COUNT(*) n, SUM(result = \'sale\') sales')->groupBy('user_id')->get()->keyBy('user_id');
        $now = $agents->map(function ($a) use ($open, $held, $campNames, $lastCall, $loc) {
            $s = $open[$a->id] ?? null;
            $alive = $s && (! $s->last_seen_at || now()->diffInMinutes($s->last_seen_at, true) <= CallCenter::OFFLINE_MINUTES);
            $h = $held[$a->id] ?? null;
            $state = ! $alive ? 'offline' : ($s->kind === 'pause' ? 'pause' : ($h ? 'calling' : 'waiting'));

            return ['user_id' => (int) $a->id, 'name' => $a->username, 'state' => $state, 'since' => $alive ? ($state === 'calling' ? $h->locked_at : $s->started_at) : null,
                'reason' => $alive && $s->kind === 'pause' ? CallCenter::label(CallCenter::PAUSES, $s->reason, $loc) : null,
                'lead' => $state === 'calling' ? trim($h->first_name.' '.$h->last_name) : null,
                'campaign' => $alive && $s->campaign_id ? ($campNames[$s->campaign_id] ?? null) : null,
                'calls_today' => (int) ($lastCall[$a->id]->n ?? 0), 'sales_today' => (int) ($lastCall[$a->id]->sales ?? 0)];
        })->values();

        $byAgent = (clone $calls)
            ->selectRaw("cc_calls.user_id, COUNT(*) n, SUM(result IN ($in)) contacts, SUM(result = 'sale') sales, SUM(sale_amount) amount,
                AVG(CASE WHEN result IN ($in) THEN duration_sec END) aht")
            ->groupBy('cc_calls.user_id')->get();
        $names = $this->names($byAgent->pluck('user_id')->all());
        $byAgent->each(fn ($a) => $a->username = $names[$a->user_id] ?? '—');
        $qa = DB::table('cc_qa_reviews as r')->join('cc_calls as c', 'c.id', '=', 'r.call_id')->where('r.company_id', $cid)
            ->whereBetween('c.started_at', [$from, $to])->groupBy('r.agent_id')->selectRaw('r.agent_id, AVG(r.score) s')->pluck('s', 'agent_id');
        $workBy = [];
        foreach ($att as $key => $v) {
            $uid = (int) explode('|', $key)[0];
            $workBy[$uid] = ($workBy[$uid] ?? ['work' => 0, 'pause' => 0]);
            $workBy[$uid]['work'] += $v['work'];
            $workBy[$uid]['pause'] += $v['pause'];
        }

        $campaigns = DB::table('cc_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->whereIn('status', ['active', 'paused'])
            ->when($campaignId, fn ($q) => $q->where('id', $campaignId))->orderBy('name')->get(['id', 'name', 'status']);
        $prog = CallCenter::progress($campaigns->pluck('id')->all());
        $hours = (clone $calls)->selectRaw("HOUR(cc_calls.started_at) h, COUNT(*) n, SUM(result IN ($in)) contacts, SUM(result = 'sale') sales")->groupBy('h')->orderBy('h')->get();
        $results = (clone $calls)->selectRaw('result, COUNT(*) n')->groupBy('result')->orderByDesc('n')->get();

        $n = (int) ($k->n ?? 0);
        $c = (int) ($k->contacts ?? 0);

        return response()->json([
            'kpis' => [
                'calls' => $n, 'contacts' => $c, 'contact_rate' => $n ? round(100 * $c / $n, 1) : null,
                'sales' => (int) ($k->sales ?? 0), 'conversion' => $c ? round(100 * (int) $k->sales / $c, 1) : null,
                'amount' => round((float) ($k->amount ?? 0), 2), 'aht_sec' => (int) round((float) ($k->aht ?? 0)),
                'work_h' => round($workSec / 3600, 1), 'pause_pct' => $workSec + $pauseSec ? round(100 * $pauseSec / ($workSec + $pauseSec), 1) : null,
                // Per hour / occupancy need a real stretch of shift (15 min) to mean anything.
                'calls_per_hour' => $workSec >= 900 ? round($n / ($workSec / 3600), 1) : null,
                'occupancy' => $workSec >= 900 ? min(100, round(100 * (int) ($k->talk ?? 0) / $workSec, 1)) : null,
                'pending' => array_sum(array_map(fn ($p) => $p['pending'] + $p['callback'] + $p['locked'], $prog)),
            ],
            'agents_now' => $now,
            'by_agent' => $byAgent->map(fn ($a) => [
                'user_id' => (int) $a->user_id, 'name' => $a->username, 'calls' => (int) $a->n, 'contacts' => (int) $a->contacts, 'sales' => (int) $a->sales,
                'amount' => round((float) $a->amount, 2), 'conversion' => $a->contacts ? round(100 * $a->sales / $a->contacts, 1) : null,
                'aht_sec' => (int) round((float) $a->aht), 'work_h' => round(($workBy[$a->user_id]['work'] ?? 0) / 3600, 1),
                'pause_h' => round(($workBy[$a->user_id]['pause'] ?? 0) / 3600, 1),
                'calls_per_hour' => ($workBy[$a->user_id]['work'] ?? 0) >= 900 ? round($a->n / ($workBy[$a->user_id]['work'] / 3600), 1) : null,
                'qa' => isset($qa[$a->user_id]) ? round((float) $qa[$a->user_id], 1) : null,
            ])->sortByDesc('sales')->values(),
            'campaigns' => $campaigns->map(fn ($x) => ['id' => (int) $x->id, 'name' => $x->name, 'status' => $x->status,
                'status_label' => CallCenter::label(CallCenterCampaignsController::STATUSES, $x->status, $loc), 'progress' => $prog[$x->id] ?? null])->values(),
            'by_hour' => $hours->map(fn ($h) => ['hour' => (int) $h->h, 'calls' => (int) $h->n, 'contacts' => (int) $h->contacts, 'sales' => (int) $h->sales]),
            'results' => $results->map(fn ($r) => ['result' => $r->result, 'n' => (int) $r->n,
                'label' => $r->result ? CallCenter::label(CallCenter::RESULTS, $r->result, $loc) : ($loc === 'es' ? 'Contestada (sin resultado)' : 'Answered (no result)')]),
        ]);
    }

    // ------------------------------------------------------------------ billing

    /** What a campaign's period is billed: agent hours on it, its calls or its sales, times the campaign's rate. */
    private function quantity(object $c, Carbon $from, Carbon $to): array
    {
        $calls = DB::table('cc_calls')->where('campaign_id', $c->id)->whereBetween('started_at', [$from, $to]);

        return match ($c->billing_mode) {
            'hour' => [round(array_sum(array_column($this->attendance((int) $c->company_id, $from, $to->copy()->addSecond(), (int) $c->id), 'work')) / 3600, 2), 'h'],
            'call' => [(float) (clone $calls)->count(), 'calls'],
            'sale' => [(float) (clone $calls)->where('result', 'sale')->count(), 'sales'],
            default => [0.0, ''],
        };
    }

    private function billingInput(Request $request, int $cid): array
    {
        $d = $request->validate(['campaign_id' => 'required|integer', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $c = DB::table('cc_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->find($d['campaign_id']);
        abort_unless($c, 404);
        abort_if($c->billing_mode === 'none' || (float) $c->billing_rate <= 0, 422, __('Set how the campaign is billed and its rate first (Campaigns).'));

        return [$c, Carbon::parse($d['from'])->startOfDay(), Carbon::parse($d['to'])->endOfDay()];
    }

    public function billing(Request $request)
    {
        $this->allow($request, 'callcenter_billing');
        $cid = $this->company($request);
        $q = DB::table('cc_billings as b')->leftJoin('cc_campaigns as c', 'c.id', '=', 'b.campaign_id')->leftJoin('clients as cl', 'cl.id', '=', 'b.client_id')
            ->leftJoin('sales as s', 's.id', '=', 'b.sale_id')->leftJoin('users as u', 'u.id', '=', 'b.created_by');
        CallCenter::joinEmployee($q);
        $q->where('b.company_id', $cid)
            ->when($request->filled('campaign_id'), fn ($q) => $q->where('b.campaign_id', (int) $request->campaign_id))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('c.name', 'like', '%'.$request->search.'%')->orWhere('cl.name', 'like', '%'.$request->search.'%')->orWhere('s.Ref', 'like', '%'.$request->search.'%')))
            ->select('b.*', 'c.name as campaign', 'cl.name as client', 's.Ref as sale_ref', 's.payment_statut', 's.deleted_at as sale_deleted')
            ->selectRaw(CallCenter::nameSql().' as username');
        [$rows, $total] = $this->page($q, $request, ['created_at' => 'b.created_at', 'campaign' => 'c.name', 'client' => 'cl.name', 'amount' => 'b.amount', 'period_from' => 'b.period_from', 'id' => 'b.id'], 'b.id');
        $loc = $this->locale();

        return response()->json(['rows' => $rows->map(fn ($b) => [
            'id' => (int) $b->id, 'created_at' => $b->created_at, 'campaign' => $b->campaign, 'client' => $b->client, 'period_from' => $b->period_from,
            'period_to' => $b->period_to, 'mode' => $b->mode, 'mode_label' => CallCenter::label(CallCenterCampaignsController::BILLING, $b->mode, $loc),
            'quantity' => (float) $b->quantity, 'rate' => (float) $b->rate, 'amount' => (float) $b->amount, 'sale_id' => $b->sale_id ? (int) $b->sale_id : null,
            'sale_ref' => $b->sale_ref, 'sale_deleted' => (bool) $b->sale_deleted, 'payment_status' => $b->payment_statut, 'by' => $b->username,
        ]), 'totalRows' => $total]);
    }

    public function billingPreview(Request $request)
    {
        $this->allow($request, 'callcenter_billing');
        $cid = $this->company($request);
        [$c, $from, $to] = $this->billingInput($request, $cid);
        [$qty, $unit] = $this->quantity($c, $from, $to);
        $overlap = DB::table('cc_billings as b')->leftJoin('sales as s', 's.id', '=', 'b.sale_id')->where('b.campaign_id', $c->id)->whereNull('s.deleted_at')
            ->where('b.period_from', '<=', $to->toDateString())->where('b.period_to', '>=', $from->toDateString())->get(['b.period_from', 'b.period_to', 's.Ref']);

        return response()->json(['campaign' => $c->name, 'client_id' => $c->client_id ? (int) $c->client_id : null,
            'client' => $c->client_id ? DB::table('clients')->where('id', $c->client_id)->value('name') : null,
            'mode' => $c->billing_mode, 'mode_label' => CallCenter::label(CallCenterCampaignsController::BILLING, $c->billing_mode, $this->locale()),
            'quantity' => $qty, 'unit' => $unit, 'rate' => (float) $c->billing_rate, 'amount' => round($qty * (float) $c->billing_rate, 2), 'overlap' => $overlap]);
    }

    /** One transaction: the sale to the campaign's client (one line of the "call center service") and the billing record. */
    public function bill(Request $request)
    {
        $this->allow($request, 'callcenter_billing');
        $cid = $this->company($request);
        [$c, $from, $to] = $this->billingInput($request, $cid);
        abort_unless($c->client_id, 422, __('The campaign has no client to bill.'));
        [$qty] = $this->quantity($c, $from, $to);
        abort_if($qty <= 0, 422, __('Nothing to bill in that period.'));
        abort_if(DB::table('cc_billings as b')->leftJoin('sales as s', 's.id', '=', 'b.sale_id')->where('b.campaign_id', $c->id)->whereNull('s.deleted_at')
            ->where('b.period_from', '<=', $to->toDateString())->where('b.period_to', '>=', $from->toDateString())->exists(), 422,
            __('Part of that period is already billed.'));
        $uid = (int) $request->user('api')->id;
        $rate = (float) $c->billing_rate;
        $amount = round($qty * $rate, 2);
        $es = $this->locale() === 'es';
        $unitText = ['hour' => $es ? 'horas de agente' : 'agent hours', 'call' => $es ? 'llamadas' : 'calls', 'sale' => $es ? 'ventas' : 'sales'][$c->billing_mode];
        $note = ($es ? 'Campaña ' : 'Campaign ').$c->name.' · '.$from->format('d-m-Y').' – '.$to->format('d-m-Y').' · '.rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.').' '.$unitText;

        $res = DB::transaction(function () use ($c, $cid, $uid, $qty, $rate, $amount, $note, $from, $to) {
            $wid = (int) DB::table('warehouses')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('id')->value('id');
            abort_unless($wid, 422, __('The company has no location.'));
            $product = $this->serviceProduct($cid);
            $sale = new Sale;
            $sale->forceFill([
                'Ref' => app(SalesController::class)->getNumberOrder($wid, $cid), 'is_pos' => 0, 'company_id' => $cid, 'warehouse_id' => $wid, 'user_id' => $uid,
                'tax_method' => '1', 'discount_Method' => '2', 'shipping' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'date' => now()->toDateString(),
                'time' => now()->toTimeString(), 'client_id' => (int) $c->client_id, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'GrandTotal' => $amount,
                'statut' => 'completed', 'notes' => $note,
            ])->save();
            SaleDetail::create([
                'date' => $sale->date, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_variant_id' => null, 'sale_unit_id' => $product->unit_sale_id ?: null,
                'quantity' => $qty, 'price' => $rate, 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'total' => $amount,
                'price_type' => 'retail', 'pack_multiplier' => 1, 'note' => $note,
            ]);
            $id = DB::table('cc_billings')->insertGetId(['company_id' => $cid, 'campaign_id' => $c->id, 'client_id' => $c->client_id,
                'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'mode' => $c->billing_mode, 'quantity' => $qty, 'rate' => $rate,
                'amount' => $amount, 'sale_id' => $sale->id, 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);

            return ['id' => $id, 'sale_id' => $sale->id, 'sale_ref' => $sale->Ref, 'amount' => $amount];
        });

        return response()->json($res);
    }

    private function serviceProduct(int $cid): Product
    {
        $code = 'CC-SERV';
        $p = Product::where('company_id', $cid)->where('code', $code)->whereNull('deleted_at')->first();
        if ($p) {
            return $p;
        }
        $cat = DB::table('categories')->where('company_id', $cid)->whereNull('deleted_at')->where('name', 'Call center')->value('id')
            ?: DB::table('categories')->insertGetId(['company_id' => $cid, 'code' => 'CC', 'name' => 'Call center', 'created_at' => now(), 'updated_at' => now()]);
        $p = new Product;
        $p->forceFill(['company_id' => $cid, 'type' => 'is_service', 'code' => $code, 'Type_barcode' => 'CODE128', 'name' => 'Servicio de call center',
            'cost' => 0, 'price' => 0, 'wholesale_price' => 0, 'min_price' => 0, 'category_id' => $cat, 'TaxNet' => 0, 'tax_method' => '1', 'is_active' => 1,
            'hide_from_online_store' => 1, 'show_on_booking_form' => 0, 'note' => 'Call center billing per campaign.'])->save();

        return $p;
    }

    // ------------------------------------------------------------------ telephone exchange

    private function settingsRow(int $cid): object
    {
        $s = DB::table('cc_settings')->where('company_id', $cid)->first();
        if (! $s) {
            DB::table('cc_settings')->insert(['company_id' => $cid, 'provider' => 'none', 'webhook_token' => Str::random(40), 'created_at' => now(), 'updated_at' => now()]);
            $s = DB::table('cc_settings')->where('company_id', $cid)->first();
        }

        return $s;
    }

    public function telephony(Request $request)
    {
        $this->allow($request, 'callcenter_telephony');
        $cid = $this->company($request);
        $s = $this->settingsRow($cid);
        $ext = json_decode((string) $s->extensions, true) ?: [];
        $loc = $this->locale();

        return response()->json([
            'provider' => $s->provider, 'dial_url' => $s->dial_url,
            'webhook_url' => rtrim(PublicUrl::server(), '/').'/api/cc-hook/'.$s->webhook_token,
            'agents' => $this->agents($cid)->map(fn ($a) => ['user_id' => (int) $a->id, 'name' => $a->username, 'email' => $a->email, 'extension' => $ext[$a->id] ?? null])->values(),
            'recent' => DB::table('cc_calls as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')->where('c.company_id', $cid)->where('c.source', 'telephony')
                ->orderByDesc('c.id')->limit(20)->get(['c.id', 'c.started_at', 'c.phone', 'c.extension', 'c.direction', 'c.duration_sec', 'c.result', 'c.user_id'])
                ->map(fn ($r) => (array) $r + ['result_label' => CallCenter::label(CallCenter::RESULTS, $r->result, $loc),
                    'username' => $r->user_id ? ($this->names([$r->user_id])[$r->user_id] ?? null) : null]),
        ]);
    }

    public function saveTelephony(Request $request)
    {
        $this->allow($request, 'callcenter_telephony');
        $cid = $this->company($request);
        $d = $request->validate(['provider' => 'required|in:none,asterisk,3cx,twilio,other', 'dial_url' => 'nullable|url|max:500',
            'extensions' => 'nullable|array', 'extensions.*' => 'nullable|string|max:20']);
        $valid = $this->agents($cid)->pluck('id')->map(fn ($i) => (string) $i)->all();
        $ext = [];
        foreach ((array) ($d['extensions'] ?? []) as $uid => $e) {
            if (in_array((string) $uid, $valid, true) && trim((string) $e) !== '') {
                $ext[(string) $uid] = trim((string) $e);
            }
        }
        abort_if(count($ext) !== count(array_unique($ext)), 422, __('Two agents have the same extension.'));
        $this->settingsRow($cid);
        DB::table('cc_settings')->where('company_id', $cid)->update(['provider' => $d['provider'], 'dial_url' => $d['dial_url'] ?? null,
            'extensions' => json_encode($ext), 'updated_at' => now()]);

        return $this->telephony($request);
    }

    public function newToken(Request $request)
    {
        $this->allow($request, 'callcenter_telephony');
        $cid = $this->company($request);
        $this->settingsRow($cid);
        DB::table('cc_settings')->where('company_id', $cid)->update(['webhook_token' => Str::random(40), 'updated_at' => now()]);

        return $this->telephony($request);
    }

    /**
     * The exchange sends each finished call here (public, the token is the key). Accepts the usual names of Asterisk /
     * Issabel (uniqueid, src, dst, billsec, disposition, calldate), 3CX and Twilio (CallSid, To, From, CallDuration,
     * CallStatus, RecordingUrl) or our own (extension, phone, direction, started_at, duration, result). The agent is
     * found by the extension; the lead by the phone; a call of the lead the agent is holding is joined to it, and the
     * agent's result on the screen completes the same call.
     */
    public function hook(Request $request, string $token)
    {
        $s = DB::table('cc_settings')->where('webhook_token', $token)->first();
        abort_unless($s && strlen($token) >= 32, 404);
        $cid = (int) $s->company_id;
        $in = $request->all();
        $pick = function (array $keys) use ($in) {
            foreach ($keys as $k) {
                if (isset($in[$k]) && $in[$k] !== '') {
                    return is_scalar($in[$k]) ? (string) $in[$k] : null;
                }
            }

            return null;
        };
        $direction = strtolower((string) ($pick(['direction', 'Direction']) ?? 'outbound'));
        $direction = str_starts_with($direction, 'in') ? 'inbound' : 'outbound';
        $ext = $pick(['extension', 'ext', 'agent', 'src_ext']) ?? ($direction === 'outbound' ? $pick(['src', 'From', 'from']) : $pick(['dst', 'To', 'to']));
        $phone = $direction === 'outbound' ? $pick(['phone', 'number', 'dst', 'To', 'to']) : $pick(['phone', 'number', 'src', 'From', 'from', 'caller']);
        $extensions = array_flip(json_decode((string) $s->extensions, true) ?: []);
        $userId = $ext !== null && isset($extensions[$ext]) ? (int) $extensions[$ext] : null;
        $key = CallCenter::phoneKey($phone);
        $lead = strlen($key) >= 6 ? DB::table('cc_leads')->where('company_id', $cid)->whereNull('deleted_at')->where('phone_key', $key)->first() : null;
        $dur = (int) ($pick(['duration', 'duration_sec', 'billsec', 'CallDuration', 'talk_time']) ?? 0);
        $startedRaw = $pick(['started_at', 'start', 'calldate', 'StartTime', 'start_time']);
        try {
            $started = $startedRaw ? Carbon::parse($startedRaw) : now()->subSeconds($dur);
        } catch (\Throwable) {
            $started = now()->subSeconds($dur);
        }
        $disp = strtolower((string) ($pick(['result', 'disposition', 'CallStatus', 'status']) ?? ''));
        $result = match (true) {
            in_array($disp, ['no answer', 'no-answer', 'noanswer', 'no_answer', 'missed'], true) => 'no_answer',
            in_array($disp, ['busy'], true) => 'busy',
            in_array($disp, ['failed', 'congestion', 'canceled', 'cancelled'], true) => 'no_answer',
            isset(CallCenter::RESULTS[$disp]) => $disp,
            default => null, // answered: the agent says how it went
        };
        $externalId = $pick(['external_id', 'uniqueid', 'call_id', 'CallSid', 'id']);
        if ($externalId && DB::table('cc_calls')->where('company_id', $cid)->where('external_id', $externalId)->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }
        $held = $userId && $lead ? DB::table('cc_campaign_leads')->where('locked_by', $userId)->where('lead_id', $lead->id)->where('status', 'locked')->first() : null;
        $session = $userId ? CallCenter::openSession($userId) : null;
        $id = DB::table('cc_calls')->insertGetId([
            'company_id' => $cid, 'campaign_id' => $held->campaign_id ?? ($session->campaign_id ?? null), 'lead_id' => $lead->id ?? null,
            'campaign_lead_id' => $held->id ?? null, 'user_id' => $userId, 'direction' => $direction, 'source' => 'telephony',
            'phone' => $phone ? mb_substr($phone, 0, 40) : null, 'started_at' => $started, 'ended_at' => $started->copy()->addSeconds($dur), 'duration_sec' => $dur,
            'result' => $result, 'external_id' => $externalId ? mb_substr($externalId, 0, 120) : null,
            'recording_url' => ($r = $pick(['recording_url', 'recording', 'RecordingUrl'])) && filter_var($r, FILTER_VALIDATE_URL) ? mb_substr($r, 0, 500) : null,
            'extension' => $ext ? mb_substr($ext, 0, 20) : null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'agent' => (bool) $userId, 'lead' => (bool) $lead]);
    }
}
