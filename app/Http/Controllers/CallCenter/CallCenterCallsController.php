<?php

namespace App\Http\Controllers\CallCenter;

use App\Support\CallCenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Call log and quality (2026-11-17).
 *
 *  GET  callcenter/calls                      every call (agent, campaign, lead, result, duration, review score)
 *  GET  callcenter/quality/criteria           POST callcenter/quality/criteria {rows}
 *  GET  callcenter/quality/reviews            POST callcenter/quality/reviews {call_id, scores, comments}
 *  DELETE callcenter/quality/reviews/{id}
 */
class CallCenterCallsController extends CallCenterBase
{
    /** The first time a company opens Quality it gets a usual call scorecard (editable). */
    private const DEFAULT_CRITERIA = [
        ['Saludo e identificación', 1], ['Uso del guion', 2], ['Escucha y empatía', 2], ['Manejo de objeciones', 2],
        ['Información correcta del producto', 2], ['Cierre y despedida', 1],
    ];

    public function index(Request $request)
    {
        $this->allow($request, 'callcenter_calls', 'callcenter_quality');
        $cid = $this->company($request);
        $q = DB::table('cc_calls as c')->leftJoin('cc_leads as l', 'l.id', '=', 'c.lead_id')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('cc_campaigns as k', 'k.id', '=', 'c.campaign_id')->leftJoin('cc_qa_reviews as r', 'r.call_id', '=', 'c.id');
        CallCenter::joinEmployee($q);
        $q->where('c.company_id', $cid)
            ->when($request->filled('from'), fn ($q) => $q->where('c.started_at', '>=', $request->from.' 00:00:00'))
            ->when($request->filled('to'), fn ($q) => $q->where('c.started_at', '<=', $request->to.' 23:59:59'))
            ->when($request->filled('campaign_id'), fn ($q) => $q->where('c.campaign_id', (int) $request->campaign_id))
            ->when($request->filled('user_id'), fn ($q) => $q->where('c.user_id', (int) $request->user_id))
            ->when($request->filled('result'), fn ($q) => $q->where('c.result', $request->result))
            ->when($request->filled('source'), fn ($q) => $q->where('c.source', $request->source))
            ->when($request->input('reviewed') === '1', fn ($q) => $q->whereNotNull('r.id'))
            ->when($request->input('reviewed') === '0', fn ($q) => $q->whereNull('r.id'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.trim($request->search).'%';
                $q->where(fn ($w) => $w->where('l.first_name', 'like', $s)->orWhere('l.last_name', 'like', $s)->orWhere('c.phone', 'like', $s)->orWhere('c.notes', 'like', $s));
            })
            ->select('c.*', 'l.first_name', 'l.last_name', 'k.name as campaign', 'r.score as qa_score', 'r.id as review_id')
            ->selectRaw(CallCenter::nameSql().' as username');
        $summary = (clone $q)->reorder()->select(DB::raw('COUNT(*) n, SUM(c.duration_sec) talk, SUM(c.result = \'sale\') sales, SUM(c.sale_amount) amount'))->first();
        [$rows, $total] = $this->page($q, $request, ['started_at' => 'c.started_at', 'username' => 'username', 'campaign' => 'k.name', 'result' => 'c.result',
            'duration_sec' => 'c.duration_sec', 'sale_amount' => 'c.sale_amount', 'qa_score' => 'r.score', 'id' => 'c.id'], 'c.started_at');
        $loc = $this->locale();

        return response()->json([
            'rows' => $rows->map(fn ($c) => [
                'id' => (int) $c->id, 'started_at' => $c->started_at, 'username' => $c->username, 'user_id' => $c->user_id ? (int) $c->user_id : null,
                'campaign' => $c->campaign, 'campaign_id' => $c->campaign_id ? (int) $c->campaign_id : null,
                'lead' => trim($c->first_name.' '.$c->last_name), 'lead_id' => $c->lead_id ? (int) $c->lead_id : null, 'phone' => $c->phone,
                'direction' => $c->direction, 'source' => $c->source, 'result' => $c->result, 'result_label' => CallCenter::label(CallCenter::RESULTS, $c->result, $loc),
                'duration_sec' => (int) $c->duration_sec, 'sale_amount' => $c->sale_amount !== null ? (float) $c->sale_amount : null,
                'callback_at' => $c->callback_at, 'notes' => $c->notes, 'recording_url' => $c->recording_url, 'extension' => $c->extension,
                'qa_score' => $c->qa_score !== null ? (float) $c->qa_score : null, 'review_id' => $c->review_id ? (int) $c->review_id : null,
            ]),
            'totalRows' => $total,
            'summary' => ['calls' => (int) ($summary->n ?? 0), 'talk_sec' => (int) ($summary->talk ?? 0), 'sales' => (int) ($summary->sales ?? 0), 'amount' => round((float) ($summary->amount ?? 0), 2)],
        ]);
    }

    // ------------------------------------------------------------------ quality

    private function criteriaOf(int $cid)
    {
        if (! DB::table('cc_qa_criteria')->where('company_id', $cid)->exists()) {
            foreach (self::DEFAULT_CRITERIA as $i => [$name, $weight]) {
                DB::table('cc_qa_criteria')->insert(['company_id' => $cid, 'name' => $name, 'weight' => $weight, 'sort' => $i, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return DB::table('cc_qa_criteria')->where('company_id', $cid)->orderBy('sort')->orderBy('id')->get(['id', 'name', 'weight', 'sort', 'active']);
    }

    public function criteria(Request $request)
    {
        $this->allow($request, 'callcenter_quality');
        $cid = $this->company($request);

        return response()->json(['rows' => $this->criteriaOf($cid)]);
    }

    public function saveCriteria(Request $request)
    {
        $this->allow($request, 'callcenter_quality');
        $cid = $this->company($request);
        $rows = $request->validate(['rows' => 'required|array|min:1|max:30', 'rows.*.id' => 'nullable|integer', 'rows.*.name' => 'required|string|max:150',
            'rows.*.weight' => 'required|integer|min:1|max:10', 'rows.*.active' => 'nullable|boolean'])['rows'];
        DB::transaction(function () use ($rows, $cid) {
            $keep = [];
            foreach (array_values($rows) as $i => $r) {
                $data = ['name' => $r['name'], 'weight' => (int) $r['weight'], 'sort' => $i, 'active' => (bool) ($r['active'] ?? true), 'updated_at' => now()];
                if (! empty($r['id']) && DB::table('cc_qa_criteria')->where('company_id', $cid)->where('id', $r['id'])->exists()) {
                    DB::table('cc_qa_criteria')->where('id', $r['id'])->update($data);
                    $keep[] = (int) $r['id'];
                } else {
                    $keep[] = DB::table('cc_qa_criteria')->insertGetId($data + ['company_id' => $cid, 'created_at' => now()]);
                }
            }
            // A criterion used in reviews is switched off, never removed (old scores keep their meaning).
            $used = [];
            foreach (DB::table('cc_qa_reviews')->where('company_id', $cid)->pluck('scores') as $s) {
                $used += array_flip(array_keys(json_decode((string) $s, true) ?: []));
            }
            foreach (DB::table('cc_qa_criteria')->where('company_id', $cid)->whereNotIn('id', $keep)->pluck('id') as $gone) {
                isset($used[$gone]) ? DB::table('cc_qa_criteria')->where('id', $gone)->update(['active' => 0])
                    : DB::table('cc_qa_criteria')->where('id', $gone)->delete();
            }
        });

        return response()->json(['rows' => $this->criteriaOf($cid)]);
    }

    public function reviews(Request $request)
    {
        $this->allow($request, 'callcenter_quality');
        $cid = $this->company($request);
        $q = DB::table('cc_qa_reviews as r')->join('cc_calls as c', 'c.id', '=', 'r.call_id')->leftJoin('users as a', 'a.id', '=', 'r.agent_id')
            ->leftJoin('users as v', 'v.id', '=', 'r.reviewer_id')->leftJoin('cc_leads as l', 'l.id', '=', 'c.lead_id')->leftJoin('cc_campaigns as k', 'k.id', '=', 'c.campaign_id');
        CallCenter::joinEmployee($q, 'a', 'ae');
        CallCenter::joinEmployee($q, 'v', 've');
        $q->where('r.company_id', $cid)
            ->when($request->filled('from'), fn ($q) => $q->where('r.created_at', '>=', $request->from.' 00:00:00'))
            ->when($request->filled('to'), fn ($q) => $q->where('r.created_at', '<=', $request->to.' 23:59:59'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('r.agent_id', (int) $request->user_id))
            ->when($request->filled('campaign_id'), fn ($q) => $q->where('c.campaign_id', (int) $request->campaign_id))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('r.comments', 'like', '%'.$request->search.'%')->orWhere('a.username', 'like', '%'.$request->search.'%')))
            ->select('r.*', 'c.started_at', 'c.result', 'c.duration_sec', 'l.first_name', 'l.last_name', 'k.name as campaign')
            ->selectRaw(CallCenter::nameSql('ae', 'a').' as agent, '.CallCenter::nameSql('ve', 'v').' as reviewer');
        $byAgent = (clone $q)->reorder()->groupBy('r.agent_id')->select(DB::raw('r.agent_id, MAX('.CallCenter::nameSql('ae', 'a').') username, COUNT(*) n, AVG(r.score) avg'))->get();
        [$rows, $total] = $this->page($q, $request, ['created_at' => 'r.created_at', 'agent' => 'agent', 'score' => 'r.score', 'started_at' => 'c.started_at', 'id' => 'r.id'], 'r.id');
        $loc = $this->locale();

        return response()->json([
            'rows' => $rows->map(fn ($r) => [
                'id' => (int) $r->id, 'call_id' => (int) $r->call_id, 'created_at' => $r->created_at, 'agent' => $r->agent, 'reviewer' => $r->reviewer,
                'started_at' => $r->started_at, 'campaign' => $r->campaign, 'lead' => trim($r->first_name.' '.$r->last_name),
                'result_label' => CallCenter::label(CallCenter::RESULTS, $r->result, $loc), 'duration_sec' => (int) $r->duration_sec,
                'score' => (float) $r->score, 'scores' => json_decode((string) $r->scores, true) ?: (object) [], 'comments' => $r->comments,
            ]),
            'totalRows' => $total,
            'by_agent' => $byAgent->map(fn ($a) => ['user_id' => (int) $a->agent_id, 'agent' => $a->username, 'reviews' => (int) $a->n, 'avg' => round((float) $a->avg, 1)])
                ->sortByDesc('avg')->values(),
        ]);
    }

    /** Scores 0-5 per criterion; the review's score = the weighted %. One review per call (saving again replaces it). */
    public function saveReview(Request $request)
    {
        $this->allow($request, 'callcenter_quality');
        $cid = $this->company($request);
        $d = $request->validate(['call_id' => 'required|integer', 'scores' => 'required|array', 'scores.*' => 'integer|min:0|max:5', 'comments' => 'nullable|string|max:5000']);
        $call = DB::table('cc_calls')->where('company_id', $cid)->find($d['call_id']);
        abort_unless($call, 404);
        abort_if((int) $call->user_id === (int) $request->user('api')->id, 422, __('Nobody reviews their own calls.'));
        $criteria = DB::table('cc_qa_criteria')->where('company_id', $cid)->where('active', 1)->get(['id', 'weight']);
        $scores = [];
        $got = 0;
        $max = 0;
        foreach ($criteria as $c) {
            abort_unless(array_key_exists($c->id, $d['scores']), 422, __('Score every criterion.'));
            $scores[$c->id] = (int) $d['scores'][$c->id];
            $got += $scores[$c->id] * $c->weight;
            $max += 5 * $c->weight;
        }
        $score = $max ? round(100 * $got / $max, 1) : 0;
        DB::table('cc_qa_reviews')->updateOrInsert(['call_id' => $call->id], [
            'company_id' => $cid, 'agent_id' => $call->user_id, 'reviewer_id' => $request->user('api')->id, 'scores' => json_encode($scores),
            'score' => $score, 'comments' => $d['comments'] ?? null, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'score' => $score]);
    }

    public function deleteReview(Request $request, int $id)
    {
        $this->allow($request, 'callcenter_quality');
        $cid = $this->company($request);
        abort_unless(DB::table('cc_qa_reviews')->where('company_id', $cid)->where('id', $id)->delete(), 404);

        return response()->json(['ok' => true]);
    }
}
