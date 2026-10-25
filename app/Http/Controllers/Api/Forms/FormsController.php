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
