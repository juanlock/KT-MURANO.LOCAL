<?php

namespace App\Http\Controllers\CallCenter;

use App\Support\CallCenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Call center campaigns (2026-11-17, user's example "campaña seguros de salud"): the campaign picks its leads by age,
 * sex, location and other values; "Load leads" puts the matching ones in its queue, and the agents call them one each.
 *
 *  GET  callcenter/campaigns            GET callcenter/campaigns/options     POST callcenter/campaigns/count {filters}
 *  POST callcenter/campaigns            PUT callcenter/campaigns/{id}        DELETE callcenter/campaigns/{id}
 *  POST callcenter/campaigns/{id}/load  POST callcenter/campaigns/{id}/status {status}
 */
class CallCenterCampaignsController extends CallCenterBase
{
    public const STATUSES = [
        'draft' => ['Draft', 'Borrador'], 'active' => ['Active', 'Activa'], 'paused' => ['Paused', 'Pausada'], 'finished' => ['Finished', 'Terminada'],
    ];

    public const BILLING = [
        'none' => ['Not billed', 'No se factura'], 'hour' => ['Per agent hour', 'Por hora de agente'],
        'call' => ['Per call', 'Por llamada'], 'sale' => ['Per sale', 'Por venta'],
    ];

    public function index(Request $request)
    {
        $this->allow($request, 'callcenter_campaigns', 'callcenter_dashboard', 'callcenter_billing');
        $cid = $this->company($request);
        $q = DB::table('cc_campaigns as c')->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->where('c.company_id', $cid)->whereNull('c.deleted_at')
            ->when($request->filled('status'), fn ($q) => $q->where('c.status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('c.name', 'like', '%'.$request->search.'%')->orWhere('cl.name', 'like', '%'.$request->search.'%')))
            ->select('c.*', 'cl.name as client_name');
        [$rows, $total] = $this->page($q, $request, ['name' => 'c.name', 'client_name' => 'cl.name', 'status' => 'c.status', 'starts_on' => 'c.starts_on',
            'created_at' => 'c.created_at', 'id' => 'c.id'], 'c.id');
        $progress = CallCenter::progress($rows->pluck('id')->all());
        $calls = DB::table('cc_calls')->whereIn('campaign_id', $rows->pluck('id'))->selectRaw('campaign_id, COUNT(*) n, MAX(started_at) last_at')
            ->groupBy('campaign_id')->get()->keyBy('campaign_id');
        $loc = $this->locale();

        return response()->json([
            'rows' => $rows->map(fn ($c) => $this->out($c, $loc) + [
                'progress' => $progress[$c->id] ?? ['total' => 0, 'pending' => 0, 'locked' => 0, 'callback' => 0, 'done' => 0, 'exhausted' => 0, 'sales' => 0, 'percent' => 0],
                'calls' => (int) ($calls[$c->id]->n ?? 0), 'last_call_at' => $calls[$c->id]->last_at ?? null,
            ]),
            'totalRows' => $total,
        ]);
    }

    private function out(object $c, string $loc): array
    {
        return [
            'id' => (int) $c->id, 'name' => $c->name, 'client_id' => $c->client_id ? (int) $c->client_id : null, 'client_name' => $c->client_name ?? null,
            'mode' => $c->mode, 'status' => $c->status, 'status_label' => CallCenter::label(self::STATUSES, $c->status, $loc),
            'script' => $c->script, 'filters' => json_decode((string) $c->filters, true) ?: (object) [],
            'starts_on' => $c->starts_on, 'ends_on' => $c->ends_on,
            'call_from' => $c->call_from ? substr($c->call_from, 0, 5) : null, 'call_to' => $c->call_to ? substr($c->call_to, 0, 5) : null,
            'max_attempts' => (int) $c->max_attempts, 'retry_minutes' => (int) $c->retry_minutes,
            'billing_mode' => $c->billing_mode, 'billing_label' => CallCenter::label(self::BILLING, $c->billing_mode, $loc), 'billing_rate' => (float) $c->billing_rate,
            'created_at' => $c->created_at,
        ];
    }

    public function options(Request $request)
    {
        $this->allow($request, 'callcenter_campaigns', 'callcenter_billing', 'callcenter_dashboard', 'callcenter_calls', 'callcenter_quality', 'callcenter_shifts');
        $cid = $this->company($request);
        $loc = $this->locale();
        $walkIn = (int) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('client_id');

        return response()->json([
            'clients' => DB::table('clients')->where('company_id', $cid)->whereNull('deleted_at')->where('id', '!=', $walkIn)
                ->orderBy('name')->get(['id', 'name']),
            'campaigns' => DB::table('cc_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'status']),
            'agents' => $this->agents($cid),
            'statuses' => collect(self::STATUSES)->map(fn ($v, $k) => ['value' => $k, 'label' => $loc === 'es' ? $v[1] : $v[0]])->values(),
            'billing' => collect(self::BILLING)->map(fn ($v, $k) => ['value' => $k, 'label' => $loc === 'es' ? $v[1] : $v[0]])->values(),
            'results' => collect(CallCenter::RESULTS)->map(fn ($v, $k) => ['value' => $k, 'label' => $loc === 'es' ? $v[1] : $v[0], 'contact' => $v[3]])->values(),
            'currency' => DB::table('settings as s')->leftJoin('currencies as c', 'c.id', '=', 's.currency_id')->where('s.company_id', $cid)->whereNull('s.deleted_at')->value('c.symbol') ?: 'S/',
        ]);
    }

    private function filters(array $f): array
    {
        $out = [];
        foreach (['age_min', 'age_max'] as $k) {
            if (isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null) {
                $out[$k] = max(0, min(120, (int) $f[$k]));
            }
        }
        if (! empty($f['gender']) && in_array($f['gender'], ['M', 'F'], true)) {
            $out['gender'] = $f['gender'];
        }
        foreach (['regions', 'provinces', 'districts', 'cities', 'sources'] as $k) {
            $vals = array_values(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 120), (array) ($f[$k] ?? [])), fn ($v) => $v !== ''));
            if ($vals) {
                $out[$k] = $vals;
            }
        }
        $extra = [];
        foreach ((array) ($f['extra'] ?? []) as $c) {
            if (trim((string) ($c['key'] ?? '')) !== '' && trim((string) ($c['value'] ?? '')) !== '') {
                $extra[] = ['key' => mb_substr(trim($c['key']), 0, 60), 'value' => mb_substr(trim($c['value']), 0, 120)];
            }
        }
        if ($extra) {
            $out['extra'] = $extra;
        }
        if (! empty($f['exclude_active'])) {
            $out['exclude_active'] = true;
        }

        return $out;
    }

    /** How many leads the filter selects (the form shows it while the filter is built). */
    public function count(Request $request)
    {
        $this->allow($request, 'callcenter_campaigns');
        $cid = $this->company($request);
        $f = $this->filters((array) $request->input('filters', []));
        if ($request->filled('campaign_id')) {
            $f['_campaign_id'] = (int) $request->campaign_id;
        }
        $q = CallCenter::leadsQuery($cid, $f);
        $new = $request->filled('campaign_id')
            ? (clone $q)->whereNotExists(fn ($s) => $s->from('cc_campaign_leads as y')->whereColumn('y.lead_id', 'l.id')->where('y.campaign_id', (int) $request->campaign_id))->count()
            : null;

        return response()->json(['count' => $q->count(), 'new' => $new]);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => 'required|string|max:150', 'client_id' => 'nullable|integer', 'mode' => 'required|in:screen,telephony',
            'script' => 'nullable|string|max:20000', 'filters' => 'nullable|array', 'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'call_from' => 'nullable|date_format:H:i', 'call_to' => 'nullable|date_format:H:i', 'max_attempts' => 'required|integer|min:1|max:20',
            'retry_minutes' => 'required|integer|min:5|max:10080', 'billing_mode' => 'required|in:none,hour,call,sale', 'billing_rate' => 'nullable|numeric|min:0|max:1000000',
        ]);
        $d['filters'] = json_encode($this->filters($d['filters'] ?? []), JSON_UNESCAPED_UNICODE);
        $d['billing_rate'] = (float) ($d['billing_rate'] ?? 0);

        return $d;
    }

    private function checkClient(int $cid, ?int $clientId): void
    {
        abort_if($clientId && ! DB::table('clients')->where('company_id', $cid)->whereNull('deleted_at')->where('id', $clientId)->exists(), 422, __('That customer is not one of this company\'s.'));
    }

    public function store(Request $request)
    {
        $this->allow($request, 'callcenter_campaigns');
        $cid = $this->company($request);
        $d = $this->validated($request);
        $this->checkClient($cid, $d['client_id'] ?? null);
        $id = DB::table('cc_campaigns')->insertGetId($d + ['company_id' => $cid, 'status' => 'draft', 'created_by' => $request->user('api')->id,
            'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id]);
    }

    public function update(Request $request, int $id)
    {
        $this->allow($request, 'callcenter_campaigns');
        $cid = $this->company($request);
        abort_unless(DB::table('cc_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->where('id', $id)->exists(), 404);
        $d = $this->validated($request);
        $this->checkClient($cid, $d['client_id'] ?? null);
        DB::table('cc_campaigns')->where('id', $id)->update($d + ['updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->allow($request, 'callcenter_campaigns');
        $cid = $this->company($request);
        abort_unless(DB::table('cc_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->where('id', $id)->exists(), 404);
        abort_if(DB::table('cc_calls')->where('campaign_id', $id)->exists(), 422, __('The campaign has calls: finish it instead of deleting it.'));
        DB::transaction(function () use ($id) {
            DB::table('cc_campaign_leads')->where('campaign_id', $id)->delete();
            DB::table('cc_campaigns')->where('id', $id)->update(['deleted_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    public function load(Request $request, int $id)
    {
        $this->allow($request, 'callcenter_campaigns');
        $cid = $this->company($request);
        $c = DB::table('cc_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->find($id);
        abort_unless($c, 404);
        abort_if($c->status === 'finished', 422, __('The campaign is finished.'));
        $added = DB::transaction(fn () => CallCenter::loadLeads($c));

        return response()->json(['added' => $added, 'progress' => CallCenter::progress([$id])[$id] ?? null]);
    }

    public function status(Request $request, int $id)
    {
        $this->allow($request, 'callcenter_campaigns');
        $cid = $this->company($request);
        $c = DB::table('cc_campaigns')->where('company_id', $cid)->whereNull('deleted_at')->find($id);
        abort_unless($c, 404);
        $status = (string) $request->validate(['status' => 'required|in:draft,active,paused,finished'])['status'];
        if ($status === 'active') {
            abort_unless(DB::table('cc_campaign_leads')->where('campaign_id', $id)->exists(), 422, __('Load the leads first.'));
        }
        DB::transaction(function () use ($id, $status) {
            DB::table('cc_campaigns')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
            // Finishing frees every lead an agent was holding.
            if ($status === 'finished') {
                DB::table('cc_campaign_leads')->where('campaign_id', $id)->where('status', 'locked')
                    ->update(['status' => 'pending', 'locked_by' => null, 'locked_at' => null, 'claim' => null, 'updated_at' => now()]);
            }
        });

        return response()->json(['ok' => true]);
    }
}
