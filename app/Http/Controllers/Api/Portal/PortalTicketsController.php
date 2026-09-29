<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Concerns\RequesterTickets;
use App\Http\Controllers\Controller;
use App\Models\HelpdeskMessage;
use App\Models\HelpdeskTicket;
use App\Services\Helpdesk\Helpdesk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Client portal "Support tickets" (portal guard). Requester = customer (the
 * portal user's client); company = that client's company. Only the client's
 * own tickets in its own company, never internal notes.
 *
 *  GET  /api/portal/tickets                  GET  /api/portal/tickets/{id}
 *  POST /api/portal/tickets                  POST /api/portal/tickets/{id}/reply
 *  GET  /api/portal/tickets/messages/{id}/attachments/{i}
 */
class PortalTicketsController extends Controller
{
    use RequesterTickets;

    /** [portal user, client row]. */
    private function me(): array
    {
        $pc = Auth::guard('portal')->user();
        abort_unless($pc, 401);
        if ((int) $pc->status !== 1) {
            abort(403, __('portal.portal_disabled'));
        }
        $client = $pc->client_id
            ? DB::table('clients')->where('id', $pc->client_id)->whereNull('deleted_at')->first(['id', 'company_id', 'name'])
            : null;
        abort_unless($client && $client->company_id, 403, __('portal.portal_disabled'));

        return [$pc, $client];
    }

    private function mine($client, $id): HelpdeskTicket
    {
        $t = HelpdeskTicket::findOrFail($id);
        abort_unless((int) $t->company_id === (int) $client->company_id && (int) $t->client_id === (int) $client->id, 404);

        return $t;
    }

    public function index(Request $request)
    {
        [, $client] = $this->me();
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:'.implode(',', HelpdeskTicket::STATUSES),
            'sort' => 'nullable|string|max:30',
            'dir' => 'nullable|in:asc,desc',
        ]);
        $perPage = (int) $request->input('limit', 10);
        $page = (int) $request->input('page', 1);
        $search = trim((string) $request->input('search', ''));
        $sorts = ['number' => 'id', 'subject' => 'subject', 'status' => 'status', 'last_message_at' => 'last_message_at', 'created_at' => 'created_at'];
        $sort = $sorts[$request->input('sort')] ?? 'last_message_at';
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';

        $base = HelpdeskTicket::where('company_id', $client->company_id)->where('client_id', $client->id);
        $q = (clone $base)
            ->when($search !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%")))
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->input('status')));

        $total = (clone $q)->count();
        $rows = $q->orderBy($sort, $dir)->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)
            ->get(['id', 'number', 'subject', 'status', 'category', 'created_at', 'last_message_at']);

        return response()->json([
            'totalRows' => $total,
            'tickets' => $rows->map(fn ($t) => [
                'id' => $t->id, 'number' => $t->number, 'subject' => $t->subject, 'status' => $t->status, 'category' => $t->category,
                'created_at' => $t->created_at?->toDateTimeString(), 'last_message_at' => $t->last_message_at?->toDateTimeString(),
            ])->values(),
            'statuses' => HelpdeskTicket::STATUSES,
            'categories' => $this->ticketCategories((int) $client->company_id),
        ]);
    }

    public function show($id)
    {
        [, $client] = $this->me();
        $data = Helpdesk::present($this->mine($client, $id), false);

        return response()->json($this->forClient($data));
    }

    public function store(Request $request)
    {
        [$pc, $client] = $this->me();
        $cid = (int) $client->company_id;
        $d = $request->validate($this->openRules($cid));

        $t = Helpdesk::open($cid, $d['subject'], $d['body'], [
            'type' => 'customer', 'user_id' => $pc->id, 'client_id' => $client->id,
            'name' => $client->name ?: $pc->email, 'email' => $pc->email,
        ], 'portal', [
            'category' => $d['category'] ?? null,
            'attachments' => $this->uploads($request, $cid),
        ]);

        return response()->json(['id' => $t->id, 'number' => $t->number], 201);
    }

    public function reply(Request $request, $id)
    {
        [$pc, $client] = $this->me();
        $t = $this->mine($client, $id);
        $d = $request->validate($this->replyRules());
        abort_if($t->status === 'closed', 422, __('portal.ticket_closed_notice'));
        Helpdesk::addMessage($t, 'requester', $d['body'], [
            'user_id' => $pc->id, 'name' => $client->name ?: $pc->email, 'email' => $pc->email, 'via' => 'portal',
            'attachments' => $this->uploads($request, (int) $client->company_id),
        ]);

        return response()->json($this->forClient(Helpdesk::present($t->fresh(), false)));
    }

    public function attachment($id, $index)
    {
        [, $client] = $this->me();
        $m = HelpdeskMessage::findOrFail($id);
        $this->mine($client, $m->ticket_id);
        abort_if($m->is_internal, 404);

        return Helpdesk::download($m, (int) $index);
    }

    /** Only what the client needs to see of the ticket (no staff-side fields). */
    private function forClient(array $data): array
    {
        $t = $data['ticket'];
        $data['ticket'] = [
            'id' => $t['id'], 'number' => $t['number'], 'subject' => $t['subject'], 'status' => $t['status'],
            'category' => $t['category'] ?? null, 'created_at' => $t['created_at'] ?? null, 'last_message_at' => $t['last_message_at'] ?? null,
        ];

        return $data;
    }
}
