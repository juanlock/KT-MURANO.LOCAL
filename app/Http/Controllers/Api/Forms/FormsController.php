<?php

namespace App\Http\Controllers\Api\Forms;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\CompanyFiles;
use App\Support\CompanyNames;
use App\Support\FormSchema;
use App\Support\ModuleFlags;
use App\Support\PublicUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Forms module -- back office (2026-10-24). Permissions: forms_view (see forms and
 * submissions), forms_manage (build / publish / delete). Module "forms".
 * Multicompany: lists follow listCompanyId() (superadmin/AdminSystem = every
 * company, with company_name); a new form belongs to the acting company; a
 * record is opened only when its company is one the user may act on.
 *
 *  GET    forms                       list (+ submissions / unread counts)
 *  POST   forms {name}                new form (a starter Name / Email / Message)
 *  GET    forms/{id}                  form + its embed codes
 *  PUT    forms/{id}                  name, fields, settings
 *  POST   forms/{id}/duplicate
 *  DELETE forms/{id}                  (its submissions too)
 *  GET    forms/{id}/submissions      ?search &page &per_page &unread &all=1 (export)
 *  GET    forms/submissions/{id}      one submission (marked read)
 *  PUT    forms/submissions/{id}      {is_read}
 *  DELETE forms/submissions/{id}
 *  GET    forms/submissions/{id}/file/{key}   a "file" field's upload (private)
 */
class FormsController extends Controller
{
    use ScopesToCompany;

    private function can(Request $request, string $perm): void
    {
        $user = $request->user('api');
        $ok = HelpdeskAccess::can($user, $perm) || ($perm === 'forms_view' && HelpdeskAccess::can($user, 'forms_manage'));
        abort_unless($ok, 403, 'You do not have permission for Forms.');
    }

    /** The form, if the user may act on its company (404 otherwise). */
    private function form(Request $request, int $id): object
    {
        $f = DB::table('forms')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($f && $this->mayActOn($request, (int) $f->company_id), 404);
        ModuleFlags::abortUnlessEnabled((int) $f->company_id, 'forms');

        return $f;
    }

    private function mayActOn(Request $request, int $companyId): bool
    {
        $user = $request->user('api');
        if ($user && $user->type === 'SuperAdmin') {
            $list = $this->listCompanyId($request);

            return $list === null || $list === $companyId;
        }

        return (int) ($user->company_id ?? 0) === $companyId;
    }

    private function out(object $f, bool $full = false): array
    {
        $a = [
            'id' => $f->id, 'company_id' => $f->company_id, 'name' => $f->name, 'public_key' => $f->public_key,
            'status' => (bool) $f->status, 'created_at' => $f->created_at, 'updated_at' => $f->updated_at,
            'legacy' => $f->legacy_source ? $f->legacy_source.' #'.$f->legacy_id : null,
        ];
        if ($full) {
            $a['fields'] = json_decode((string) $f->fields, true) ?: [];
            $a['settings'] = FormSchema::settings(json_decode((string) $f->settings, true));
            $a['next_number'] = (int) $f->next_number;
            $base = PublicUrl::base((int) $f->company_id);
            $a['links'] = [
                'page' => $base.'/f/'.$f->public_key,
                'embed' => $base.'/f/'.$f->public_key.'/embed',
                'script' => $base.'/embed/murano-forms.js',
            ];
        }

        return $a;
    }

    public function index(Request $request)
    {
        $this->can($request, 'forms_view');
        $q = DB::table('forms')->whereNull('deleted_at');
        $this->scopeListToCompany($q, $request);
        $forms = $q->orderBy('name')->get();
        $counts = DB::table('form_submissions')->whereIn('form_id', $forms->pluck('id'))->whereNull('deleted_at')
            ->selectRaw('form_id, count(*) n, sum(is_read = 0) unread, max(created_at) last_at')->groupBy('form_id')->get()->keyBy('form_id');
        $rows = $forms->map(function ($f) use ($counts) {
            $c = $counts[$f->id] ?? null;

            return $this->out($f) + [
                'submissions' => (int) ($c->n ?? 0), 'unread' => (int) ($c->unread ?? 0), 'last_submission_at' => $c->last_at ?? null,
            ];
        })->all();

        return response()->json(['forms' => CompanyNames::attach($rows)]);
    }

    /** A starter form: Name, Email, Message (the form's language = the company's). */
    public function store(Request $request)
    {
        $this->can($request, 'forms_manage');
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');
        ModuleFlags::abortUnlessEnabled($cid, 'forms');
        $v = $request->validate(['name' => 'required|string|max:150']);
        $es = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('default_language') === 'es';
        $fields = [
            ['key' => 'name', 'type' => 'text', 'label' => $es ? 'Nombre' : 'Name', 'required' => true, 'width' => 'half'],
            ['key' => 'email', 'type' => 'email', 'label' => $es ? 'Correo electrónico' : 'Email', 'required' => true, 'width' => 'half'],
            ['key' => 'message', 'type' => 'textarea', 'label' => $es ? 'Mensaje' : 'Message', 'required' => true, 'rows' => 5],
        ];
        $settings = FormSchema::defaultSettings();
        if ($es) {
            $settings = array_merge($settings, ['language' => 'es', 'submit_text' => 'Enviar',
                'success_message' => '¡Gracias! Recibimos su mensaje.', 'notify_subject' => 'Nuevo envío: {form}']);
        }
        $settings['reply_to_field'] = 'email';
        $id = DB::table('forms')->insertGetId([
            'company_id' => $cid, 'name' => strip_tags($v['name']), 'public_key' => self::newKey(), 'status' => true,
            'fields' => json_encode(FormSchema::fields($fields)), 'settings' => json_encode(FormSchema::settings($settings)),
            'next_number' => 1, 'created_by' => $request->user('api')->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['form' => $this->out(DB::table('forms')->find($id), true)], 201);
    }

    /**
     * GET forms/dashboard?from&to (2026-10-24, the 8 cards + lists):
     *  - users (unique visitors), starters + starter rate, conversions + conversion
     *    rate, abandons (started, not sent) + abandonment rate, completion rate
     *    (conversions / starters); forms viewed / filled / submitted; average
     *    filling time -- rates over unique users, like the old Easy Forms panel
     *  - performance per day (users, starters, conversions), most visited / most
     *    started / most conversions / last updated forms, new (unread) submissions,
     *    pages the submissions come from.
     * Counters: form_stats_daily (Easy Forms' history imported); submissions
     * lists: form_submissions.
     */
    public function dashboard(Request $request)
    {
        $this->can($request, 'forms_view');
        $from = $request->input('from') ?: now()->subDays(29)->toDateString();
        $to = $request->input('to') ?: now()->toDateString();
        $fq = DB::table('forms')->whereNull('deleted_at');
        $this->scopeListToCompany($fq, $request);
        $forms = $fq->get(['id', 'company_id', 'name', 'status', 'settings', 'updated_at'])->keyBy('id');
        $ids = $forms->keys();

        $subs = DB::table('form_submissions')->whereIn('form_id', $ids)->whereNull('deleted_at')
            ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
        $subsByForm = (clone $subs)->selectRaw('form_id, count(*) n')->groupBy('form_id')->pluck('n', 'form_id');
        $stats = DB::table('form_stats_daily')->whereIn('form_id', $ids)->whereBetween('day', [$from, $to]);
        $sum = 'sum(views) v, sum(users) u, sum(starts) s, sum(submissions) c, sum(fill_ms) ms, sum(timed) t';
        $statsByDay = (clone $stats)->selectRaw("day, $sum")->groupBy('day')->get()->keyBy(fn ($r) => (string) $r->day);
        $statsByForm = (clone $stats)->selectRaw("form_id, $sum")->groupBy('form_id')->get()->keyBy('form_id');
        $t = (clone $stats)->selectRaw($sum)->first();
        $unreadByForm = DB::table('form_submissions')->whereIn('form_id', $ids)->whereNull('deleted_at')->where('is_read', false)
            ->selectRaw('form_id, count(*) n')->groupBy('form_id')->pluck('n', 'form_id');

        // Performance per day (per month beyond ~2 years).
        $point = fn ($k, $r) => ['d' => $k, 'users' => (int) ($r->u ?? 0), 'starts' => (int) ($r->s ?? 0), 'submissions' => (int) ($r->c ?? 0), 'views' => (int) ($r->v ?? 0)];
        $series = [];
        $start = \Carbon\Carbon::parse($from);
        $end = \Carbon\Carbon::parse($to);
        if ($start->diffInDays($end) <= 731) {
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $k = $d->toDateString();
                $series[] = $point($k, $statsByDay[$k] ?? null);
            }
        } else {
            $byMonth = [];
            foreach ($statsByDay as $k => $r) {
                $m = substr($k, 0, 7);
                foreach (['v', 'u', 's', 'c'] as $c) {
                    $byMonth[$m][$c] = ($byMonth[$m][$c] ?? 0) + (int) $r->$c;
                }
            }
            ksort($byMonth);
            foreach ($byMonth as $m => $x) {
                $series[] = $point($m, (object) $x);
            }
        }

        $users = (int) ($t->u ?? 0);
        $starters = (int) ($t->s ?? 0);
        $conversions = (int) ($t->c ?? 0);
        $abandons = max(0, $starters - $conversions);
        $rate = fn ($n, $of) => $of > 0 ? (int) round(min(100, $n * 100 / $of)) : 0;
        $avgMs = (int) ($t->t ?? 0) > 0 ? (int) round($t->ms / $t->t) : 0;
        $withStat = fn ($col) => $statsByForm->filter(fn ($r) => (int) $r->$col > 0)->count();

        $perForm = $forms->map(function ($f) use ($subsByForm, $statsByForm, $unreadByForm, $rate) {
            $r = $statsByForm[$f->id] ?? null;
            $u = (int) ($r->u ?? 0);

            return ['id' => $f->id, 'company_id' => $f->company_id, 'name' => $f->name, 'status' => (bool) $f->status,
                'views' => (int) ($r->v ?? 0), 'users' => $u, 'starts' => (int) ($r->s ?? 0), 'conversions' => (int) ($r->c ?? 0),
                'submissions' => (int) ($subsByForm[$f->id] ?? 0), 'unread' => (int) ($unreadByForm[$f->id] ?? 0),
                'conversion' => $u ? $rate((int) ($r->c ?? 0), $u) : null, 'updated_at' => $f->updated_at];
        })->sortByDesc('submissions')->values()->all();
        $top = fn ($key) => CompanyNames::attach(collect($perForm)->filter(fn ($f) => $f[$key] > 0)->sortByDesc($key)->take(5)->values()->all());

        $prefix = fn ($fid) => FormSchema::settings(json_decode((string) ($forms[$fid]->settings ?? ''), true))['number_prefix'];
        $list = function ($q) use ($forms, $prefix) {
            return $q->limit(8)->get()->map(function ($s) use ($forms, $prefix) {
                $data = json_decode((string) $s->data, true) ?: [];
                $first = collect($data)->first(fn ($v) => is_string($v) && trim($v) !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v));

                return ['id' => $s->id, 'form_id' => $s->form_id, 'form' => $forms[$s->form_id]->name ?? '', 'company_id' => $s->company_id,
                    'ref' => $prefix($s->form_id).$s->number, 'summary' => mb_substr((string) $first, 0, 80), 'is_read' => (bool) $s->is_read, 'created_at' => $s->created_at];
            })->all();
        };
        $base = fn () => DB::table('form_submissions')->whereIn('form_id', $ids)->whereNull('deleted_at')->orderByDesc('id');

        // Where the submissions were sent from (the page that showed the form).
        $pages = (clone $subs)->whereNotNull('page_url')->pluck('page_url')
            ->map(function ($u) {
                $p = parse_url((string) $u);

                return isset($p['host']) ? preg_replace('/^www\./', '', $p['host']).rtrim($p['path'] ?? '', '/') : null;
            })->filter()->countBy()->sortDesc()->take(8)->map(fn ($n, $page) => ['page' => $page, 'submissions' => $n])->values()->all();

        return response()->json([
            'from' => $from, 'to' => $to,
            'cards' => [
                'users' => $users, 'starter_rate' => $rate($starters, $users),
                'starters' => $starters, 'completion_rate' => $rate($conversions, $starters),
                'conversions' => $conversions, 'conversion_rate' => $rate($conversions, $users),
                'abandons' => $abandons, 'abandonment_rate' => $rate($abandons, $users),
                'viewed_forms' => $withStat('u'), 'filled_forms' => $withStat('s'), 'submitted_forms' => $withStat('c'),
                'avg_fill_seconds' => (int) round($avgMs / 1000),
                'views' => (int) ($t->v ?? 0),
                'unread' => (int) array_sum($unreadByForm->all()),
                'forms' => $forms->count(), 'published' => $forms->where('status', 1)->count(),
            ],
            'series' => $series,
            'forms' => CompanyNames::attach($perForm),
            'most_visited' => $top('users'),
            'most_started' => $top('starts'),
            'most_conversions' => $top('conversions'),
            'last_updated' => CompanyNames::attach(collect($perForm)->sortByDesc(fn ($f) => (string) $f['updated_at'])->take(5)->values()->all()),
            'unread' => CompanyNames::attach($list($base()->where('is_read', false))),
            'latest' => CompanyNames::attach($list($base())),
            'pages' => $pages,
        ]);
    }

    /**
     * GET forms/report?form_id&from&to&search&page&per_page&all=1 -- every submission
     * of every form the user may see (AdminSystem: all companies), newest first,
     * with its form, number, date, a summary and every field as "label: value".
     */
    public function report(Request $request)
    {
        $this->can($request, 'forms_view');
        $fq = DB::table('forms')->whereNull('deleted_at');
        $this->scopeListToCompany($fq, $request);
        $forms = $fq->get(['id', 'company_id', 'name', 'fields', 'settings'])->keyBy('id');
        $q = DB::table('form_submissions')->whereIn('form_id', $forms->keys())->whereNull('deleted_at');
        if ($fid = (int) $request->input('form_id')) {
            $q->where('form_id', $fid);
        }
        if ($from = $request->input('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $q->whereDate('created_at', '<=', $to);
        }
        if ($s = trim((string) $request->input('search'))) {
            $q->where('data', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%');
        }
        $total = (clone $q)->count();
        $q->orderByDesc('created_at')->orderByDesc('id');
        if ($request->boolean('all')) {
            $q->limit(20000);
        } else {
            $q->forPage(max(1, (int) $request->input('page', 1)), max(5, min(200, (int) $request->input('per_page', 20))));
        }
        $meta = [];
        $rows = $q->get()->map(function ($s) use ($forms, &$meta) {
            $f = $forms[$s->form_id];
            $meta[$f->id] ??= [
                'inputs' => array_filter(FormSchema::inputs(json_decode((string) $f->fields, true) ?: []), fn ($x) => $x['type'] !== 'hidden'),
                'prefix' => FormSchema::settings(json_decode((string) $f->settings, true))['number_prefix'],
            ];
            $data = json_decode((string) $s->data, true) ?: [];
            $details = [];
            foreach ($meta[$f->id]['inputs'] as $in) {
                $details[] = ['label' => trim(rtrim($in['label'] ?: $in['key'], ':')), 'value' => FormSchema::text($data[$in['key']] ?? '')];
            }
            $first = collect($details)->first(fn ($d) => $d['value'] !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['value']));

            return ['id' => $s->id, 'form_id' => $f->id, 'form' => $f->name, 'company_id' => $f->company_id,
                'ref' => $meta[$f->id]['prefix'].$s->number, 'created_at' => $s->created_at, 'is_read' => (bool) $s->is_read,
                'summary' => mb_substr((string) ($first['value'] ?? ''), 0, 120), 'details' => $details,
                'page_url' => $s->page_url, 'ip' => $s->ip];
        })->all();

        return response()->json([
            'submissions' => CompanyNames::attach($rows), 'total' => $total,
            'forms' => CompanyNames::attach($forms->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'company_id' => $f->company_id])->values()->all()),
        ]);
    }

    public static function newKey(): string
    {
        do {
            $k = Str::lower(Str::random(12));
        } while (DB::table('forms')->where('public_key', $k)->exists());

        return $k;
    }

    public function show(Request $request, int $id)
    {
        $this->can($request, 'forms_view');

        return response()->json(['form' => $this->out($this->form($request, $id), true)]);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request, 'forms_manage');
        $f = $this->form($request, $id);
        $v = $request->validate([
            'name' => 'required|string|max:150',
            'status' => 'boolean',
            'fields' => 'array',
            'settings' => 'array',
            'next_number' => 'nullable|integer|min:1|max:99999999',
        ]);
        $set = ['name' => strip_tags($v['name']), 'updated_at' => now()];
        if (array_key_exists('status', $v)) {
            $set['status'] = (bool) $v['status'];
        }
        if (array_key_exists('fields', $v)) {
            $set['fields'] = json_encode(FormSchema::fields($v['fields']));
        }
        if (array_key_exists('settings', $v)) {
            $set['settings'] = json_encode(FormSchema::settings($v['settings']));
        }
        if (! empty($v['next_number'])) {
            // Never below a number already used.
            $used = (int) DB::table('form_submissions')->where('form_id', $f->id)->max('number');
            $set['next_number'] = max((int) $v['next_number'], $used + 1);
        }
        DB::table('forms')->where('id', $f->id)->update($set);

        return response()->json(['form' => $this->out(DB::table('forms')->find($f->id), true)]);
    }

    /**
     * POST forms/{id}/share-email {to: [emails], subject?, message?} -- sends the
     * form's link (Publish -> Send by email) through the company's mail server.
     */
    public function shareEmail(Request $request, int $id)
    {
        $this->can($request, 'forms_manage');
        $f = $this->form($request, $id);
        $v = $request->validate([
            'to' => 'required|array|min:1|max:20',
            'to.*' => 'email:rfc|max:190',
            'subject' => 'nullable|string|max:190',
            'message' => 'nullable|string|max:5000',
        ]);
        $cid = (int) $f->company_id;
        $company = trim((string) DB::table('companies')->where('id', $cid)->value('name')) ?: 'Forms';
        $link = PublicUrl::base($cid).'/f/'.$f->public_key;
        $s = FormSchema::settings(json_decode((string) $f->settings, true));
        $es = $s['language'] === 'es';
        $subject = trim((string) ($v['subject'] ?? '')) ?: ($s['title'] ?: $f->name);
        $msg = trim(strip_tags((string) ($v['message'] ?? ''))) ?: ($es ? 'Por favor complete el siguiente formulario:' : 'Please fill in the following form:');
        $btn = $es ? 'Abrir el formulario' : 'Open the form';
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:560px">'
            .'<p>'.nl2br(e($msg)).'</p><p><a href="'.e($link).'" style="display:inline-block;background:'.e($s['primary_color']).';color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px">'.e($btn).'</a></p>'
            .'<p style="color:#888;font-size:12px">'.e($link).'</p><p style="color:#888;font-size:12px;margin-top:24px">'.e($company).'</p></div>';
        [$mailer, $from] = \App\Support\CompanyMail::mailer($cid);
        $sent = [];
        $failed = [];
        foreach (array_unique($v['to']) as $to) {
            try {
                $mailer->html($html, function ($m) use ($to, $from, $company, $subject, $msg, $link) {
                    $m->to($to)->from($from ?: config('mail.from.address'), $company)->subject($subject);
                    $m->getSymfonyMessage()->text($msg."\n\n".$link."\n\n--\n".$company);
                });
                $sent[] = $to;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('form share mail failed: '.$e->getMessage(), ['form' => $f->id, 'to' => $to]);
                $failed[] = $to;
            }
        }

        return response()->json(['sent' => $sent, 'failed' => $failed], $sent ? 200 : 422);
    }

    public function duplicate(Request $request, int $id)
    {
        $this->can($request, 'forms_manage');
        $f = $this->form($request, $id);
        $nid = DB::table('forms')->insertGetId([
            'company_id' => $f->company_id, 'name' => mb_substr($f->name.' (copy)', 0, 150), 'public_key' => self::newKey(),
            'status' => false, 'fields' => $f->fields, 'settings' => $f->settings, 'next_number' => 1,
            'created_by' => $request->user('api')->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['form' => $this->out(DB::table('forms')->find($nid), true)], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $this->can($request, 'forms_manage');
        $f = $this->form($request, $id);
        DB::transaction(function () use ($f) {
            DB::table('form_submissions')->where('form_id', $f->id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
            DB::table('forms')->where('id', $f->id)->update(['deleted_at' => now()]);
        });

        return response()->json(['success' => true]);
    }

    public function submissions(Request $request, int $id)
    {
        $this->can($request, 'forms_view');
        $f = $this->form($request, $id);
        $q = DB::table('form_submissions')->where('form_id', $f->id)->whereNull('deleted_at');
        if ($request->boolean('unread')) {
            $q->where('is_read', false);
        }
        if ($s = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('data', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%')->orWhere('number', ltrim(preg_replace('/\D/', '', $s), '0') ?: -1));
        }
        if ($from = $request->input('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $q->whereDate('created_at', '<=', $to);
        }
        $total = (clone $q)->count();
        $q->orderByDesc('id');
        if (! $request->boolean('all')) {
            $per = max(5, min(200, (int) $request->input('per_page', 20)));
            $q->forPage(max(1, (int) $request->input('page', 1)), $per);
        } else {
            $q->limit(20000);
        }
        $prefix = FormSchema::settings(json_decode((string) $f->settings, true))['number_prefix'];
        $rows = $q->get()->map(fn ($s) => $this->submissionOut($s, $prefix))->all();

        return response()->json([
            'submissions' => $rows, 'total' => $total,
            'fields' => FormSchema::inputs(json_decode((string) $f->fields, true) ?: []),
            'form' => $this->out($f),
        ]);
    }

    private function submissionOut(object $s, string $prefix): array
    {
        return [
            'id' => $s->id, 'form_id' => $s->form_id, 'number' => (int) $s->number, 'ref' => $prefix.$s->number,
            'data' => json_decode((string) $s->data, true) ?: [], 'is_read' => (bool) $s->is_read,
            'ip' => $s->ip, 'user_agent' => $s->user_agent, 'page_url' => $s->page_url, 'created_at' => $s->created_at,
        ];
    }

    private function submission(Request $request, int $id): array
    {
        $s = DB::table('form_submissions')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($s, 404);
        $f = $this->form($request, (int) $s->form_id);

        return [$s, $f];
    }

    public function showSubmission(Request $request, int $id)
    {
        $this->can($request, 'forms_view');
        [$s, $f] = $this->submission($request, $id);
        if (! $s->is_read) {
            DB::table('form_submissions')->where('id', $s->id)->update(['is_read' => true]);
            $s->is_read = 1;
        }
        $prefix = FormSchema::settings(json_decode((string) $f->settings, true))['number_prefix'];

        return response()->json([
            'submission' => $this->submissionOut($s, $prefix),
            'fields' => FormSchema::inputs(json_decode((string) $f->fields, true) ?: []),
            'form' => $this->out($f),
        ]);
    }

    public function updateSubmission(Request $request, int $id)
    {
        $this->can($request, 'forms_view');
        [$s] = $this->submission($request, $id);
        $v = $request->validate(['is_read' => 'required|boolean']);
        DB::table('form_submissions')->where('id', $s->id)->update(['is_read' => $v['is_read'], 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function destroySubmission(Request $request, int $id)
    {
        $this->can($request, 'forms_manage');
        [$s] = $this->submission($request, $id);
        DB::table('form_submissions')->where('id', $s->id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function file(Request $request, int $id, string $key)
    {
        $this->can($request, 'forms_view');
        [$s] = $this->submission($request, $id);
        $v = (json_decode((string) $s->data, true) ?: [])[$key] ?? null;
        abort_unless(is_array($v) && ! empty($v['path']) && CompanyFiles::owns($v['path'], (int) $s->company_id, 'forms'), 404);

        return CompanyFiles::download($v['path'], $v['name'] ?? basename($v['path']));
    }
}
