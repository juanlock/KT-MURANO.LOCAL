<?php

namespace App\Http\Controllers\CallCenter;

use App\Support\CallCenter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "My campaign" (2026-11-17): the agent's screen. The agent takes an active campaign and asks for the next lead; the
 * lead is held for them alone until they save the call's result (or 15 minutes pass), so with several agents nobody
 * calls a lead another one already has, until the campaign runs out of leads. Starting the first lead starts the shift
 * (work session); pauses are sessions too, with their reason (shifts, attendance and billing per hour read them).
 *
 *  GET  callcenter/work                  POST callcenter/work/session {action: start|pause|resume|stop, reason}
 *  POST callcenter/work/heartbeat        POST callcenter/work/next {campaign_id}
 *  POST callcenter/work/save             POST callcenter/work/release {campaign_lead_id}
 *  POST callcenter/work/dial {campaign_lead_id}
 */
class CallCenterWorkController extends CallCenterBase
{
    public function show(Request $request)
    {
        $this->allow($request, 'callcenter_work');
        $cid = $this->company($request);
        $uid = (int) $request->user('api')->id;
        $loc = $this->locale();
        $session = CallCenter::openSession($uid);
        $held = DB::table('cc_campaign_leads')->where('company_id', $cid)->where('status', 'locked')->where('locked_by', $uid)
            ->where('locked_at', '>=', now()->subMinutes(CallCenter::LOCK_MINUTES))->orderByDesc('locked_at')->first();

        $campaigns = $this->available($cid);
        $progress = CallCenter::progress($campaigns->pluck('id')->all());
        $settings = DB::table('cc_settings')->where('company_id', $cid)->first();
        $ext = $settings ? ((json_decode((string) $settings->extensions, true) ?: [])[$uid] ?? null) : null;

        return response()->json([
            'session' => $session ? ['kind' => $session->kind, 'reason' => $session->reason, 'reason_label' => CallCenter::label(CallCenter::PAUSES, $session->reason, $loc),
                'since' => $session->started_at, 'campaign_id' => $session->campaign_id ? (int) $session->campaign_id : null] : null,
            'campaigns' => $campaigns->map(fn ($c) => [
                'id' => (int) $c->id, 'name' => $c->name, 'client_name' => $c->client_name, 'mode' => $c->mode, 'script' => $c->script,
                'call_from' => $c->call_from ? substr($c->call_from, 0, 5) : null, 'call_to' => $c->call_to ? substr($c->call_to, 0, 5) : null,
                'progress' => $progress[$c->id] ?? null,
            ])->values(),
            'current' => $held ? $this->leadCard($held, $loc) : null,
            'today' => $this->today($uid),
            'results' => collect(CallCenter::RESULTS)->map(fn ($v, $k) => ['value' => $k, 'label' => $loc === 'es' ? $v[1] : $v[0], 'effect' => $v[2]])->values(),
            'pauses' => collect(CallCenter::PAUSES)->map(fn ($v, $k) => ['value' => $k, 'label' => $loc === 'es' ? $v[1] : $v[0]])->values(),
            'telephony' => ['dial' => (bool) ($settings && $settings->dial_url && $ext), 'extension' => $ext],
            'currency' => DB::table('settings as s')->leftJoin('currencies as c', 'c.id', '=', 's.currency_id')->where('s.company_id', $cid)->whereNull('s.deleted_at')->value('c.symbol') ?: 'S/',
        ]);
    }

    /** Active campaigns on today's date. */
    private function available(int $cid)
    {
        $today = now()->toDateString();

        return DB::table('cc_campaigns as c')->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->where('c.company_id', $cid)->whereNull('c.deleted_at')->where('c.status', 'active')
            ->where(fn ($q) => $q->whereNull('c.starts_on')->orWhere('c.starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('c.ends_on')->orWhere('c.ends_on', '>=', $today))
            ->orderBy('c.name')->get(['c.*', 'cl.name as client_name']);
    }

    private function today(int $uid): array
    {
        $calls = DB::table('cc_calls')->where('user_id', $uid)->whereDate('started_at', now()->toDateString());
        $contacts = CallCenter::contactResults();
        $worked = 0;
        $paused = 0;
        foreach (DB::table('cc_agent_sessions')->where('user_id', $uid)->where('started_at', '>=', now()->startOfDay())->get() as $s) {
            $end = $s->ended_at ? Carbon::parse($s->ended_at) : now();
            $sec = max(0, Carbon::parse($s->started_at)->diffInSeconds($end, true));
            $s->kind === 'pause' ? $paused += $sec : $worked += $sec;
        }

        return [
            'calls' => (clone $calls)->count(),
            'contacts' => (clone $calls)->whereIn('result', $contacts)->count(),
            'sales' => (clone $calls)->where('result', 'sale')->count(),
            'talk_sec' => (int) (clone $calls)->sum('duration_sec'),
            'worked_sec' => (int) $worked, 'paused_sec' => (int) $paused,
            'recent' => DB::table('cc_calls as c')->leftJoin('cc_leads as l', 'l.id', '=', 'c.lead_id')->where('c.user_id', $uid)
                ->whereDate('c.started_at', now()->toDateString())->orderByDesc('c.id')->limit(8)
                ->get(['c.id', 'c.started_at', 'c.result', 'c.duration_sec', 'l.first_name', 'l.last_name', 'c.phone'])
                ->map(fn ($r) => ['id' => $r->id, 'at' => $r->started_at, 'result' => $r->result,
                    'result_label' => CallCenter::label(CallCenter::RESULTS, $r->result, $this->locale()),
                    'name' => trim($r->first_name.' '.$r->last_name), 'phone' => $r->phone, 'duration_sec' => (int) $r->duration_sec]),
        ];
    }

    /** What the agent sees of the lead: its data, extra columns and earlier calls. */
    private function leadCard(object $cl, string $loc): array
    {
        $l = DB::table('cc_leads')->find($cl->lead_id);
        $age = $l->birth_date ? Carbon::parse($l->birth_date)->age : ($l->age !== null ? $l->age + Carbon::parse($l->created_at)->diffInYears(now()) : null);
        $history = DB::table('cc_calls as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')->leftJoin('cc_campaigns as k', 'k.id', '=', 'c.campaign_id')
            ->where('c.lead_id', $l->id)->orderByDesc('c.id')->limit(10)
            ->get(['c.started_at', 'c.result', 'c.notes', 'c.duration_sec', 'c.callback_at', 'c.user_id', 'k.name as campaign']);
        $names = $this->names($history->pluck('user_id')->all());
        $history->each(fn ($h) => $h->username = $names[$h->user_id] ?? null);

        return [
            'campaign_lead_id' => (int) $cl->id, 'campaign_id' => (int) $cl->campaign_id, 'attempts' => (int) $cl->attempts,
            'is_callback' => $cl->callback_user_id !== null && $cl->next_call_at !== null,
            'next_call_at' => $cl->next_call_at, 'locked_at' => $cl->locked_at,
            'lead' => [
                'id' => (int) $l->id, 'name' => trim($l->first_name.' '.$l->last_name), 'phone' => $l->phone, 'phone2' => $l->phone2, 'email' => $l->email,
                'document' => $l->document, 'age' => $age !== null ? (int) $age : null, 'gender' => $l->gender, 'birth_date' => $l->birth_date,
                'location' => implode(' · ', array_filter([$l->district, $l->province, $l->region, $l->city !== $l->province ? $l->city : null])),
                'address' => $l->address, 'source' => $l->source, 'notes' => $l->notes, 'extra' => json_decode((string) $l->extra, true) ?: (object) [],
            ],
            'history' => $history->map(fn ($h) => ['at' => $h->started_at, 'agent' => $h->username, 'campaign' => $h->campaign,
                'result' => $h->result, 'result_label' => CallCenter::label(CallCenter::RESULTS, $h->result, $loc), 'notes' => $h->notes,
                'duration_sec' => (int) $h->duration_sec, 'callback_at' => $h->callback_at]),
        ];
    }

    public function session(Request $request)
    {
        $this->allow($request, 'callcenter_work');
        $cid = $this->company($request);
        $uid = (int) $request->user('api')->id;
        $d = $request->validate(['action' => 'required|in:start,pause,resume,stop', 'reason' => 'nullable|in:'.implode(',', array_keys(CallCenter::PAUSES)),
            'campaign_id' => 'nullable|integer']);
        DB::transaction(function () use ($d, $cid, $uid) {
            $open = CallCenter::openSession($uid);
            $now = now();
            $close = fn () => $open ? DB::table('cc_agent_sessions')->where('id', $open->id)->update(['ended_at' => $now, 'updated_at' => $now]) : null;
            $campaign = $d['campaign_id'] ?? ($open->campaign_id ?? null);
            $new = fn ($kind, $reason = null) => DB::table('cc_agent_sessions')->insert(['company_id' => $cid, 'user_id' => $uid, 'campaign_id' => $campaign,
                'kind' => $kind, 'reason' => $reason, 'started_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            switch ($d['action']) {
                case 'start':
                case 'resume':
                    if ($open && $open->kind === 'work') {
                        return;
                    }
                    $close();
                    $new('work');
                    break;
                case 'pause':
                    abort_if(empty($d['reason']), 422, __('Choose the reason for the pause.'));
                    $close();
                    $new('pause', $d['reason']);
                    // A lead in hand goes back to the pool for the other agents.
                    DB::table('cc_campaign_leads')->where('locked_by', $uid)->where('status', 'locked')
                        ->update(['status' => 'pending', 'locked_by' => null, 'locked_at' => null, 'claim' => null, 'updated_at' => $now]);
                    break;
                case 'stop':
                    $close();
                    DB::table('cc_campaign_leads')->where('locked_by', $uid)->where('status', 'locked')
                        ->update(['status' => 'pending', 'locked_by' => null, 'locked_at' => null, 'claim' => null, 'updated_at' => $now]);
                    break;
            }
        });

        return $this->show($request);
    }

    /** The screen says it is open (every 30 s): keeps the session alive and the lead held. */
    public function heartbeat(Request $request)
    {
        $this->allow($request, 'callcenter_work');
        $this->company($request);
        $uid = (int) $request->user('api')->id;
        $open = CallCenter::openSession($uid);
        if ($open) {
            DB::table('cc_agent_sessions')->where('id', $open->id)->update(['last_seen_at' => now()]);
        }
        DB::table('cc_campaign_leads')->where('locked_by', $uid)->where('status', 'locked')->update(['locked_at' => now()]);

        return response()->json(['ok' => true, 'session' => $open ? $open->kind : null]);
    }

    public function next(Request $request)
    {
        $this->allow($request, 'callcenter_work');
        $cid = $this->company($request);
        $uid = (int) $request->user('api')->id;
        $campaign = $this->available($cid)->firstWhere('id', (int) $request->input('campaign_id'));
        abort_unless($campaign, 422, __('This campaign is not active.'));
        if ($campaign->call_from && $campaign->call_to) {
            $t = now()->format('H:i:s');
            abort_if($t < $campaign->call_from || $t > $campaign->call_to, 422,
                __('Outside the campaign\'s calling hours (:from - :to).', ['from' => substr($campaign->call_from, 0, 5), 'to' => substr($campaign->call_to, 0, 5)]));
        }
        $open = CallCenter::openSession($uid);
        abort_if($open && $open->kind === 'pause', 422, __('You are on a pause: come back first.'));

        $cl = DB::transaction(function () use ($open, $cid, $uid, $campaign) {
            // The first lead starts the shift; another campaign starts a new work session on it.
            if (! $open || (int) $open->campaign_id !== (int) $campaign->id) {
                if ($open) {
                    DB::table('cc_agent_sessions')->where('id', $open->id)->update(['ended_at' => now(), 'updated_at' => now()]);
                }
                DB::table('cc_agent_sessions')->insert(['company_id' => $cid, 'user_id' => $uid, 'campaign_id' => $campaign->id, 'kind' => 'work',
                    'started_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            // A lead of another campaign still in hand goes back first.
            DB::table('cc_campaign_leads')->where('locked_by', $uid)->where('status', 'locked')->where('campaign_id', '!=', $campaign->id)
                ->update(['status' => 'pending', 'locked_by' => null, 'locked_at' => null, 'claim' => null, 'updated_at' => now()]);

            return CallCenter::claimNext($campaign, $uid);
        });

        $progress = CallCenter::progress([(int) $campaign->id])[$campaign->id] ?? null;
        if (! $cl) {
            return response()->json(['current' => null, 'progress' => $progress,
                'message' => ($progress['callback'] ?? 0) + ($progress['pending'] ?? 0) > 0
                    ? __('No lead is due right now: the rest are callbacks or retries for later.')
                    : __('The campaign has no leads left to call.')]);
        }

        return response()->json(['current' => $this->leadCard($cl, $this->locale()), 'progress' => $progress]);
    }

    public function save(Request $request)
    {
        $this->allow($request, 'callcenter_work');
        $cid = $this->company($request);
        $uid = (int) $request->user('api')->id;
        $d = $request->validate([
            'campaign_lead_id' => 'required|integer', 'result' => 'required|in:'.implode(',', array_keys(CallCenter::RESULTS)),
            'notes' => 'nullable|string|max:5000', 'callback_at' => 'nullable|date|required_if:result,callback', 'sale_amount' => 'nullable|numeric|min:0|max:10000000',
            'started_at' => 'nullable|date', 'duration_sec' => 'nullable|integer|min:0|max:86400', 'phone' => 'nullable|string|max:40',
        ]);
        $cl = DB::table('cc_campaign_leads')->where('company_id', $cid)->find($d['campaign_lead_id']);
        abort_unless($cl && $cl->status === 'locked' && (int) $cl->locked_by === $uid, 409,
            __('This lead is no longer yours (it was released after 15 minutes without saving). Ask for the next one.'));
        if ($d['result'] === 'callback') {
            abort_if(Carbon::parse($d['callback_at'])->lt(now()->subMinute()), 422, __('The callback time must be in the future.'));
        }
        $campaign = DB::table('cc_campaigns')->find($cl->campaign_id);
        $lead = DB::table('cc_leads')->find($cl->lead_id);
        $effect = CallCenter::RESULTS[$d['result']][2];

        $callId = DB::transaction(function () use ($d, $cl, $cid, $uid, $campaign, $lead, $effect) {
            $started = ! empty($d['started_at']) ? Carbon::parse($d['started_at']) : now()->subSeconds((int) ($d['duration_sec'] ?? 0));
            $dur = (int) ($d['duration_sec'] ?? max(0, $started->diffInSeconds(now(), true)));
            // The exchange already sent this call (telephony campaigns): the agent's result completes it, no second call.
            $fromExchange = DB::table('cc_calls')->where('campaign_lead_id', $cl->id)->where('source', 'telephony')->where('user_id', $uid)
                ->where('created_at', '>=', Carbon::parse($cl->locked_at)->subMinutes(CallCenter::LOCK_MINUTES))->orderByDesc('id')->first();
            if ($fromExchange) {
                DB::table('cc_calls')->where('id', $fromExchange->id)->update([
                    'result' => $d['result'], 'callback_at' => $d['result'] === 'callback' ? Carbon::parse($d['callback_at']) : null,
                    'sale_amount' => $d['result'] === 'sale' && isset($d['sale_amount']) ? (float) $d['sale_amount'] : null,
                    'notes' => $d['notes'] ?? null, 'updated_at' => now(),
                ]);
                $callId = (int) $fromExchange->id;
            }
            $callId = $callId ?? DB::table('cc_calls')->insertGetId([
                'company_id' => $cid, 'campaign_id' => $cl->campaign_id, 'lead_id' => $cl->lead_id, 'campaign_lead_id' => $cl->id, 'user_id' => $uid,
                'direction' => 'outbound', 'source' => 'screen', 'phone' => $d['phone'] ?? $lead->phone, 'started_at' => $started, 'ended_at' => $started->copy()->addSeconds($dur),
                'duration_sec' => $dur, 'result' => $d['result'], 'callback_at' => $d['result'] === 'callback' ? Carbon::parse($d['callback_at']) : null,
                'sale_amount' => $d['result'] === 'sale' && isset($d['sale_amount']) ? (float) $d['sale_amount'] : null,
                'notes' => $d['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $attempts = (int) $cl->attempts + 1;
            $upd = ['attempts' => $attempts, 'locked_by' => null, 'locked_at' => null, 'claim' => null, 'last_result' => $d['result'],
                'last_user_id' => $uid, 'last_call_id' => $callId, 'callback_user_id' => null, 'next_call_at' => null, 'updated_at' => now()];
            switch ($effect) {
                case 'callback':
                    $upd += ['status' => 'callback'];
                    $upd['next_call_at'] = Carbon::parse($d['callback_at']);
                    $upd['callback_user_id'] = $uid;
                    break;
                case 'retry':
                    if ($attempts >= (int) $campaign->max_attempts) {
                        $upd += ['status' => 'exhausted'];
                    } else {
                        $upd += ['status' => 'pending'];
                        $upd['next_call_at'] = now()->addMinutes((int) $campaign->retry_minutes);
                    }
                    break;
                case 'dnc':
                    DB::table('cc_leads')->where('id', $lead->id)->update(['do_not_call' => 1, 'updated_at' => now()]);
                    DB::table('cc_campaign_leads')->where('lead_id', $lead->id)->where('id', '!=', $cl->id)->whereIn('status', ['pending', 'callback'])
                        ->update(['status' => 'done', 'last_result' => 'do_not_call', 'updated_at' => now()]);
                    $upd += ['status' => 'done'];
                    break;
                default:
                    $upd += ['status' => 'done'];
            }
            DB::table('cc_campaign_leads')->where('id', $cl->id)->update($upd);
            // The screen's data changes the agent made (e.g. a corrected email) are kept on the lead.
            return $callId;
        });

        return response()->json(['ok' => true, 'call_id' => $callId, 'today' => $this->today($uid),
            'progress' => CallCenter::progress([(int) $cl->campaign_id])[$cl->campaign_id] ?? null]);
    }

    /** Give the lead back without a call (it goes back to the pool). */
    public function release(Request $request)
    {
        $this->allow($request, 'callcenter_work');
        $cid = $this->company($request);
        $uid = (int) $request->user('api')->id;
        $id = (int) $request->input('campaign_lead_id');
        $cl = DB::table('cc_campaign_leads')->where('company_id', $cid)->find($id);
        abort_unless($cl && (int) $cl->locked_by === $uid && $cl->status === 'locked', 409, __('This lead is no longer yours.'));
        DB::table('cc_campaign_leads')->where('id', $id)->update([
            'status' => $cl->callback_user_id ? 'callback' : 'pending', 'locked_by' => null, 'locked_at' => null, 'claim' => null, 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** Click to call through the exchange: the company's dial address with {extension} and {phone}. */
    public function dial(Request $request)
    {
        $this->allow($request, 'callcenter_work');
        $cid = $this->company($request);
        $uid = (int) $request->user('api')->id;
        $cl = DB::table('cc_campaign_leads')->where('company_id', $cid)->find((int) $request->input('campaign_lead_id'));
        abort_unless($cl && (int) $cl->locked_by === $uid, 409, __('This lead is no longer yours.'));
        $s = DB::table('cc_settings')->where('company_id', $cid)->first();
        $ext = $s ? ((json_decode((string) $s->extensions, true) ?: [])[$uid] ?? null) : null;
        abort_unless($s && $s->dial_url && $ext, 422, __('The telephone exchange is not set up for your login (extension).'));
        $phone = preg_replace('/[^\d+]/', '', (string) ($request->input('phone') ?: DB::table('cc_leads')->where('id', $cl->lead_id)->value('phone')));
        $url = strtr($s->dial_url, ['{extension}' => rawurlencode($ext), '{phone}' => rawurlencode($phone)]);
        try {
            $res = Http::timeout(8)->get($url);
            abort_unless($res->successful(), 502, __('The telephone exchange answered :s.', ['s' => $res->status()]));
        } catch (\Illuminate\Http\Client\ConnectionException) {
            abort(502, __('The telephone exchange did not answer.'));
        }

        return response()->json(['ok' => true]);
    }
}
