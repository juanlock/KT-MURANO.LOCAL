<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Call center (2026-11-17): what the screens share -- the call results and what each does to the lead in the
 * campaign, the leads a campaign's filter selects, and taking the next lead so that two agents never get the same.
 */
class CallCenter
{
    /** A lead held by an agent who left without saving goes back to the pool after this many minutes. */
    public const LOCK_MINUTES = 15;

    /** A callback booked by one agent is theirs for this long after its time; then anyone takes it. */
    public const CALLBACK_GRACE_MINUTES = 30;

    /** An agent with no sign of life on the screen for this long is offline (their session is closed then). */
    public const OFFLINE_MINUTES = 5;

    /**
     * result => [English, Spanish, effect, counts as contact]. Effects: done = the lead leaves the campaign;
     * retry = tried again later (attempts, then exhausted); callback = at the time the agent set; dnc = done and never called again.
     */
    public const RESULTS = [
        'sale' => ['Sale', 'Venta', 'done', true],
        'interested' => ['Interested', 'Interesado', 'done', true],
        'callback' => ['Call back', 'Volver a llamar', 'callback', true],
        'not_interested' => ['Not interested', 'No interesado', 'done', true],
        'complaint' => ['Complaint', 'Reclamo', 'done', true],
        'no_answer' => ['No answer', 'No contesta', 'retry', false],
        'busy' => ['Busy', 'Ocupado', 'retry', false],
        'voicemail' => ['Voicemail', 'Buzón de voz', 'retry', false],
        'wrong_number' => ['Wrong number', 'Número equivocado', 'done', false],
        'do_not_call' => ['Do not call again', 'No llamar más', 'dnc', true],
    ];

    public const PAUSES = [
        'break' => ['Break', 'Descanso'],
        'lunch' => ['Lunch', 'Refrigerio'],
        'restroom' => ['Restroom', 'Servicios higiénicos'],
        'training' => ['Training / feedback', 'Capacitación / feedback'],
        'other' => ['Other', 'Otro'],
    ];

    /** Digits only, the last 9 (Peru's mobiles; a +51 / 0051 prefix doesn't make another lead). */
    public static function phoneKey(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);

        return strlen($d) > 9 ? substr($d, -9) : $d;
    }

    public static function contactResults(): array
    {
        return array_keys(array_filter(self::RESULTS, fn ($r) => $r[3]));
    }

    /** A person's name in SQL: the employee's name, else the login's username. */
    public static function nameSql(string $e = 'ue', string $u = 'u'): string
    {
        return "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', {$e}.firstname, {$e}.lastname)), ''), {$u}.username)";
    }

    /** Joins the employee behind a login ($u alias) as $e. */
    public static function joinEmployee($q, string $u = 'u', string $e = 'ue')
    {
        return $q->leftJoin("employees as {$e}", fn ($j) => $j->on("{$e}.user_id", '=', "{$u}.id")->whereNull("{$e}.deleted_at"));
    }

    /** The age in SQL: from the birth date, else the age given (grown by the years since it was given). */
    public static function ageSql(string $alias = 'l'): string
    {
        return "COALESCE(TIMESTAMPDIFF(YEAR, {$alias}.birth_date, CURDATE()), {$alias}.age + TIMESTAMPDIFF(YEAR, {$alias}.created_at, CURDATE()))";
    }

    /** The company's leads a campaign's filters select (never the "do not call" ones). */
    public static function leadsQuery(int $companyId, array $f)
    {
        $age = self::ageSql('l');
        $q = DB::table('cc_leads as l')->where('l.company_id', $companyId)->whereNull('l.deleted_at')->where('l.do_not_call', 0);
        if (($f['age_min'] ?? '') !== '' && $f['age_min'] !== null) {
            $q->whereRaw("$age >= ?", [(int) $f['age_min']]);
        }
        if (($f['age_max'] ?? '') !== '' && $f['age_max'] !== null) {
            $q->whereRaw("$age <= ?", [(int) $f['age_max']]);
        }
        if (! empty($f['gender'])) {
            $q->where('l.gender', $f['gender']);
        }
        foreach (['region', 'province', 'district', 'city', 'source'] as $col) {
            $vals = array_values(array_filter((array) ($f[$col.'s'] ?? $f[$col] ?? []), fn ($v) => $v !== null && $v !== ''));
            if ($vals) {
                $q->whereIn('l.'.$col, $vals);
            }
        }
        foreach ((array) ($f['extra'] ?? []) as $cond) {
            $key = trim((string) ($cond['key'] ?? ''));
            $val = trim((string) ($cond['value'] ?? ''));
            if ($key !== '' && $val !== '' && preg_match('/^[\pL\pN _.-]{1,60}$/u', $key)) {
                $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(l.extra, ?)) = ?', ['$."'.$key.'"', $val]);
            }
        }
        if (! empty($f['exclude_active'])) {
            $q->whereNotExists(fn ($s) => $s->from('cc_campaign_leads as x')->join('cc_campaigns as c', 'c.id', '=', 'x.campaign_id')
                ->whereColumn('x.lead_id', 'l.id')->whereNull('c.deleted_at')->whereIn('c.status', ['active', 'paused'])
                ->whereIn('x.status', ['pending', 'locked', 'callback'])->when(! empty($f['_campaign_id']), fn ($w) => $w->where('c.id', '!=', $f['_campaign_id'])));
        }

        return $q;
    }

    /** Adds the leads the filter selects that the campaign doesn't have yet; returns how many. */
    public static function loadLeads(object $campaign): int
    {
        $f = json_decode((string) $campaign->filters, true) ?: [];
        $f['_campaign_id'] = $campaign->id;
        $ids = self::leadsQuery((int) $campaign->company_id, $f)
            ->whereNotExists(fn ($s) => $s->from('cc_campaign_leads as y')->whereColumn('y.lead_id', 'l.id')->where('y.campaign_id', $campaign->id))
            ->pluck('l.id');
        $now = now();
        foreach ($ids->chunk(1000) as $chunk) {
            DB::table('cc_campaign_leads')->insertOrIgnore($chunk->map(fn ($id) => [
                'company_id' => $campaign->company_id, 'campaign_id' => $campaign->id, 'lead_id' => $id, 'status' => 'pending',
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        return $ids->count();
    }

    /**
     * The next lead for this agent, held for them alone (user's rule, 2026-11-17: "llaman a los que ya no fueron
     * marcados por otro agente"). One UPDATE ... LIMIT 1 marks it, so two agents pressing at the same instant get two
     * different leads. Order: the agent's own due callbacks, then anybody's overdue callbacks and the pending leads.
     * The lead the agent already holds comes back first (a reload never loses it).
     */
    public static function claimNext(object $campaign, int $userId): ?object
    {
        $held = DB::table('cc_campaign_leads')->where('campaign_id', $campaign->id)->where('status', 'locked')->where('locked_by', $userId)->first();
        if ($held) {
            DB::table('cc_campaign_leads')->where('id', $held->id)->update(['locked_at' => now()]);

            return $held;
        }
        $claim = (string) Str::uuid();
        $now = now();
        $lockLimit = $now->copy()->subMinutes(self::LOCK_MINUTES)->toDateTimeString();
        $grace = $now->copy()->subMinutes(self::CALLBACK_GRACE_MINUTES)->toDateTimeString();
        $nowS = $now->toDateTimeString();

        $affected = DB::update(
            "UPDATE cc_campaign_leads SET status = 'locked', locked_by = ?, locked_at = ?, claim = ?, updated_at = ?
             WHERE campaign_id = ? AND (
                 (status = 'pending' AND (next_call_at IS NULL OR next_call_at <= ?))
              OR (status = 'callback' AND next_call_at <= ? AND (callback_user_id IS NULL OR callback_user_id = ? OR next_call_at <= ?))
              OR (status = 'locked' AND locked_at < ?)
             )
             ORDER BY (status = 'callback' AND callback_user_id = ?) DESC, (status = 'callback') DESC, next_call_at IS NULL, next_call_at, id
             LIMIT 1",
            [$userId, $nowS, $claim, $nowS, $campaign->id, $nowS, $nowS, $userId, $grace, $lockLimit, $userId]
        );
        if (! $affected) {
            return null;
        }

        return DB::table('cc_campaign_leads')->where('campaign_id', $campaign->id)->where('claim', $claim)->first();
    }

    /** Where the campaign stands: leads by state. */
    public static function progress(array $campaignIds): array
    {
        $out = [];
        if (! $campaignIds) {
            return $out;
        }
        $rows = DB::table('cc_campaign_leads')->whereIn('campaign_id', $campaignIds)
            ->selectRaw("campaign_id, COUNT(*) total,
                SUM(status = 'pending') pending, SUM(status = 'locked') locked, SUM(status = 'callback') callback,
                SUM(status = 'done') done, SUM(status = 'exhausted') exhausted, SUM(last_result = 'sale') sales")
            ->groupBy('campaign_id')->get();
        foreach ($rows as $r) {
            $total = (int) $r->total;
            $closed = (int) $r->done + (int) $r->exhausted;
            $out[$r->campaign_id] = [
                'total' => $total, 'pending' => (int) $r->pending, 'locked' => (int) $r->locked, 'callback' => (int) $r->callback,
                'done' => (int) $r->done, 'exhausted' => (int) $r->exhausted, 'sales' => (int) $r->sales,
                'percent' => $total ? (int) floor(100 * $closed / $total) : 0,
            ];
        }

        return $out;
    }

    /** The agent's open session (work or pause); one left without a sign of life is closed at its last sign. */
    public static function openSession(int $userId): ?object
    {
        $s = DB::table('cc_agent_sessions')->where('user_id', $userId)->whereNull('ended_at')->orderByDesc('id')->first();
        if ($s && $s->last_seen_at && now()->diffInMinutes($s->last_seen_at, true) > self::OFFLINE_MINUTES) {
            DB::table('cc_agent_sessions')->where('id', $s->id)->update(['ended_at' => $s->last_seen_at, 'updated_at' => now()]);

            return null;
        }

        return $s;
    }

    public static function label(array $map, ?string $key, string $locale): ?string
    {
        if ($key === null || ! isset($map[$key])) {
            return $key;
        }

        return $locale === 'es' ? $map[$key][1] : $map[$key][0];
    }
}
