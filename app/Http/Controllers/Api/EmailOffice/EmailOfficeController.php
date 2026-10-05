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
        if (in_array($request->category, OfficeEmail::CATEGORIES, true)) {
            $q->where('category', $request->category);
        }
        if ($request->filled('party_type')) {
            $request->party_type === 'none' ? $q->whereNull('party_type') : $q->where('party_type', $request->party_type);
        }
        if ($request->filled('linked')) {
            $request->linked ? $q->whereNotNull('linked_type') : $q->whereNull('linked_type');
        }
        if ($request->boolean('unread')) {
            $q->where('is_read', false);
        }
        if ($request->filled('source')) {
            $q->where('source', $request->source);
        }
        // One address: the emails FROM it or TO it (a conversation with that sender / recipient).
        if ($addr = strtolower(trim((string) $request->input('address')))) {
            $q->where(fn ($w) => $w->where('from_email', $addr)->orWhere('to_emails', 'like', '%'.$addr.'%')->orWhere('cc_emails', 'like', '%'.$addr.'%'));
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
                'attachments', 'category', 'category_manual', 'party_type', 'party_id', 'linked_type', 'linked_id', 'is_read', 'status']);
        $this->decorate($rows);

        // Cards: counts for the same filters except category.
        $base = $this->listQuery($request->merge(['category' => null, 'unread' => null]), $cid);
        $stats = (clone $base)->selectRaw('category, count(*) n')->groupBy('category')->pluck('n', 'category');

        return response()->json([
            'emails' => $rows,
            'totalRows' => $total,
            'stats' => [
                'total' => (int) $stats->sum(),
                'bill' => (int) ($stats['bill'] ?? 0),
                'claim' => (int) ($stats['claim'] ?? 0),
                'reminder' => (int) ($stats['reminder'] ?? 0),
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
        foreach ($rows as $r) {
            $r->party_name = $r->party_type ? ($names[$r->party_type][$r->party_id] ?? null) : null;
            $r->attachments_count = count($r->attachments ?? []);
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

    public function update(Request $request, $id)
    {
        $e = $this->email($request, $id);
        $v = $request->validate([
            'category' => 'sometimes|in:'.implode(',', OfficeEmail::CATEGORIES),
            'linked_type' => 'sometimes|nullable|in:sale,purchase,expense,helpdesk_ticket',
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
            } else {
                $v['linked_id'] = null;
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
        $emails = array_values(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)),
            array_merge([$party->email ?? null], $type === 'client' ? (json_decode($party->additional_emails ?? '[]', true) ?: []) : []))));

        $q = OfficeEmail::where('company_id', $party->company_id)->where(function ($w) use ($type, $id, $emails) {
            $w->where(fn ($x) => $x->where('party_type', $type)->where('party_id', $id));
            foreach ($emails as $e) {
                $w->orWhere('from_email', $e)->orWhere('to_emails', 'like', "%$e%");
            }
        });
        if ($s = trim((string) $request->search)) {
            $q->where(fn ($w) => $w->where('subject', 'like', "%$s%")->orWhere('snippet', 'like', "%$s%"));
        }
        $total = (clone $q)->count();
        $limit = (int) $request->limit > 0 ? min(200, (int) $request->limit) : 10;
        $page = max(1, (int) $request->page);
        $rows = $q->orderByDesc('sent_at')->offset(($page - 1) * $limit)->limit($limit)
            ->get(['id', 'direction', 'source', 'subject', 'from_email', 'from_name', 'to_emails', 'sent_at', 'snippet', 'attachments', 'category', 'party_type', 'party_id', 'linked_type', 'linked_id', 'is_read']);
        $this->decorate($rows);

        return response()->json(['emails' => $rows, 'totalRows' => $total, 'addresses' => $emails]);
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
