<?php

namespace App\Http\Controllers\Api\EmailOffice;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Models\EmailOfficeSetting;
use App\Models\OfficeEmail;
use App\Services\EmailOffice\EmailOffice;
use App\Services\EmailOffice\EmailOfficeFetcher;
use App\Services\EmailOffice\EmailOfficeMailer;
use App\Services\Helpdesk\Helpdesk;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\ModuleFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Email Office, per company (multicompany: an Admin works on their own
 * company; a SuperAdmin on the company picked in the header, or every
 * company's emails when acting as AdminSystem 999 -- settings need a company).
 *
 *  GET|PUT email-office/settings             (email_office_manage)
 *  POST    email-office/settings/test        (manage) log in, list folders
 *  POST    email-office/settings/fetch       (manage) import now
 *  GET     email-office/emails               ?direction=in|out&category&party_type&linked(1|0)&from&to&search&page&limit
 *  GET     email-office/emails/{id}          full email (marks it read)
 *  PUT     email-office/emails/{id}          category, link (purchase|expense|helpdesk_ticket + id), unlink
 *  GET     email-office/emails/{id}/attachments/{i}
 *  POST    email-office/emails/{id}/ticket   open a helpdesk ticket from a claim
 *  POST    email-office/compose              to, cc, subject, body
 *  GET     email-office/party                ?type=client&id= (Customer -> Email conversation)
 *  GET     email-office/link-options         ?type=purchase|expense&search
 */
class EmailOfficeController extends Controller
{
    use ScopesToCompany;

    /** Acting company (null = SuperAdmin on AdminSystem: all companies). */
    private function companyId(Request $request, string $perm = 'email_office_view', bool $needCompany = false): ?int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), $perm), 403, 'You do not have permission for Email Office.');
        $cid = (int) $this->resolveActingCompanyId($request);
        $all = $request->user('api')->type === 'SuperAdmin' && $cid === 999;
        abort_if($all && $needCompany, 422, 'Pick a company in the header first.');
        if (! $all) {
            ModuleFlags::abortUnlessEnabled($cid, 'email_office');
        }

        return $all ? null : $cid;
    }

    private function email(Request $request, $id): OfficeEmail
    {
        $cid = $this->companyId($request);
        $e = OfficeEmail::findOrFail($id);
        abort_if($cid !== null && (int) $e->company_id !== $cid, 404);

        return $e;
    }

    /* ------------------------------------------------ settings */

    public function settings(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        $s = EmailOfficeSetting::forCompany($cid);

        return response()->json([
            'settings' => $s->toArray() + ['has_password' => (bool) $s->password],
            'pop3_available' => function_exists('imap_open'),
            'counts' => [
                'received' => OfficeEmail::where('company_id', $cid)->where('direction', 'in')->count(),
                'sent' => OfficeEmail::where('company_id', $cid)->where('direction', 'out')->count(),
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        $v = $request->validate([
            'enabled' => 'boolean',
            'protocol' => 'required|in:imap,pop3',
            'host' => 'nullable|string|max:191',
            'port' => 'nullable|integer|min:1|max:65535',
            'encryption' => 'required|in:ssl,tls,none',
            'username' => 'nullable|string|max:191',
            'password' => 'nullable|string|max:500',
            'folder' => 'nullable|string|max:120',
            'import_sent' => 'boolean',
            'check_every_minutes' => 'required|integer|in:5,15,30,60,180,720,1440',
            'days_back' => 'required|integer|min:1|max:365',
            'smtp_host' => 'nullable|string|max:191',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:ssl,tls,none',
            'from_name' => 'nullable|string|max:191',
            'from_email' => 'nullable|email|max:191',
        ]);
        $s = EmailOfficeSetting::forCompany($cid);
        // Empty password = keep the saved one.
        if (($v['password'] ?? '') === '') {
            unset($v['password']);
        }
        $v['folder'] = ($v['folder'] ?? '') ?: 'INBOX';
        $s->fill($v)->save();

        return response()->json(['success' => true]);
    }

    public function test(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        try {
            $s = EmailOfficeSetting::forCompany($cid);
            $r = EmailOfficeFetcher::test($s);
            // Connected: the old error no longer applies.
            $s->forceFill(['last_error' => null])->save();

            return response()->json(['ok' => true] + $r);
        } catch (\Throwable $e) {
            $s = EmailOfficeSetting::forCompany($cid);

            return response()->json(['ok' => false, 'message' => EmailOfficeFetcher::explain($e, $s), 'suggest' => EmailOfficeFetcher::suggestion($s)], 422);
        }
    }

    public function fetchNow(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        try {
            $stats = EmailOfficeFetcher::fetch(EmailOfficeSetting::forCompany($cid));
            $stats['matched'] = EmailOffice::rematch($cid);

            return response()->json(['ok' => true, 'stats' => $stats]);
        } catch (\Throwable $e) {
            $s = EmailOfficeSetting::forCompany($cid);

            return response()->json(['ok' => false, 'message' => EmailOfficeFetcher::explain($e, $s), 'suggest' => EmailOfficeFetcher::suggestion($s)], 422);
        }
    }

    /**
     * POST email-office/auto-check -- called by the Received page every minute
     * while it is open: checks the mailbox only when the company's "check
     * every N minutes" has passed (and automatic checking is on), so new
     * emails arrive even where the server's scheduler isn't running. A lock
     * keeps two open pages from checking at once.
     * Returns {checked, received, sent, last_polled_at, next_at, enabled, error}.
     */
    public function autoCheck(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_view', true);
        $s = EmailOfficeSetting::forCompany($cid);
        $out = ['checked' => false, 'received' => 0, 'sent' => 0];
        if ($s->isDue()) {
            $lock = \Illuminate\Support\Facades\Cache::lock('email-office-check-'.$cid, 120);
            if ($lock->get()) {
                try {
                    $stats = EmailOfficeFetcher::fetch($s);
                    EmailOffice::rematch($cid);
                    $out = ['checked' => true, 'received' => (int) ($stats['received'] ?? 0), 'sent' => (int) ($stats['sent'] ?? 0)];
                } catch (\Throwable $e) {
                    $out['checked'] = true; // the error is kept in last_error
                } finally {
                    $lock->release();
                }
                $s->refresh();
            }
        }
        $every = max(1, (int) $s->check_every_minutes);

        return response()->json($out + [
            'enabled' => (bool) $s->enabled,
            'every' => $every,
            'last_polled_at' => $s->last_polled_at?->toDateTimeString(),
            'next_at' => $s->enabled ? ($s->last_polled_at ? $s->last_polled_at->copy()->addMinutes($every) : now())->toDateTimeString() : null,
            'error' => $s->last_error,
        ]);
    }

    /**
     * "Import all emails" (first time): action = start | step | pause |
     * resume | cancel. The Settings page calls step repeatedly while it's
     * open; the scheduler (email-office:fetch) keeps it going otherwise.
     */
    public function importAll(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        $s = EmailOfficeSetting::forCompany($cid);
        $action = (string) $request->input('action', 'step');
        $state = $s->full_import ?: [];
        try {
            switch ($action) {
                case 'start':
                    abort_if(($state['status'] ?? null) === 'running', 422, 'An import is already running.');
                    $state = EmailOfficeFetcher::fullStart($s);
                    break;
                case 'step':
                    $state = EmailOfficeFetcher::fullStep($s, 3, 35);
                    break;
                case 'pause':
                case 'cancel':
                    if (in_array($state['status'] ?? null, ['running', 'paused'], true)) {
                        $state['status'] = $action === 'pause' ? 'paused' : 'cancelled';
                        $s->forceFill(['full_import' => $state])->save();
                    }
                    break;
                case 'resume':
                    abort_unless(($state['status'] ?? null) === 'paused', 422, 'Nothing to resume.');
                    $state['status'] = 'running';
                    $s->forceFill(['full_import' => $state])->save();
                    break;
                default:
                    abort(422, 'Unknown action.');
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => EmailOfficeFetcher::explain($e, $s), 'suggest' => EmailOfficeFetcher::suggestion($s)], 422);
        }
        if (($state['status'] ?? null) === 'done') {
            EmailOffice::rematch($cid);
        }

        return response()->json(['ok' => true, 'full_import' => $state]);
    }

    /* ------------------------------------------------ lists */

    private function listQuery(Request $request, ?int $cid)
    {
        $q = OfficeEmail::query()->when($cid !== null, fn ($x) => $x->where('company_id', $cid));
        if (in_array($request->direction, ['in', 'out'], true)) {
            $q->where('direction', $request->direction);
        }
        if (in_array($request->category, OfficeEmail::categoriesFor($cid), true)) {
            $q->where('category', $request->category);
        } elseif (! $request->boolean('with_hidden')) {
            // Social / Archive / Spam and the company's own folders: only listed when picked.
            $q->whereNotIn('category', OfficeEmail::hiddenFor($cid));
        }
        if ($request->filled('party_type')) {
            $request->party_type === 'none' ? $q->whereNull('party_type') : $q->where('party_type', $request->party_type);
        }
        if ($request->filled('linked')) {
            $request->linked ? $q->whereNotNull('linked_type') : $q->whereNull('linked_type');
        }
        // Received -> "Hide recorded": emails already entered in Murano (linked
        // to an expense / purchase / sale / ticket) drop out of the list, and
        // so do customers' emails (they live in the customer's Email
        // conversation; a customer's email is never a bill to record).
        if ($request->boolean('hide_recorded') && ! $request->filled('linked')) {
            $q->whereNull('linked_type')->where(fn ($w) => $w->whereNull('party_type')->orWhere('party_type', '!=', 'client'));
        }
        if ($request->boolean('unread')) {
            $q->where('is_read', false);
        }
        if ($request->filled('source')) {
            $q->where('source', $request->source);
        }
        // A sender by NAME (JSON {"sender": "...", "subject"?: "..."} from the
        // Received "From" filter): every email under that name, whatever
        // address it came from -- optionally only one subject (e.g. the bill).
        $addrInput = trim((string) $request->input('address'));
        $byName = str_starts_with($addrInput, '{') ? json_decode($addrInput, true) : null;
        if (is_array($byName) && trim((string) ($byName['sender'] ?? '')) !== '') {
            $q->whereRaw(self::SENDER_SQL.' = ?', [trim($byName['sender'])]);
            if (trim((string) ($byName['subject'] ?? '')) !== '') {
                $q->whereRaw('TRIM(subject) = ?', [trim($byName['subject'])]);
            }
        }
        // One address: the emails FROM it or TO it (a conversation with that sender / recipient).
        elseif ($addr = strtolower($addrInput)) {
            $q->where(fn ($w) => $w->where('from_email', $addr)->orWhere('to_emails', 'like', '%'.$addr.'%')->orWhere('cc_emails', 'like', '%'.$addr.'%'));
        }
        // Subject filter: that subject and its replies / forwards (subject_norm).
        if (($subj = trim((string) $request->input('subject'))) !== '' && Schema::hasColumn('office_emails', 'subject_norm')) {
            $q->where('subject_norm', EmailOffice::normSubject($subj));
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input($k))) {
                $q->whereDate('sent_at', $op, $request->input($k));
            }
        }
        if ($s = trim((string) $request->search)) {
            $q->where(fn ($w) => $w->where('subject', 'like', "%$s%")->orWhere('from_email', 'like', "%$s%")
                ->orWhere('from_name', 'like', "%$s%")->orWhere('to_emails', 'like', "%$s%")->orWhere('snippet', 'like', "%$s%"));
        }

        return $q;
    }

    public function index(Request $request)
    {
        $cid = $this->companyId($request);
        $q = $this->listQuery($request, $cid);
        $total = (clone $q)->count();
        $limit = (int) $request->limit > 0 ? min(200, (int) $request->limit) : 20;
        $page = max(1, (int) $request->page);
        $sort = in_array($request->SortField, ['sent_at', 'subject', 'from_email', 'category', 'id'], true) ? $request->SortField : 'sent_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';
        $rows = (clone $q)->orderBy($sort, $dir)->orderByDesc('id')->offset(($page - 1) * $limit)->limit($limit)
            ->get(['id', 'company_id', 'direction', 'source', 'subject', 'from_email', 'from_name', 'to_emails', 'sent_at', 'snippet',
                'attachments', 'category', 'category_manual', 'party_type', 'party_id', 'linked_type', 'linked_id', 'link_role', 'is_read', 'status']);
        $this->decorate($rows);

        // Cards: counts for the same filters except category (Archive / Spam
        // counted too, but not in the total -- they're folders).
        $base = $this->listQuery($request->merge(['category' => null, 'unread' => null, 'with_hidden' => null]), $cid);
        $stats = $this->listQuery($request->merge(['with_hidden' => 1]), $cid)->selectRaw('category, count(*) n')->groupBy('category')->pluck('n', 'category');

        // Defaults -> Email Office: the cards this company hides.
        $hiddenCards = $cid !== null && Schema::hasColumn('settings', 'email_office_hidden_cards')
            ? (json_decode((string) \App\Models\Setting::forCompany($cid)->email_office_hidden_cards, true) ?: [])
            : [];

        return response()->json([
            'emails' => $rows,
            'totalRows' => $total,
            'hidden_cards' => $hiddenCards,
            'stats' => [
                'total' => (int) $stats->except(OfficeEmail::hiddenFor($cid))->sum(),
                // Every type's count, the company's own folders too ("f12" => 4).
                'by_category' => $stats,
                'social' => (int) ($stats['social'] ?? 0),
                'archive' => (int) ($stats['archive'] ?? 0),
                'spam' => (int) ($stats['spam'] ?? 0),
                'bill' => (int) ($stats['bill'] ?? 0),
                'claim' => (int) ($stats['claim'] ?? 0),
                'reminder' => (int) ($stats['reminder'] ?? 0),
                'payment' => (int) ($stats['payment'] ?? 0),
                'supplier' => (int) ($stats['supplier'] ?? 0),
                'work_order' => (int) ($stats['work_order'] ?? 0),
                'request' => (int) ($stats['request'] ?? 0),
                'question' => (int) ($stats['question'] ?? 0),
                'general' => (int) ($stats['general'] ?? 0),
                'unread' => (clone $base)->where('is_read', false)->count(),
                'unlinked_bills' => (clone $base)->where('category', 'bill')->whereNull('linked_type')->count(),
            ],
        ]);
    }

    /** Party names, links and attachment counts for a page of emails. */
    private function decorate($rows): void
    {
        $names = [];
        foreach (['client' => 'clients', 'provider' => 'providers'] as $type => $table) {
            $ids = $rows->where('party_type', $type)->pluck('party_id')->unique()->filter()->all();
            $names[$type] = $ids ? DB::table($table)->whereIn('id', $ids)->pluck('name', 'id') : collect();
        }
        $empIds = $rows->where('party_type', 'employee')->pluck('party_id')->unique()->filter()->all();
        $names['employee'] = $empIds ? DB::table('employees')->whereIn('id', $empIds)->selectRaw("id, TRIM(CONCAT(COALESCE(firstname,''),' ',COALESCE(lastname,''))) n")->pluck('n', 'id') : collect();
        // Zelle deposits waiting for Confirm: amount + payer for the list's button.
        $draftIds = $rows->filter(fn ($r) => in_array($r->link_role, ['sale_draft', 'payment_draft'], true) && ! $r->linked_type)->pluck('id')->all();
        $bodies = $draftIds ? DB::table('office_emails')->whereIn('id', $draftIds)->pluck('body_text', 'id') : collect();
        foreach ($rows as $r) {
            $body = $bodies[$r->id] ?? null;
            $d = $body !== null && $r->link_role === 'sale_draft' ? \App\Services\EmailOffice\ZelleSale::parse($body) : null;
            $r->sale_draft = $d ? ['amount' => $d['amount'], 'payer' => $d['payer']] : null;
            // Zelle payments sent waiting for Confirm (-> expense).
            $pd = $body !== null && $r->link_role === 'payment_draft' ? \App\Services\EmailOffice\ZellePayment::parse($body) : null;
            $r->payment_draft = $pd ? ['amount' => $pd['amount'], 'payee' => $pd['payee']] : null;
            $r->party_name = $r->party_type ? ($names[$r->party_type][$r->party_id] ?? null) : null;
            $r->attachments_count = count($r->attachments ?? []);
            // Names for the list's Attach column (index = what attachment/{index} opens).
            $r->attachment_names = collect($r->attachments ?? [])->map(fn ($a, $i) => ['index' => $i, 'name' => $a['name'] ?? 'file', 'downloadable' => ! empty($a['path'])])->values()->all();
            $r->linked_label = $this->linkedLabel($r->linked_type, $r->linked_id);
            unset($r->attachments);
        }
    }

    private function linkedLabel(?string $type, $id): ?string
    {
        if (! $type || ! $id) {
            return null;
        }
        $ref = match ($type) {
            'purchase' => DB::table('purchases')->where('id', $id)->value('Ref'),
            'sale' => DB::table('sales')->where('id', $id)->value('Ref'),
            'expense' => DB::table('expenses')->where('id', $id)->value('Ref'),
            'helpdesk_ticket' => DB::table('helpdesk_tickets')->where('id', $id)->value('number'),
            'quotation' => DB::table('quotations')->where('id', $id)->value('Ref'),
            'service_job' => DB::table('service_jobs')->where('id', $id)->value('Ref'),
            default => null,
        };

        return $ref ?: '#'.$id;
    }

    public function show(Request $request, $id)
    {
        $e = $this->email($request, $id);
        if (! $e->is_read) {
            $e->update(['is_read' => true]);
        }
        $row = collect([$e]);
        $att = $e->attachments ?? [];
        $this->decorate($row);
        $data = $e->toArray();
        $data['attachments'] = collect($att)->map(fn ($a, $i) => ['index' => $i, 'name' => $a['name'] ?? 'file', 'size' => $a['size'] ?? 0, 'downloadable' => ! empty($a['path'])])->values();

        return response()->json(['email' => $data]);
    }

    /**
     * GET email-office/emails/{id}/similar -- the other received emails of the
     * same sender NAME and subject (e.g. every monthly AT&T bill) that aren't
     * recorded in Murano yet, oldest first. Used after creating an expense
     * from one of them: "I found N similar emails -- record them too?".
     */
    public function similar(Request $request, $id)
    {
        $e = $this->email($request, $id);
        $sender = trim((string) DB::table('office_emails')->where('id', $e->id)->selectRaw(self::SENDER_SQL.' as s')->value('s'));
        $rows = OfficeEmail::where('company_id', $e->company_id)->where('direction', 'in')->where('id', '!=', $e->id)
            ->whereNull('linked_type')
            ->whereRaw(self::SENDER_SQL.' = ?', [$sender])
            ->whereRaw('TRIM(subject) = ?', [trim((string) $e->subject)])
            ->orderBy('sent_at')->orderBy('id')->limit(100)
            ->get(['id', 'subject', 'sent_at', 'snippet']);

        return response()->json(['sender' => $sender, 'subject' => $e->subject, 'emails' => $rows]);
    }

    public function update(Request $request, $id)
    {
        $e = $this->email($request, $id);
        $v = $request->validate([
            'category' => 'sometimes|in:'.implode(',', OfficeEmail::categoriesFor((int) $e->company_id)),
            'linked_type' => 'sometimes|nullable|in:sale,purchase,expense,helpdesk_ticket,quotation,service_job',
            'party_type' => 'sometimes|nullable|in:client,provider,employee',
            'party_id' => 'sometimes|nullable|integer',
            'linked_id' => 'sometimes|nullable|integer',
            'is_read' => 'sometimes|boolean',
        ]);
        if (array_key_exists('category', $v)) {
            $v['category_manual'] = true;
        }
        if (array_key_exists('linked_type', $v)) {
            if ($v['linked_type']) {
                abort_unless(! empty($v['linked_id']) && $this->ownsDocument((int) $e->company_id, $v['linked_type'], (int) $v['linked_id']), 422, 'That document is not in this company.');
                // A reminder / payment confirmation linked to an expense = its history.
                $cat = $v['category'] ?? $e->category;
                $v['link_role'] = $v['linked_type'] === 'expense' ? (in_array($cat, ['reminder', 'payment'], true) ? $cat : 'bill') : null;
            } else {
                $v['linked_id'] = null;
                $v['link_role'] = null;
            }
        }
        if (array_key_exists('party_type', $v)) {
            if ($v['party_type']) {
                abort_unless(! empty($v['party_id']) && $this->ownsParty((int) $e->company_id, $v['party_type'], (int) $v['party_id']), 422, 'That contact is not in this company.');
            } else {
                $v['party_id'] = null;
            }
        }
        $e->update($v);

        return response()->json(['success' => true]);
    }

    private function ownsParty(int $companyId, string $type, int $id): bool
    {
        $table = ['client' => 'clients', 'provider' => 'providers', 'employee' => 'employees'][$type] ?? null;

        return $table && DB::table($table)->where('id', $id)->where('company_id', $companyId)->whereNull('deleted_at')->exists();
    }

    private function ownsDocument(int $companyId, string $type, int $id): bool
    {
        return match ($type) {
            'purchase' => DB::table('purchases')->where('id', $id)->where('company_id', $companyId)->exists(),
            'sale' => DB::table('sales')->where('id', $id)->where('company_id', $companyId)->exists(),
            'expense' => DB::table('expenses as e')->join('warehouses as w', 'w.id', '=', 'e.warehouse_id')->where('e.id', $id)->where('w.company_id', $companyId)->exists(),
            'helpdesk_ticket' => DB::table('helpdesk_tickets')->where('id', $id)->where('company_id', $companyId)->exists(),
            'quotation' => DB::table('quotations')->where('id', $id)->where('company_id', $companyId)->exists(),
            // service_jobs has no company_id: its location's company.
            'service_job' => DB::table('service_jobs as j')->join('warehouses as w', 'w.id', '=', 'j.warehouse_id')->where('j.id', $id)->where('w.company_id', $companyId)->exists(),
            default => false,
        };
    }

    public function attachment(Request $request, $id, $index)
    {
        $e = $this->email($request, $id);
        $a = ($e->attachments ?? [])[(int) $index] ?? null;
        abort_unless($a && \App\Support\CompanyFiles::owns($a['path'] ?? null, (int) $e->company_id, EmailOffice::FILES_MODULE), 404);

        return \App\Support\CompanyFiles::download($a['path'], $a['name'] ?? basename($a['path']));
    }

    /**
     * GET email-office/emails/{id}/conversation -- the email's history: the
     * same subject (without Re: / Fwd:) with the same contact (its customer /
     * supplier, else the same address either way), oldest first.
     */
    public function conversation(Request $request, $id)
    {
        $e = $this->email($request, $id);
        $other = strtolower((string) ($e->direction === 'in' ? $e->from_email : trim(explode(',', (string) $e->to_emails)[0])));
        $rows = OfficeEmail::where('company_id', $e->company_id)
            ->when(Schema::hasColumn('office_emails', 'subject_norm') && $e->subject_norm, fn ($q) => $q->where('subject_norm', $e->subject_norm), fn ($q) => $q->where('id', $e->id))
            ->where(function ($w) use ($e, $other) {
                $w->where('id', $e->id);
                if ($e->party_type && $e->party_id) {
                    $w->orWhere(fn ($x) => $x->where('party_type', $e->party_type)->where('party_id', $e->party_id));
                }
                if ($other !== '') {
                    $w->orWhere('from_email', $other)->orWhere('to_emails', 'like', '%'.$other.'%');
                }
            })
            ->orderBy('sent_at')->limit(60)
            ->get(['id', 'direction', 'sent_at', 'from_name', 'from_email', 'snippet', 'subject']);

        return response()->json(['emails' => $rows]);
    }

    /* ------------------------------------------------ the company's own folders */

    /** GET email-office/folders -- [{key: "f12", name, color}], by name. */
    public function folders(Request $request)
    {
        $cid = $this->companyId($request);
        $rows = Schema::hasTable('email_office_folders')
            ? DB::table('email_office_folders')->when($cid !== null, fn ($q) => $q->where('company_id', $cid))->orderBy('name')->get(['id', 'name', 'color'])
            : collect();

        return response()->json(['folders' => $rows->map(fn ($f) => ['key' => 'f'.$f->id, 'name' => $f->name, 'color' => $f->color])->values()]);
    }

    /** POST email-office/folders {name, color?} -- a new folder (from the rules' "Move to"). */
    public function createFolder(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        $v = $request->validate(['name' => 'required|string|max:60', 'color' => 'nullable|string|max:20']);
        $name = trim($v['name']);
        $existing = DB::table('email_office_folders')->where('company_id', $cid)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');
        $id = $existing ?: DB::table('email_office_folders')->insertGetId([
            'company_id' => $cid, 'name' => $name, 'color' => $v['color'] ?? 'purple', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'key' => 'f'.$id, 'name' => $name]);
    }

    /** DELETE email-office/folders/{id} -- its emails go back to General; rules moving there are cleared. */
    public function deleteFolder(Request $request, $id)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        abort_unless(DB::table('email_office_folders')->where('id', $id)->where('company_id', $cid)->exists(), 404);
        $key = 'f'.(int) $id;
        $moved = OfficeEmail::where('company_id', $cid)->where('category', $key)->update(['category' => 'general']);
        DB::table('email_office_rules')->where('company_id', $cid)->where('set_category', $key)->update(['set_category' => null]);
        DB::table('email_office_folders')->where('id', $id)->delete();

        return response()->json(['success' => true, 'moved' => $moved]);
    }

    /* ------------------------------------------------ rules (the company's own sorting) */

    /** GET email-office/rules -- the company's rules, in order. */
    public function rules(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        $rows = DB::table('email_office_rules')->where('company_id', $cid)->orderBy('sort_order')->orderBy('id')->get()
            // array_merge, not "+": the conditions must REPLACE the stored JSON text with the list.
            ->map(fn ($r) => array_merge((array) $r, [
                'conditions' => json_decode((string) $r->conditions, true) ?: [],
                'prepare_sale' => isset($r->prepare_sale) ? (json_decode((string) $r->prepare_sale, true) ?: null) : null,
            ]));

        // "Prepare a paid sale": the company's services and sales agents.
        $services = DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')->where('type', 'is_service')
            ->orderBy('name')->get(['id', 'name']);
        $agents = DB::table('sales_agents')->where('company_id', $cid)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name']);

        return response()->json(['rules' => $rows, 'sale_options' => ['services' => $services, 'agents' => $agents]]);
    }

    /** GET email-office/emails/{id}/sale-draft -- what Confirm will create (Zelle deposit). */
    public function saleDraft(Request $request, $id)
    {
        $e = $this->email($request, $id);
        $preview = \App\Services\EmailOffice\ZelleSale::preview($e);
        abort_unless($preview, 422, 'This email is not a Zelle deposit.');

        return response()->json(['draft' => $preview]);
    }

    /** POST email-office/emails/{id}/sale-draft -- create the paid sale (or link the one already there). */
    public function confirmSaleDraft(Request $request, $id)
    {
        $this->companyId($request, 'email_office_manage');
        $user = $request->user('api');
        abort_unless($user->can('create', \App\Models\Sale::class), 403, 'You do not have permission to create sales.');
        $e = $this->email($request, $id);

        return response()->json(['success' => true] + \App\Services\EmailOffice\ZelleSale::confirm($e, (int) $user->id));
    }

    /** GET email-office/emails/{id}/payment-draft -- Zelle payment sent: what Confirm will record + who the payee may be. */
    public function paymentDraft(Request $request, $id)
    {
        $e = $this->email($request, $id);
        $preview = \App\Services\EmailOffice\ZellePayment::preview($e);
        abort_unless($preview, 422, 'This email is not a Zelle payment sent.');
        $cid = (int) $e->company_id;

        return response()->json([
            'draft' => $preview,
            'employees' => DB::table('employees')->where('company_id', $cid)->whereNull('deleted_at')
                ->selectRaw("id, TRIM(CONCAT(COALESCE(firstname,''),' ',COALESCE(lastname,''))) name")->orderBy('firstname')->get(),
            'categories' => DB::table('expense_categories')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** POST email-office/emails/{id}/payment-draft {employee_id?, expense_category_id?} -- record the expense (or link the one there). */
    public function confirmPaymentDraft(Request $request, $id)
    {
        $this->companyId($request, 'email_office_manage');
        $user = $request->user('api');
        abort_unless($user->can('create', \App\Models\Expense::class), 403, 'You do not have permission to create expenses.');
        $e = $this->email($request, $id);
        $v = $request->validate(['employee_id' => 'nullable|integer', 'expense_category_id' => 'nullable|integer']);

        return response()->json(['success' => true] + \App\Services\EmailOffice\ZellePayment::confirm(
            $e, (int) $user->id, isset($v['employee_id']) ? (int) $v['employee_id'] : null, isset($v['expense_category_id']) ? (int) $v['expense_category_id'] : null
        ));
    }

    /** POST email-office/rules (new) | PUT email-office/rules/{id} (edit). */
    public function saveRule(Request $request, $id = null)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'conditions' => 'required|array|min:1|max:20',
            'conditions.*.field' => 'required|in:'.implode(',', \App\Services\EmailOffice\EmailRules::FIELDS),
            'conditions.*.op' => 'required|in:'.implode(',', \App\Services\EmailOffice\EmailRules::OPS),
            'conditions.*.value' => 'required|string|max:190',
            'match' => 'required|in:any,all',
            'set_category' => 'nullable|in:'.implode(',', OfficeEmail::categoriesFor($cid)),
            'mark_read' => 'boolean',
            'active' => 'boolean',
            'prepare_sale' => 'nullable|array',
            'prepare_sale.kind' => 'nullable|in:zelle_in,zelle_out',
            'prepare_sale.product_id' => 'nullable|integer',
            'prepare_sale.sales_agent_id' => 'nullable|integer',
        ]);
        $prep = null;
        if (! empty($v['prepare_sale']) && ($v['prepare_sale']['kind'] ?? 'zelle_in') === 'zelle_out') {
            // Zelle payment sent -> expense: who the payee is is mapped on Confirm.
            $prep = ['kind' => 'zelle_out'];
        } elseif (! empty($v['prepare_sale'])) {
            $prep = ['kind' => 'zelle_in', 'product_id' => (int) ($v['prepare_sale']['product_id'] ?? 0), 'sales_agent_id' => (int) ($v['prepare_sale']['sales_agent_id'] ?? 0) ?: null];
            abort_unless(DB::table('products')->where('id', $prep['product_id'])->where('company_id', $cid)->whereNull('deleted_at')->exists(), 422, 'Pick a service of this company.');
            abort_if($prep['sales_agent_id'] && ! DB::table('sales_agents')->where('id', $prep['sales_agent_id'])->where('company_id', $cid)->exists(), 422, 'Pick a sales agent of this company.');
        }
        abort_if(! ($v['set_category'] ?? null) && empty($v['mark_read']) && ! $prep, 422, 'Pick what the rule does (move to a type, mark as read and/or prepare a sale).');
        $row = [
            'name' => $v['name'], 'conditions' => json_encode(array_values($v['conditions'])), 'match' => $v['match'],
            'set_category' => $v['set_category'] ?? null, 'mark_read' => (bool) ($v['mark_read'] ?? false),
            'active' => (bool) ($v['active'] ?? true), 'updated_at' => now(),
        ];
        if (Schema::hasColumn('email_office_rules', 'prepare_sale')) {
            $row['prepare_sale'] = $prep ? json_encode($prep) : null;
        }
        if ($id) {
            $q = DB::table('email_office_rules')->where('id', $id)->where('company_id', $cid);
            abort_unless((clone $q)->exists(), 404);
            $q->update($row);
        } else {
            $id = DB::table('email_office_rules')->insertGetId($row + [
                'company_id' => $cid, 'created_at' => now(),
                'sort_order' => (int) DB::table('email_office_rules')->where('company_id', $cid)->max('sort_order') + 1,
            ]);
        }

        return response()->json(['success' => true, 'id' => (int) $id]);
    }

    /** DELETE email-office/rules/{id} */
    public function deleteRule(Request $request, $id)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        DB::table('email_office_rules')->where('id', $id)->where('company_id', $cid)->delete();

        return response()->json(['success' => true]);
    }

    /** POST email-office/rules/run-all -- every active rule again, in order, on the emails already there. */
    public function runAllRules(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        $n = 0;
        foreach (DB::table('email_office_rules')->where('company_id', $cid)->where('active', true)->orderBy('sort_order')->orderBy('id')->get() as $rule) {
            $n += \App\Services\EmailOffice\EmailRules::runOnExisting($rule);
        }

        return response()->json(['success' => true, 'changed' => $n]);
    }

    /** POST email-office/rules/{id}/run -- the rule on the emails already there. */
    public function runRule(Request $request, $id)
    {
        $cid = $this->companyId($request, 'email_office_manage', true);
        $rule = DB::table('email_office_rules')->where('id', $id)->where('company_id', $cid)->first();
        abort_unless($rule, 404);

        return response()->json(['success' => true, 'changed' => \App\Services\EmailOffice\EmailRules::runOnExisting($rule)]);
    }

    /**
     * GET email-office/emails/{id}/pdf -- the email as a PDF (header: from,
     * to, cc, date, subject, attachment names; then its formatted body, or
     * the plain text). Images / scripts of the email are not loaded.
     */
    public function pdf(Request $request, $id)
    {
        $e = $this->email($request, $id);
        $esc = fn ($v) => e((string) $v);
        $body = $e->body_html
            // Formatted body without scripts / remote resources (dompdf won't fetch them anyway).
            ? preg_replace(['#<script\b[^>]*>.*?</script>#is', '#<style\b[^>]*>.*?</style>#is', '#<(img|link|iframe|object|embed)\b[^>]*>#i'], '', (string) $e->body_html)
            : '<pre style="white-space:pre-wrap;font-family:DejaVu Sans, sans-serif;font-size:11px">'.$esc($e->body_text).'</pre>';
        $att = collect($e->attachments ?? [])->pluck('name')->filter()->implode(', ');
        $row = fn ($label, $value) => $value ? '<tr><td style="color:#666;width:70px;vertical-align:top">'.$label.'</td><td>'.$esc($value).'</td></tr>' : '';
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans, sans-serif;font-size:11px;color:#222}'
            .'h2{font-size:15px;margin:0 0 8px}table.h{border-collapse:collapse;margin-bottom:10px}table.h td{padding:2px 6px 2px 0}'
            .'hr{border:0;border-top:1px solid #ccc;margin:8px 0 12px}</style></head><body>'
            .'<h2>'.$esc($e->subject).'</h2><table class="h">'
            .$row('From', trim(($e->from_name ? trim($e->from_name, '"').' ' : '').'<'.$e->from_email.'>'))
            .$row('To', $e->to_emails).$row('Cc', $e->cc_emails)
            .$row('Date', optional($e->sent_at)->format('M j, Y g:i A'))
            .$row('Attached', $att)
            .'</table><hr>'.$body.'</body></html>';
        $name = preg_replace('/[^\w\- ]+/', '', mb_substr((string) $e->subject, 0, 60)) ?: 'email';

        return \PDF::loadHTML($html, 'UTF-8')->download(trim($name).'.pdf');
    }

    /** A claim -> helpdesk ticket (requester = the sender), linked back. */
    public function ticket(Request $request, $id)
    {
        $e = $this->email($request, $id);
        abort_if($e->linked_type === 'helpdesk_ticket', 422, 'This email already has a ticket.');
        abort_unless(ModuleFlags::enabled((int) $e->company_id, 'helpdesk'), 422, 'The Helpdesk module is off for this company.');
        $t = Helpdesk::open((int) $e->company_id, (string) $e->subject, trim((string) $e->body_text) ?: '(empty message)',
            ['name' => $e->from_name, 'email' => $e->from_email], 'email', ['email_message_id' => $e->message_id]);
        $e->update(['linked_type' => 'helpdesk_ticket', 'linked_id' => $t->id, 'category' => 'claim']);

        return response()->json(['success' => true, 'ticket' => ['id' => $t->id, 'number' => $t->number]]);
    }

    public function compose(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_view', true);
        $v = $request->validate([
            'to' => 'required|array|min:1', 'to.*' => 'email',
            'cc' => 'nullable|array', 'cc.*' => 'email',
            'subject' => 'required|string|max:900',
            'body' => 'required|string|max:100000',
        ]);
        try {
            EmailOfficeMailer::send($cid, $v['to'], $v['cc'] ?? [], $v['subject'], $v['body']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not send: '.$e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    /** Customer (or supplier) -> Email conversation: by party link or by their addresses. */
    public function party(Request $request)
    {
        $cid = $this->companyId($request);
        $type = $request->input('type') === 'provider' ? 'provider' : 'client';
        $id = (int) $request->input('id');
        $table = $type === 'provider' ? 'providers' : 'clients';
        $party = DB::table($table)->where('id', $id)->when($cid !== null, fn ($q) => $q->where('company_id', $cid))->first();
        abort_unless($party, 404);
        // The contact's own address + its additional ones (customers and suppliers),
        // and its contact person's.
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)),
            array_merge([$party->email ?? null, $party->contact_email ?? null], json_decode($party->additional_emails ?? '[]', true) ?: [])))));

        $q = OfficeEmail::where('company_id', $party->company_id)->where(function ($w) use ($type, $id, $emails) {
            $w->where(fn ($x) => $x->where('party_type', $type)->where('party_id', $id));
            foreach ($emails as $e) {
                $w->orWhere('from_email', $e)->orWhere('to_emails', 'like', "%$e%");
            }
        });
        if ($s = trim((string) $request->search)) {
            $q->where(fn ($w) => $w->where('subject', 'like', "%$s%")->orWhere('snippet', 'like', "%$s%"));
        }
        $limit = (int) $request->limit > 0 ? min(200, (int) $request->limit) : 10;
        $page = max(1, (int) $request->page);
        $cols = ['id', 'direction', 'source', 'subject', 'from_email', 'from_name', 'to_emails', 'sent_at', 'snippet', 'attachments', 'category', 'party_type', 'party_id', 'linked_type', 'linked_id', 'is_read'];

        // threads=1: one row per conversation (latest email on top, the rest in `messages`).
        if ($request->boolean('threads')) {
            $all = $q->orderBy('sent_at')->orderBy('id')->get(array_merge($cols, ['message_id', 'in_reply_to']));
            $threads = collect(self::threads($all))->sortByDesc(fn ($t) => $t[count($t) - 1]->sent_at)->values();
            $pageThreads = $threads->slice(($page - 1) * $limit, $limit)->values();
            $this->decorate($pageThreads->flatten(1));
            $rows = $pageThreads->map(function ($t) {
                $msgs = collect($t)->sortByDesc('sent_at')->values();
                $head = clone $msgs[0];
                $head->thread_count = $msgs->count();
                $head->thread_unread = $msgs->where('direction', 'in')->where('is_read', false)->count();
                $head->thread_subject = $msgs->last()->subject; // the conversation's first subject
                $head->messages = $msgs->map(fn ($m) => collect($m)->except(['message_id', 'in_reply_to'])->all())->all();
                unset($head->message_id, $head->in_reply_to);

                return $head;
            });

            return response()->json(['emails' => $rows, 'totalRows' => $threads->count(), 'addresses' => $emails]);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('sent_at')->offset(($page - 1) * $limit)->limit($limit)->get($cols);
        $this->decorate($rows);

        return response()->json(['emails' => $rows, 'totalRows' => $total, 'addresses' => $emails]);
    }

    /**
     * Groups emails (oldest first) into conversations: an email joins the
     * conversation of the email it answers (In-Reply-To = that Message-ID);
     * without that header, a "Re:/Fwd:" email joins the latest earlier
     * conversation with the same subject. Anything else starts a new one --
     * repeated automatic notices with the same subject stay separate.
     *
     * @return array<int, array<int, object>> conversations, each oldest first
     */
    private static function threads($emails): array
    {
        $threads = [];      // thread index => [emails]
        $byMessage = [];    // message-id => thread index
        $bySubject = [];    // normalized subject => latest thread index
        $norm = function (?string $s): array {
            $s = trim((string) $s);
            $isReply = false;
            while (preg_match('/^(re|fw|fwd|aw|wg|rv|tr|sv|vs)\s*(\[\d+\])?\s*:\s*/i', $s, $m)) {
                $s = trim(substr($s, strlen($m[0])));
                $isReply = true;
            }

            return [mb_strtolower(preg_replace('/\s+/', ' ', $s)), $isReply];
        };
        $key = fn ($id) => strtolower(trim((string) $id, " <>\t\r\n"));

        foreach ($emails as $e) {
            [$subject, $isReply] = $norm($e->subject);
            $t = null;
            if ($e->in_reply_to && isset($byMessage[$key($e->in_reply_to)])) {
                $t = $byMessage[$key($e->in_reply_to)];
            } elseif ($isReply && $subject !== '' && isset($bySubject[$subject])) {
                $t = $bySubject[$subject];
            }
            if ($t === null) {
                $t = count($threads);
                $threads[$t] = [];
            }
            $threads[$t][] = $e;
            if ($e->message_id) {
                $byMessage[$key($e->message_id)] = $t;
            }
            if ($subject !== '') {
                $bySubject[$subject] = $t;
            }
        }

        return $threads;
    }

    /**
     * GET email-office/subjects?direction=in|out&search&address -- the Subject
     * filter: subjects (an email and its replies count as one), most emails
     * first, within the current From / To filter when one is set.
     */
    public function subjects(Request $request)
    {
        $cid = $this->companyId($request);
        $s = trim((string) $request->search);
        $base = $this->listQuery($request->merge(['subject' => null, 'search' => null, 'category' => null, 'linked' => null, 'unread' => null, 'hide_recorded' => null]), $cid);
        $rows = $base->whereNotNull('subject_norm')->where('subject_norm', '!=', '')
            ->when($s !== '', fn ($q) => $q->where('subject_norm', 'like', '%'.mb_strtolower($s).'%'))
            ->selectRaw('subject_norm, MIN(subject) as sample, count(*) as n')
            ->groupBy('subject_norm')->orderByDesc('n')->limit(40)->get();

        return response()->json(['subjects' => $rows->map(fn ($r) => [
            'value' => $r->subject_norm,
            'label' => EmailOffice::normSubject($r->sample, false).' ('.$r->n.')',
            'count' => (int) $r->n,
        ])->values()]);
    }

    /**
     * GET email-office/addresses?direction=in|out&search -- the addresses for
     * the From (received) / To (sent) filter, most emails first, with the
     * contact each one belongs to.
     */
    public function addresses(Request $request)
    {
        $cid = $this->companyId($request);
        $in = $request->input('direction', 'in') !== 'out';
        $s = strtolower(trim((string) $request->search));
        if ($in) {
            return response()->json(['senders' => $this->senders($cid, $s)]);
        }
        $col = $in ? 'from_email' : 'to_emails';
        $rows = OfficeEmail::query()->when($cid !== null, fn ($q) => $q->where('company_id', $cid))
            ->where('direction', $in ? 'in' : 'out')->whereNotNull($col)
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where($col, 'like', "%$s%")->orWhere('from_name', 'like', "%$s%")))
            ->selectRaw("$col as address, MAX(from_name) as name, MAX(party_type) as party_type, MAX(party_id) as party_id, count(*) as n")
            ->groupBy($col)->orderByDesc('n')->limit(50)->get();
        $this->decorate($rows);

        return response()->json(['addresses' => $rows->map(fn ($r) => [
            'address' => $in ? $r->address : trim(explode(',', (string) $r->address)[0]),
            'label' => ($in && $r->name ? $r->name.' <'.$r->address.'>' : $r->address).' ('.$r->n.')'.($r->party_name ? ' — '.$r->party_name : ''),
            'count' => (int) $r->n,
        ])->unique('address')->values()]);
    }

    /**
     * SQL for an email's sender key: its display name without quotes, else
     * its address. Many companies send each email from a different address
     * (AT&T: att-services.cn.<number>@...) but keep the same name, so the
     * Received "From" filter groups by this, not by the address.
     */
    private const SENDER_SQL = "TRIM(BOTH '\"' FROM TRIM(COALESCE(NULLIF(TRIM(from_name), ''), from_email)))";

    /**
     * Received -> From filter: senders by name (most emails first), each with
     * its repeating subjects (2+ emails, e.g. a monthly bill) so a single
     * kind of email can be picked. Search matches name, address or subject.
     */
    private function senders(?int $cid, string $s): array
    {
        $base = fn () => OfficeEmail::query()->when($cid !== null, fn ($q) => $q->where('company_id', $cid))
            ->where('direction', 'in')->whereNotNull('from_email');
        // The search only picks WHICH senders show; their counts are always complete.
        $names = $s === '' ? null : $base()->where(fn ($w) => $w->where('from_email', 'like', "%$s%")
            ->orWhere('from_name', 'like', "%$s%")->orWhere('subject', 'like', "%$s%"))
            ->selectRaw(self::SENDER_SQL.' as sender, count(*) as n')->groupBy('sender')->orderByDesc('n')->limit(40)->pluck('sender')->all();
        if ($names === []) {
            return [];
        }
        $senders = $base()->when($names !== null, fn ($q) => $q->whereIn(DB::raw(self::SENDER_SQL), $names))
            ->selectRaw(self::SENDER_SQL.' as sender, count(*) as n, count(distinct from_email) as addrs')
            ->groupBy('sender')->orderByDesc('n')->limit(40)->get();
        if ($senders->isEmpty()) {
            return [];
        }
        $subjects = $base()->whereIn(DB::raw(self::SENDER_SQL), $senders->pluck('sender')->all())
            ->selectRaw(self::SENDER_SQL.' as sender, TRIM(subject) as subj, count(*) as n')
            ->groupBy('sender', 'subj')->having('n', '>=', 2)->orderByDesc('n')->get()
            ->groupBy(fn ($r) => mb_strtolower($r->sender));

        return $senders->map(fn ($r) => [
            'sender' => $r->sender,
            'count' => (int) $r->n,
            'addresses' => (int) $r->addrs,
            'subjects' => collect($subjects[mb_strtolower($r->sender)] ?? [])->filter(fn ($x) => $x->subj !== '' && $x->subj !== null)
                ->take(8)->map(fn ($x) => ['subject' => $x->subj, 'count' => (int) $x->n])->values()->all(),
        ])->values()->all();
    }

    /** GET email-office/party-options?type=client|provider&search -- contacts to assign an email to. */
    public function partyOptions(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_view', true);
        $table = $request->input('type') === 'provider' ? 'providers' : 'clients';
        $s = trim((string) $request->search);
        $rows = DB::table($table)->where('company_id', $cid)->whereNull('deleted_at')
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->orderBy('name')->limit(30)->get(['id', 'name', 'email']);

        return response()->json(['options' => $rows->map(fn ($r) => ['value' => $r->id, 'label' => $r->name.($r->email ? ' — '.$r->email : '')])]);
    }

    /**
     * POST email-office/assign-address {address, party_type, party_id, remember}
     * -- every email from / to that address belongs to this customer /
     * supplier. remember: also save the address on the contact (customer:
     * additional emails, or its email when empty; supplier: its email when
     * empty), so future emails match on their own.
     */
    public function assignAddress(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_view', true);
        $v = $request->validate([
            'address' => 'required|email',
            'party_type' => 'required|in:client,provider',
            'party_id' => 'required|integer',
            'remember' => 'boolean',
        ]);
        abort_unless($this->ownsParty($cid, $v['party_type'], (int) $v['party_id']), 422, 'That contact is not in this company.');
        $addr = strtolower($v['address']);
        $n = OfficeEmail::where('company_id', $cid)->where(fn ($w) => $w->where(fn ($x) => $x->where('direction', 'in')->where('from_email', $addr))
            ->orWhere(fn ($x) => $x->where('direction', 'out')->where('to_emails', 'like', '%'.$addr.'%')))
            ->update(['party_type' => $v['party_type'], 'party_id' => (int) $v['party_id']]);

        $remembered = false;
        if ($request->boolean('remember')) {
            $table = $v['party_type'] === 'client' ? 'clients' : 'providers';
            $row = DB::table($table)->where('id', $v['party_id'])->first();
            if ($row && ! trim((string) $row->email)) {
                DB::table($table)->where('id', $row->id)->update(['email' => $addr]);
                $remembered = true;
            } elseif ($row && strtolower(trim((string) $row->email)) !== $addr && $v['party_type'] === 'client') {
                $extra = json_decode((string) ($row->additional_emails ?? '[]'), true) ?: [];
                if (! in_array($addr, array_map('strtolower', $extra), true)) {
                    $extra[] = $addr;
                    DB::table('clients')->where('id', $row->id)->update(['additional_emails' => json_encode(array_values($extra))]);
                }
                $remembered = true;
            }
        }

        return response()->json(['success' => true, 'updated' => $n, 'remembered' => $remembered]);
    }

    /**
     * GET email-office/emails/{id}/history -- for a bill email already
     * recorded as an expense: its supplier's reminders / payment confirmations
     * of the same period, not recorded yet (to offer adding them to the
     * expense's history).
     */
    public function history(Request $request, $id)
    {
        $bill = $this->email($request, $id);
        $rows = $bill->party_type === 'provider' && $bill->party_id
            ? EmailOffice::billHistoryQuery($bill)->orderBy('sent_at')->get(['id', 'subject', 'sent_at', 'category'])
            : collect();

        return response()->json(['emails' => $rows, 'linked' => $bill->linked_type === 'expense']);
    }

    /** POST email-office/emails/{id}/history {ids[]} -- add them to the bill's expense history. */
    public function attachHistory(Request $request, $id)
    {
        $bill = $this->email($request, $id);
        $v = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);

        return response()->json(['success' => true, 'attached' => EmailOffice::attachHistory($bill, $v['ids'])]);
    }

    /**
     * GET email-office/linked?type=expense&id= -- the emails recorded as a
     * document (its bill + history), oldest first, for that document's page.
     */
    public function linked(Request $request)
    {
        $cid = $this->companyId($request);
        $v = $request->validate(['type' => 'required|in:sale,purchase,expense,helpdesk_ticket,quotation,service_job', 'id' => 'required|integer']);
        $rows = OfficeEmail::when($cid !== null, fn ($q) => $q->where('company_id', $cid))
            ->where('linked_type', $v['type'])->where('linked_id', $v['id'])->orderBy('sent_at')
            ->get(['id', 'direction', 'subject', 'from_email', 'from_name', 'sent_at', 'snippet', 'category', 'link_role', 'attachments']);
        $this->decorate($rows);

        return response()->json(['emails' => $rows]);
    }

    /**
     * POST email-office/emails/bulk {ids[], category?, is_read?} -- the list's
     * bulk actions on the selected emails (this company's only). A type set
     * here is a manual choice, like the Type menu.
     */
    public function bulk(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_view', true);
        $v = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'category' => 'sometimes|in:'.implode(',', OfficeEmail::categoriesFor($cid)),
            'is_read' => 'sometimes|boolean',
        ]);
        $set = [];
        if (isset($v['category'])) {
            $set += ['category' => $v['category'], 'category_manual' => true];
        }
        if (array_key_exists('is_read', $v)) {
            $set['is_read'] = (bool) $v['is_read'];
        }
        abort_if(! $set, 422, 'Nothing to change.');
        $n = OfficeEmail::where('company_id', $cid)->whereIn('id', $v['ids'])->update($set);

        return response()->json(['success' => true, 'updated' => $n]);
    }

    /**
     * POST email-office/assign-sender {email_id, party_type, party_id, count_only}
     * -- every received email under the same sender NAME as that email (any
     * address: AT&T sends each bill from a new one) belongs to this supplier /
     * customer. count_only: just how many there are (to ask first).
     */
    public function assignSender(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_view', true);
        $v = $request->validate([
            'email_id' => 'required|integer',
            'party_type' => 'required_unless:count_only,1|in:client,provider',
            'party_id' => 'required_unless:count_only,1|integer',
            'count_only' => 'boolean',
        ]);
        $e = $this->email($request, $v['email_id']);
        $sender = trim((string) DB::table('office_emails')->where('id', $e->id)->selectRaw(self::SENDER_SQL.' as s')->value('s'));
        $q = OfficeEmail::where('company_id', $cid)->where('direction', 'in')->whereRaw(self::SENDER_SQL.' = ?', [$sender]);
        if ($request->boolean('count_only')) {
            return response()->json(['sender' => $sender, 'count' => (clone $q)->count(), 'unassigned' => (clone $q)->whereNull('party_type')->count()]);
        }
        abort_unless($this->ownsParty($cid, $v['party_type'], (int) $v['party_id']), 422, 'That contact is not in this company.');
        $n = $q->update(['party_type' => $v['party_type'], 'party_id' => (int) $v['party_id']]);
        // Same sender, several accounts (two cards of one bank): each email
        // that names another supplier's account goes to that supplier.
        $moved = 0;
        if ($v['party_type'] === 'provider') {
            foreach ((clone $q)->where('party_id', (int) $v['party_id'])->get(['id', 'subject', 'body_text', 'from_name']) as $e) {
                $acc = EmailOffice::supplierByAccount($cid, $e->subject."\n".mb_substr((string) $e->body_text, 0, 5000), (int) $v['party_id'], $e->from_name);
                if ($acc && $acc !== (int) $v['party_id']) {
                    OfficeEmail::whereKey($e->id)->update(['party_id' => $acc]);
                    $moved++;
                }
            }
        }

        return response()->json(['success' => true, 'sender' => $sender, 'updated' => $n - $moved, 'moved_by_account' => $moved]);
    }

    /** Sales / purchases / expenses to link an email to (latest first, searchable). */
    public function linkOptions(Request $request)
    {
        $cid = $this->companyId($request, 'email_office_view', true);
        $s = trim((string) $request->search);
        if ($request->input('type') === 'sale') {
            $rows = DB::table('sales as x')->leftJoin('clients as c', 'c.id', '=', 'x.client_id')->where('x.company_id', $cid)->whereNull('x.deleted_at')
                ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('x.Ref', 'like', "%$s%")->orWhere('c.name', 'like', "%$s%")))
                ->when($request->filled('client_id'), fn ($q) => $q->orderByRaw('x.client_id = ? desc', [(int) $request->client_id]))
                ->orderByDesc('x.date')->limit(30)->get(['x.id', 'x.Ref', 'x.date', 'x.GrandTotal', 'c.name']);

            return response()->json(['options' => $rows->map(fn ($r) => ['value' => $r->id, 'label' => "{$r->Ref} · {$r->date} · ".($r->name ?: '—').' · '.number_format((float) $r->GrandTotal, 2)])]);
        }
        // A customer's quotations / service jobs (newest first, that customer's first).
        if ($request->input('type') === 'quotation') {
            $rows = DB::table('quotations as x')->leftJoin('clients as c', 'c.id', '=', 'x.client_id')->where('x.company_id', $cid)->whereNull('x.deleted_at')
                ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('x.Ref', 'like', "%$s%")->orWhere('c.name', 'like', "%$s%")))
                ->when($request->filled('client_id'), fn ($q) => $q->orderByRaw('x.client_id = ? desc', [(int) $request->client_id]))
                ->orderByDesc('x.date')->limit(30)->get(['x.id', 'x.Ref', 'x.date', 'x.GrandTotal', 'c.name']);

            return response()->json(['options' => $rows->map(fn ($r) => ['value' => $r->id, 'label' => "{$r->Ref} · {$r->date} · ".($r->name ?: '—').' · '.number_format((float) $r->GrandTotal, 2)])]);
        }
        if ($request->input('type') === 'service_job') {
            $rows = DB::table('service_jobs as x')->join('warehouses as w', 'w.id', '=', 'x.warehouse_id')->leftJoin('clients as c', 'c.id', '=', 'x.client_id')
                ->where('w.company_id', $cid)->whereNull('x.deleted_at')
                ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('x.Ref', 'like', "%$s%")->orWhere('c.name', 'like', "%$s%")->orWhere('x.service_item', 'like', "%$s%")))
                ->when($request->filled('client_id'), fn ($q) => $q->orderByRaw('x.client_id = ? desc', [(int) $request->client_id]))
                ->orderByDesc('x.id')->limit(30)->get(['x.id', 'x.Ref', 'x.status', 'x.service_item', 'c.name']);

            return response()->json(['options' => $rows->map(fn ($r) => ['value' => $r->id, 'label' => "{$r->Ref} · ".($r->name ?: '—').' · '.($r->service_item ?: $r->status)])]);
        }
        if ($request->input('type') === 'expense') {
            $rows = DB::table('expenses as e')->join('warehouses as w', 'w.id', '=', 'e.warehouse_id')->where('w.company_id', $cid)->whereNull('e.deleted_at')
                ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('e.Ref', 'like', "%$s%")->orWhere('e.details', 'like', "%$s%")))
                ->orderByDesc('e.date')->limit(30)->get(['e.id', 'e.Ref', 'e.date', 'e.amount', 'e.details']);

            return response()->json(['options' => $rows->map(fn ($r) => ['value' => $r->id, 'label' => "{$r->Ref} · {$r->date} · ".number_format((float) $r->amount, 2).' · '.mb_strimwidth((string) $r->details, 0, 40, '…')])]);
        }
        $rows = DB::table('purchases as p')->leftJoin('providers as v', 'v.id', '=', 'p.provider_id')->where('p.company_id', $cid)->whereNull('p.deleted_at')
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('p.Ref', 'like', "%$s%")->orWhere('v.name', 'like', "%$s%")))
            ->orderByDesc('p.date')->limit(30)->get(['p.id', 'p.Ref', 'p.date', 'p.GrandTotal', 'v.name']);

        return response()->json(['options' => $rows->map(fn ($r) => ['value' => $r->id, 'label' => "{$r->Ref} · {$r->date} · ".($r->name ?: '—').' · '.number_format((float) $r->GrandTotal, 2)])]);
    }
}
