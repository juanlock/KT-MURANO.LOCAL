<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\HelpdeskMessage;
use App\Models\HelpdeskSetting;
use App\Models\HelpdeskTicket;
use App\Services\Helpdesk\Helpdesk;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Services\Helpdesk\HelpdeskMailFetcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Helpdesk for agents (helpdesk_view) and managers (helpdesk_manage), per
 * company (resolveActingCompanyId: SuperAdmin follows the company switcher).
 *
 *  GET    helpdesk/dashboard
 *  GET    helpdesk/tickets                 ?status&priority&requester_type&assigned(me|none|id)&q&page&per_page
 *  POST   helpdesk/tickets                 agent opens a ticket for someone
 *  GET    helpdesk/tickets/{id}
 *  PUT    helpdesk/tickets/{id}            status, priority, category, assigned_to
 *  POST   helpdesk/tickets/{id}/reply      body, is_internal, status?, files[]
 *  GET    helpdesk/messages/{id}/attachments/{i}
 *  DELETE helpdesk/tickets/{id}            (manage)
 *  GET|PUT helpdesk/settings               (manage)
 *  POST   helpdesk/settings/test           (manage) test the mailbox login
 *  POST   helpdesk/settings/fetch          (manage) import new emails now
 */
class HelpdeskAdminController extends Controller
{
    use ScopesToCompany;

    private function companyId(Request $request, string $perm = 'helpdesk_view'): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), $perm), 403);
        $companyId = (int) $this->resolveActingCompanyId($request);
        \App\Support\ModuleFlags::abortUnlessEnabled($companyId, 'helpdesk');

        return $companyId;
    }

    private function ticket(int $companyId, $id): HelpdeskTicket
    {
        $t = HelpdeskTicket::findOrFail($id);
        abort_unless((int) $t->company_id === $companyId, 404);

        return $t;
    }

    public function dashboard(Request $request)
    {
        $companyId = $this->companyId($request);
        $uid = $request->user('api')->id;
        $base = fn () => HelpdeskTicket::where('company_id', $companyId);

        $byStatus = $base()->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status');
        $bySource = $base()->select('source', DB::raw('count(*) c'))->groupBy('source')->pluck('c', 'source');
        $byRequester = $base()->select('requester_type', DB::raw('count(*) c'))->groupBy('requester_type')->pluck('c', 'requester_type');
        $avgFirst = $base()->whereNotNull('first_response_at')->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) m')->value('m');

        return response()->json([
            'status' => $byStatus,
            'source' => $bySource,
            'requester' => $byRequester,
            'mine_open' => $base()->where('assigned_to', $uid)->whereIn('status', ['open', 'pending'])->count(),
            'unassigned_open' => $base()->whereNull('assigned_to')->whereIn('status', ['open', 'pending'])->count(),
            'urgent_open' => $base()->where('priority', 'urgent')->whereIn('status', ['open', 'pending'])->count(),
            'created_7d' => $base()->where('created_at', '>=', now()->subDays(7))->count(),
            'resolved_7d' => $base()->where('resolved_at', '>=', now()->subDays(7))->count(),
            'avg_first_response_min' => $avgFirst !== null ? (int) round($avgFirst) : null,
            'recent' => $base()->whereIn('status', ['open', 'pending'])->orderByDesc('last_message_at')->limit(8)
                ->get(['id', 'number', 'subject', 'status', 'priority', 'requester_name', 'requester_email', 'requester_type', 'last_message_at']),
            'mailbox' => $this->mailboxState($companyId),
        ]);
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $q = HelpdeskTicket::where('company_id', $companyId)->with('assignee:id,firstname,lastname');
        foreach (['status', 'priority', 'requester_type', 'category', 'source'] as $f) {
            if ($v = $request->query($f)) {
                $q->whereIn($f, (array) $v);
            }
        }
        if ($a = $request->query('assigned')) {
            $a === 'me' ? $q->where('assigned_to', $request->user('api')->id)
                : ($a === 'none' ? $q->whereNull('assigned_to') : $q->where('assigned_to', (int) $a));
        }
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('number', 'like', "%$s%")->orWhere('subject', 'like', "%$s%")
                ->orWhere('requester_name', 'like', "%$s%")->orWhere('requester_email', 'like', "%$s%"));
        }
        $sort = in_array($request->query('sort'), ['number', 'subject', 'status', 'priority', 'created_at', 'last_message_at'], true) ? $request->query('sort') : 'last_message_at';
        $p = $q->orderBy($sort, $request->query('dir') === 'asc' ? 'asc' : 'desc')->paginate(min(100, max(5, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($p->items())->map(fn ($t) => $t->toArray() + [
                'assignee_name' => $t->assignee ? trim($t->assignee->firstname.' '.$t->assignee->lastname) : null,
            ]),
            'meta' => ['total' => $p->total()],
            'agents' => Helpdesk::agents($companyId),
            'categories' => HelpdeskSetting::forCompany($companyId)->categories ?? [],
        ]);
    }

    public function show(Request $request, $id)
    {
        $companyId = $this->companyId($request);
        $t = $this->ticket($companyId, $id);

        return response()->json(Helpdesk::present($t, true) + [
            'agents' => Helpdesk::agents($companyId),
            'categories' => HelpdeskSetting::forCompany($companyId)->categories ?? [],
            'public_url' => $t->publicUrl(),
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId($request);
        $d = $request->validate([
            'subject' => 'required|string|max:250',
            'body' => 'required|string|max:60000',
            'requester_email' => 'nullable|email|max:190',
            'requester_name' => 'nullable|string|max:190',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'category' => 'nullable|string|max:80',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:'.Helpdesk::ATTACH_MAX_KB.'|mimes:'.Helpdesk::ATTACH_MIMES,
        ]);
        $t = Helpdesk::open($companyId, $d['subject'], $d['body'],
            ['name' => $d['requester_name'] ?? null, 'email' => $d['requester_email'] ?? null], 'admin', [
                'priority' => $d['priority'] ?? null, 'category' => $d['category'] ?? null,
                'assigned_to' => $request->user('api')->id,
                'attachments' => Helpdesk::storeUploads($companyId, $request->file('files', [])),
            ]);

        return response()->json(['id' => $t->id, 'number' => $t->number], 201);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId($request);
        $t = $this->ticket($companyId, $id);
        $d = $request->validate([
            'status' => 'nullable|in:'.implode(',', HelpdeskTicket::STATUSES),
            'priority' => 'nullable|in:'.implode(',', HelpdeskTicket::PRIORITIES),
            'category' => 'nullable|string|max:80',
            'assigned_to' => 'nullable|integer',
        ]);
        if ($request->has('assigned_to')) {
            $aid = $d['assigned_to'] ?? null;
            abort_if($aid && ! collect(Helpdesk::agents($companyId))->contains('id', (int) $aid), 422, 'That user is not a helpdesk agent of this company.');
            $t->assigned_to = $aid ?: null;
        }
        foreach (['priority', 'category'] as $f) {
            if ($request->has($f)) {
                $t->{$f} = $d[$f] ?? null;
            }
        }
        $t->save();
        if (! empty($d['status']) && $d['status'] !== $t->status) {
            Helpdesk::setStatus($t, $d['status']);
        }

        return $this->show($request, $t->id);
    }

    public function reply(Request $request, $id)
    {
        $companyId = $this->companyId($request);
        $t = $this->ticket($companyId, $id);
        $request->merge(['is_internal' => $request->boolean('is_internal')]);
        $d = $request->validate([
            'body' => 'required|string|max:60000',
            'is_internal' => 'boolean',
            'status' => 'nullable|in:'.implode(',', HelpdeskTicket::STATUSES),
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:'.Helpdesk::ATTACH_MAX_KB.'|mimes:'.Helpdesk::ATTACH_MIMES,
        ]);
        $u = $request->user('api');
        Helpdesk::addMessage($t, 'agent', $d['body'], [
            'user_id' => $u->id, 'name' => trim($u->firstname.' '.$u->lastname) ?: $u->username, 'email' => $u->email,
            'is_internal' => $d['is_internal'], 'via' => 'app',
            'attachments' => Helpdesk::storeUploads($companyId, $request->file('files', [])),
        ]);
        if (! $t->assigned_to) {
            $t->forceFill(['assigned_to' => $u->id])->save();
        }
        if (! empty($d['status'])) {
            Helpdesk::setStatus($t->fresh(), $d['status']);
        }

        return $this->show($request, $t->id);
    }

    public function attachment(Request $request, $id, $index)
    {
        $companyId = $this->companyId($request);
        $m = HelpdeskMessage::findOrFail($id);
        abort_unless((int) $m->company_id === $companyId, 404);

        return Helpdesk::download($m, (int) $index);
    }

    public function destroy(Request $request, $id)
    {
        $companyId = $this->companyId($request, 'helpdesk_manage');
        $t = $this->ticket($companyId, $id);
        foreach ($t->messages as $m) {
            foreach ($m->attachments ?? [] as $a) {
                if (str_starts_with($a['path'] ?? '', 'helpdesk/company_'.$companyId.'/')) {
                    \Illuminate\Support\Facades\Storage::disk('local')->delete($a['path']);
                }
            }
        }
        HelpdeskMessage::where('ticket_id', $t->id)->delete();
        $t->delete();

        return response()->json(['ok' => true]);
    }

    /* ---------------- settings ---------------- */

    public function settings(Request $request)
    {
        $companyId = $this->companyId($request, 'helpdesk_manage');
        $s = HelpdeskSetting::forCompany($companyId);

        return response()->json($s->toArray() + [
            'has_password' => (bool) $s->imap_password,
            'public_form_url' => url('/support').'?company='.$companyId,
            'agents' => Helpdesk::agents($companyId),
        ]);
    }

    public function updateSettings(Request $request, $test = false)
    {
        $companyId = $this->companyId($request, 'helpdesk_manage');
        foreach (['public_form_enabled', 'auto_reply', 'imap_enabled'] as $b) {
            if ($request->has($b)) {
                $request->merge([$b => $request->boolean($b)]);
            }
        }
        $d = $request->validate([
            'ticket_prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9-]*$/'],
            'public_form_enabled' => 'boolean',
            'auto_reply' => 'boolean',
            'categories' => 'nullable|array|max:30',
            'categories.*' => 'string|max:80',
            'imap_enabled' => 'boolean',
            'imap_host' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'imap_port' => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => 'nullable|in:ssl,tls,none',
            'imap_username' => 'nullable|string|max:190',
            'imap_password' => 'nullable|string|max:500',
            'imap_folder' => 'nullable|string|max:120',
            'smtp_host' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:ssl,tls,none',
            'from_email' => 'nullable|email|max:190',
            'from_name' => 'nullable|string|max:190',
        ]);
        $s = HelpdeskSetting::forCompany($companyId);
        // Blank password = keep the saved one.
        if (($d['imap_password'] ?? '') === '') {
            unset($d['imap_password']);
        }
        if (array_key_exists('categories', $d)) {
            $d['categories'] = array_values(array_unique(array_filter(array_map('trim', $d['categories'] ?? []))));
        }
        $s->fill($d);
        if (! $test) {
            $s->save();
        }

        return $s;
    }

    public function saveSettings(Request $request)
    {
        $this->updateSettings($request);

        return $this->settings($request);
    }

    public function testMailbox(Request $request)
    {
        $s = $this->updateSettings($request, true); // test what's on screen, unsaved
        try {
            $r = HelpdeskMailFetcher::test($s);

            return response()->json(['ok' => true, 'message' => 'Connected. '.($r['unseen'] ?? 0).' unread email(s) waiting.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function fetchNow(Request $request)
    {
        $companyId = $this->companyId($request, 'helpdesk_manage');
        $s = HelpdeskSetting::forCompany($companyId);
        try {
            return response()->json(['ok' => true] + HelpdeskMailFetcher::fetch($s));
        } catch (\Throwable $e) {
            $s->forceFill(['last_polled_at' => now(), 'last_error' => 'Reading: '.mb_substr($e->getMessage(), 0, 500)])->save();

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function mailboxState(int $companyId): array
    {
        $s = HelpdeskSetting::forCompany($companyId);

        return [
            'enabled' => (bool) $s->imap_enabled && (bool) $s->imap_host,
            'address' => $s->address(),
            'last_polled_at' => $s->last_polled_at?->toDateTimeString(),
            'last_error' => $s->last_error,
        ];
    }
}
