<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Models\HelpdeskMessage;
use App\Models\HelpdeskSetting;
use App\Models\HelpdeskTicket;
use App\Services\Helpdesk\Helpdesk;
use Illuminate\Http\Request;

/**
 * "My tickets" for any staff user (employees included): open a ticket to the
 * helpdesk of THEIR OWN company, follow it and reply. Only their own tickets.
 *
 *  GET  helpdesk/my                 GET helpdesk/my/{id}
 *  POST helpdesk/my                 POST helpdesk/my/{id}/reply
 *  GET  helpdesk/my/messages/{id}/attachments/{i}
 */
class MyTicketsController extends Controller
{
    private function me(Request $request)
    {
        $u = $request->user('api');
        abort_if(! $u || $u->type === 'customer' || ! $u->company_id, 403);
        \App\Support\ModuleFlags::abortUnlessEnabled((int) $u->company_id, 'helpdesk');

        return $u;
    }

    private function mine($u, $id): HelpdeskTicket
    {
        $t = HelpdeskTicket::findOrFail($id);
        abort_unless((int) $t->company_id === (int) $u->company_id && (int) $t->requester_user_id === (int) $u->id, 404);

        return $t;
    }

    public function index(Request $request)
    {
        $u = $this->me($request);

        return response()->json([
            'data' => HelpdeskTicket::where('company_id', $u->company_id)->where('requester_user_id', $u->id)
                ->orderByDesc('last_message_at')->limit(200)
                ->get(['id', 'number', 'subject', 'status', 'priority', 'category', 'created_at', 'last_message_at']),
            'categories' => HelpdeskSetting::forCompany((int) $u->company_id)->categories ?? [],
        ]);
    }

    public function show(Request $request, $id)
    {
        $u = $this->me($request);

        return response()->json(Helpdesk::present($this->mine($u, $id), false));
    }

    public function store(Request $request)
    {
        $u = $this->me($request);
        $d = $request->validate([
            'subject' => 'required|string|max:250',
            'body' => 'required|string|max:60000',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'category' => 'nullable|string|max:80',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:'.Helpdesk::ATTACH_MAX_KB.'|mimes:'.Helpdesk::ATTACH_MIMES,
        ]);
        $t = Helpdesk::open((int) $u->company_id, $d['subject'], $d['body'], [
            'type' => 'employee', 'user_id' => $u->id,
            'name' => trim($u->firstname.' '.$u->lastname) ?: $u->username, 'email' => $u->email,
        ], 'app', [
            'priority' => $d['priority'] ?? null, 'category' => $d['category'] ?? null,
            'attachments' => Helpdesk::storeUploads((int) $u->company_id, $request->file('files', [])),
        ]);

        return response()->json(['id' => $t->id, 'number' => $t->number], 201);
    }

    public function reply(Request $request, $id)
    {
        $u = $this->me($request);
        $t = $this->mine($u, $id);
        $d = $request->validate([
            'body' => 'required|string|max:60000',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:'.Helpdesk::ATTACH_MAX_KB.'|mimes:'.Helpdesk::ATTACH_MIMES,
        ]);
        abort_if($t->status === 'closed', 422, 'This ticket is closed. Open a new one.');
        Helpdesk::addMessage($t, 'requester', $d['body'], [
            'user_id' => $u->id, 'name' => trim($u->firstname.' '.$u->lastname) ?: $u->username, 'email' => $u->email, 'via' => 'app',
            'attachments' => Helpdesk::storeUploads((int) $u->company_id, $request->file('files', [])),
        ]);

        return response()->json(Helpdesk::present($t->fresh(), false));
    }

    public function attachment(Request $request, $id, $index)
    {
        $u = $this->me($request);
        $m = HelpdeskMessage::findOrFail($id);
        $this->mine($u, $m->ticket_id);
        abort_if($m->is_internal, 404);

        return Helpdesk::download($m, (int) $index);
    }
}
