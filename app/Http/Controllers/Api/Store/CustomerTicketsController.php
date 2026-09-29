<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Concerns\RequesterTickets;
use App\Http\Controllers\Controller;
use App\Models\HelpdeskMessage;
use App\Models\HelpdeskTicket;
use App\Models\StoreSetting;
use App\Services\Helpdesk\Helpdesk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Online store "My Account -> Support tickets" (store guard, EcommerceClient).
 * Requester = online_customer, the account's client; company = that
 * client's company. Only the client's own tickets in its own company.
 *
 *  GET  account/tickets                 GET  account/tickets/{id}
 *  GET  account/tickets/new             POST account/tickets/{id}/reply
 *  POST account/tickets                 GET  account/tickets/messages/{id}/attachments/{i}
 */
class CustomerTicketsController extends Controller
{
    use RequesterTickets;

    /** [ecommerce client, client row] -- 403 without a linked client. */
    private function me(): array
    {
        $u = Auth::guard('store')->user();
        abort_unless($u && $u->client_id, 403);
        $client = DB::table('clients')->where('id', $u->client_id)->whereNull('deleted_at')->first(['id', 'company_id', 'name']);
        abort_unless($client && $client->company_id, 403);

        return [$u, $client];
    }

    private function mine($client, $id): HelpdeskTicket
    {
        $t = HelpdeskTicket::findOrFail($id);
        abort_unless((int) $t->company_id === (int) $client->company_id && (int) $t->client_id === (int) $client->id, 404);

        return $t;
    }

    private function settings(): StoreSetting
    {
        return StoreSetting::first() ?: new StoreSetting;
    }

    public function index()
    {
        [, $client] = $this->me();
        $tickets = HelpdeskTicket::where('company_id', $client->company_id)->where('client_id', $client->id)
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(200)
            ->get(['id', 'number', 'subject', 'status', 'category', 'created_at', 'last_message_at']);

        return view('store.account-tickets', ['s' => $this->settings(), 'tickets' => $tickets]);
    }

    public function create()
    {
        [, $client] = $this->me();

        return view('store.account-ticket-new', ['s' => $this->settings(), 'ticketCategories' => $this->ticketCategories((int) $client->company_id)]);
    }

    public function store(Request $request)
    {
        [$u, $client] = $this->me();
        $cid = (int) $client->company_id;
        $d = $request->validate($this->openRules($cid));

        $t = Helpdesk::open($cid, $d['subject'], $d['body'], [
            'type' => 'online_customer', 'client_id' => $client->id,
            'name' => $client->name ?: $u->username, 'email' => $u->email,
        ], 'store', [
            'category' => $d['category'] ?? null,
            'attachments' => $this->uploads($request, $cid),
        ]);

        return redirect()->route('account.tickets.show', $t->id)
            ->with('status', __('messages.TicketCreated', ['number' => $t->number]));
    }

    public function show($id)
    {
        [, $client] = $this->me();
        $t = $this->mine($client, $id);

        return view('store.account-ticket-show', [
            's' => $this->settings(), 'data' => Helpdesk::present($t, false), 'closed' => $t->status === 'closed',
        ]);
    }

    public function reply(Request $request, $id)
    {
        [$u, $client] = $this->me();
        $t = $this->mine($client, $id);
        $d = $request->validate($this->replyRules());
        if ($t->status === 'closed') {
            return back()->withErrors(['body' => __('messages.TicketClosedNotice')]);
        }
        Helpdesk::addMessage($t, 'requester', $d['body'], [
            'name' => $client->name ?: $u->username, 'email' => $u->email, 'via' => 'store',
            'attachments' => $this->uploads($request, (int) $client->company_id),
        ]);

        return redirect()->route('account.tickets.show', $t->id)->with('status', __('messages.TicketReplySent'));
    }

    public function attachment($messageId, $index)
    {
        [, $client] = $this->me();
        $m = HelpdeskMessage::findOrFail($messageId);
        $this->mine($client, $m->ticket_id);
        abort_if($m->is_internal, 404);

        return Helpdesk::download($m, (int) $index);
    }
}
