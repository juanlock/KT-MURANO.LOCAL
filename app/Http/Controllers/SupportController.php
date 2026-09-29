<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RequesterTickets;
use App\Models\HelpdeskMessage;
use App\Models\HelpdeskSetting;
use App\Models\HelpdeskTicket;
use App\Services\FrontPage;
use App\Services\Helpdesk\Helpdesk;
use App\Support\CompanyDomain;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helpdesk public channel (suppliers and anyone without a login).
 *
 *  GET  /support                      "open a ticket" form (company = its domain or ?company=ID)
 *  POST /support                      opens the ticket -> redirect to its private link
 *  GET  /support/t/{token}            the ticket thread, for whoever holds the link
 *  POST /support/t/{token}/reply      requester reply
 *  GET  /support/t/{token}/attachments/{messageId}/{index}
 *
 * Anti-spam on the form: route throttle, a honeypot field and a minimum
 * time-to-submit (encrypted timestamp rendered with the form).
 */
class SupportController extends Controller
{
    use RequesterTickets;

    private const MIN_SECONDS = 3;
    private const MAX_FORM_AGE = 86400;

    /** The company whose public form this request is for; 404 when none or disabled. */
    private function formCompany(Request $request): int
    {
        $cid = FrontPage::explicitCompanyId($request);
        abort_unless($cid, 404);
        abort_unless(\App\Support\ModuleFlags::enabled($cid, 'helpdesk'), 404);
        $s = HelpdeskSetting::where('company_id', $cid)->first();
        abort_if($s && ! $s->public_form_enabled, 404);

        return $cid;
    }

    /** Company name + logo URL for the page header. */
    private function brand(int $companyId): array
    {
        $co = DB::table('companies')->where('id', $companyId)->first(['name', 'logo']);
        $logo = DB::table('settings')->where('company_id', $companyId)->value('logo') ?: ($co->logo ?? null);
        $url = null;
        if ($logo && ! str_contains($logo, 'logo-default')) {
            $url = Str::startsWith($logo, ['http://', 'https://']) ? $logo
                : (Str::startsWith($logo, ['files/', 'images/', '/']) ? asset(ltrim($logo, '/')) : asset('images/'.$logo));
        }

        return ['name' => $co->name ?? 'Support', 'logo' => $url];
    }

    private function ticketByToken(string $token): HelpdeskTicket
    {
        $t = HelpdeskTicket::where('public_token', $token)->firstOrFail();
        abort_unless(\App\Support\ModuleFlags::enabled((int) $t->company_id, 'helpdesk'), 404);

        return $t;
    }

    public function create(Request $request)
    {
        $cid = $this->formCompany($request);

        return response()->view('support.form', [
            'brand' => $this->brand($cid),
            'ticketCategories' => $this->ticketCategories($cid),
            // On the company's own domain the host says which company; else keep ?company=ID.
            'companyQuery' => CompanyDomain::companyIdForHost($request->getHost()) ? [] : ['company' => $cid],
            'formTs' => encrypt(now()->timestamp),
        ])->header('X-Robots-Tag', 'noindex');
    }

    public function store(Request $request)
    {
        $cid = $this->formCompany($request);
        $d = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            '_ts' => 'required|string|max:1000',
        ] + $this->openRules($cid));

        // Honeypot: real people never see (or fill) the "website" field.
        $elapsed = null;
        try {
            $elapsed = now()->timestamp - (int) decrypt($d['_ts']);
        } catch (DecryptException $e) {
            $elapsed = null;
        }
        if ($request->filled('website') || $elapsed === null || $elapsed < self::MIN_SECONDS || $elapsed > self::MAX_FORM_AGE) {
            return back()->withInput($request->except(['_ts', 'files', 'website']))
                ->withErrors(['body' => __('messages.TicketSpamCheck')]);
        }

        // Anyone can type any email here, so a public-form ticket is NEVER
        // attached to an account (it would show up in that person's portal,
        // store account or "My tickets"). Only a supplier match is kept --
        // suppliers have no login, so nothing is exposed; everyone else is a
        // guest who follows the ticket through its private link.
        $email = strtolower(trim($d['email']));
        [$type, , , $providerId, $knownName] = Helpdesk::identify($cid, $email);
        $requester = $type === 'supplier'
            ? ['type' => 'supplier', 'provider_id' => $providerId, 'name' => trim($d['name']) ?: $knownName, 'email' => $email]
            : ['type' => 'guest', 'name' => trim($d['name']), 'email' => $email];

        $t = Helpdesk::open($cid, $d['subject'], $d['body'], $requester, 'public_form', [
            'category' => $d['category'] ?? null,
            'attachments' => $this->uploads($request, $cid),
        ]);

        return redirect()->route('support.ticket', $t->public_token)
            ->with('support_notice', __('messages.TicketCreatedLink', ['number' => $t->number]));
    }

    public function show(string $token)
    {
        $t = $this->ticketByToken($token);

        return response()->view('support.ticket', [
            'brand' => $this->brand((int) $t->company_id),
            'data' => Helpdesk::present($t, false),
            'token' => $token,
            'closed' => $t->status === 'closed',
        ])->header('X-Robots-Tag', 'noindex');
    }

    public function reply(Request $request, string $token)
    {
        $t = $this->ticketByToken($token);
        $d = $request->validate($this->replyRules());
        if ($t->status === 'closed') {
            return back()->withErrors(['body' => __('messages.TicketClosedNotice')]);
        }
        Helpdesk::addMessage($t, 'requester', $d['body'], [
            'name' => $t->requester_name, 'email' => $t->requester_email, 'via' => 'public_form',
            'attachments' => $this->uploads($request, (int) $t->company_id),
        ]);

        return redirect()->route('support.ticket', $token)->with('support_notice', __('messages.TicketReplySent'));
    }

    public function attachment(string $token, $messageId, $index)
    {
        $t = $this->ticketByToken($token);
        $m = HelpdeskMessage::where('id', $messageId)->where('ticket_id', $t->id)->where('is_internal', false)->firstOrFail();

        return Helpdesk::download($m, (int) $index);
    }
}
