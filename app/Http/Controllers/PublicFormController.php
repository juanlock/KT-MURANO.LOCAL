<?php

namespace App\Http\Controllers;

use App\Services\FormNotifier;
use App\Support\CompanyFiles;
use App\Support\FormSchema;
use App\Support\FormStats;
use App\Support\ModuleFlags;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Forms module -- public side (2026-10-24), for visitors without a login.
 *
 *  GET  /f/{key}          the form on its own page (share link)
 *  GET  /f/{key}/embed    the same form in a bare layout, for an iframe; the
 *                         script /js/murano-forms.js creates that iframe on any
 *                         website and resizes it (postMessage)
 *  POST /f/{key}          a submission (JSON for the page's script, redirect
 *                         without JavaScript)
 *
 * Like the public booking page: no X-Frame-Options (frame-ancestors = the form's
 * allowed sites, or any), the POST is CSRF-exempt (a cross-site iframe has no
 * session cookie), spam is held off by route throttle + honeypot + minimum
 * time-to-submit (encrypted timestamp rendered with the form).
 */
class PublicFormController extends Controller
{
    private const MAX_FORM_AGE = 86400 * 2;

    private function load(string $key): array
    {
        $f = DB::table('forms')->where('public_key', $key)->whereNull('deleted_at')->first();
        abort_unless($f && ModuleFlags::enabled((int) $f->company_id, 'forms'), 404);

        return [$f, json_decode((string) $f->fields, true) ?: [], FormSchema::settings(json_decode((string) $f->settings, true))];
    }

    /** The header's logo / name: the company's, the form's own (settings.logo = custom) or none. */
    private function brand(int $companyId, array $s): array
    {
        if ($s['logo'] === 'none') {
            return ['name' => '', 'logo' => null];
        }
        if ($s['logo'] === 'custom') {
            return ['name' => '', 'logo' => CompanyFiles::owns($s['logo_path'], $companyId, 'form-logos') ? '/'.$s['logo_path'] : null];
        }
        $name = (string) DB::table('companies')->where('id', $companyId)->value('name');
        $logo = (string) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('logo');
        $url = null;
        if ($logo !== '' && ! str_contains($logo, 'logo-default')) {
            $url = Str::startsWith($logo, ['http://', 'https://']) ? $logo : '/'.ltrim(str_contains($logo, '/') ? $logo : 'images/'.$logo, '/');
        }

        return ['name' => $name, 'logo' => $url];
    }

    private function render(Request $request, string $key, bool $embed)
    {
        [$f, $fields, $s] = $this->load($key);
        $open = (bool) $f->status && $s['active'];
        // Dashboard: a view (not the builder's preview, not a re-render after an error).
        if ($open && ! $request->has('preview') && ! session()->has('form_errors_'.$f->id)) {
            FormStats::view($f, $request);
        }
        $view = view('forms.public', [
            'form' => $f, 'fields' => $fields, 's' => $s, 'embed' => $embed, 'open' => $open,
            'brand' => $this->brand((int) $f->company_id, $s),
            'stamp' => encrypt(time()),
            'action' => url('/f/'.$f->public_key),
            'sent' => session('form_sent_'.$f->id),
            'errors_bag' => session('form_errors_'.$f->id, []),
            'old' => session('form_old_'.$f->id, []),
            'closedMsg' => FormSchema::msg($s['language'], 'closed'),
        ]);
        $ancestors = $s['allowed_domains']
            ? implode(' ', array_merge(["'self'"], array_merge(...array_map(fn ($d) => ["https://$d", "https://*.$d", "http://$d", "http://*.$d"], $s['allowed_domains']))))
            : '*';

        return response($view)->header('Content-Security-Policy', 'frame-ancestors '.$ancestors);
    }

    public function page(Request $request, string $key)
    {
        return $this->render($request, $key, false);
    }

    public function embed(Request $request, string $key)
    {
        return $this->render($request, $key, true);
    }

    /** POST /f/{key}/start: the visitor began typing (once per page load) -- dashboard funnel. */
    public function start(Request $request, string $key)
    {
        [$f, , $s] = $this->load($key);
        if ($f->status && $s['active'] && ! $request->has('preview')) {
            FormStats::bump($f, 'starts');
        }

        return response()->noContent();
    }

    public function submit(Request $request, string $key)
    {
        [$f, $fields, $s] = $this->load($key);
        $lang = $s['language'];
        $json = $request->expectsJson() || $request->ajax();
        $fail = function (array $errors, string $message, int $status = 422) use ($json, $f, $request) {
            if ($json) {
                return response()->json(['ok' => false, 'message' => $message, 'errors' => (object) $errors], $status);
            }

            return back()->with('form_errors_'.$f->id, $errors + ['_' => $message])->with('form_old_'.$f->id, $request->except(['_hp', '_t']));
        };

        if (! $f->status || ! $s['active']) {
            return $fail([], FormSchema::msg($lang, 'closed'), 403);
        }
        // Spam: honeypot + time-to-submit (silently "accepted", nothing saved).
        $age = null;
        try {
            $age = time() - (int) decrypt((string) $request->input('_t'));
        } catch (DecryptException $e) {
            $age = null;
        }
        $bot = ($s['honeypot'] && $request->filled('_hp')) || $age === null || $age < $s['min_seconds'] || $age > self::MAX_FORM_AGE;
        // Allowed sites: the page the form was embedded in must be one of them.
        $page = mb_substr((string) $request->input('_page', $request->headers->get('referer', '')), 0, 500);
        if ($s['allowed_domains'] && ! $bot) {
            $host = FormSchema::domain((string) parse_url($page, PHP_URL_HOST));
            $own = FormSchema::domain((string) parse_url(url('/'), PHP_URL_HOST));
            $okHost = $host === $own || collect($s['allowed_domains'])->contains(fn ($d) => $host === $d || str_ends_with($host, '.'.$d));
            if (! $okHost) {
                return $fail([], FormSchema::msg($lang, 'spam'), 403);
            }
        }
        if ($bot) {
            return $json ? response()->json(['ok' => true, 'message' => $s['success_message']]) : back()->with('form_sent_'.$f->id, true);
        }

        $files = [];
        foreach (FormSchema::inputs($fields) as $fd) {
            if ($fd['type'] === 'file' && $request->hasFile($fd['key'])) {
                $files[$fd['key']] = $request->file($fd['key']);
            }
        }
        [$data, $errors, $store] = FormSchema::validate($fields, $request->all(), $files, $lang);
        if ($errors) {
            return $fail($errors, FormSchema::msg($lang, 'errors'));
        }
        foreach ($store as $k => $file) {
            $name = $file->getClientOriginalName();
            $size = $file->getSize();
            $data[$k] = ['name' => mb_substr($name, 0, 190), 'path' => CompanyFiles::putUpload((int) $f->company_id, 'forms', $file, true), 'size' => $size];
        }

        $sub = DB::transaction(function () use ($f, $data, $request, $page) {
            $form = DB::table('forms')->where('id', $f->id)->lockForUpdate()->first();
            $number = (int) $form->next_number;
            DB::table('forms')->where('id', $f->id)->update(['next_number' => $number + 1]);
            $id = DB::table('form_submissions')->insertGetId([
                'company_id' => $f->company_id, 'form_id' => $f->id, 'number' => $number,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'is_read' => false,
                'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'page_url' => $page ?: null, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('form_submissions')->find($id);
        });

        FormStats::submitted($f, $request->input('_ms'));
        if ($s['notify']) {
            FormNotifier::submitted($f, $fields, $s, $sub);
        }
        if ($s['confirm_email']) {
            FormNotifier::confirmVisitor($f, $fields, $s, $sub);
        }

        $ref = $s['number_prefix'].$sub->number;
        $message = str_replace('{number}', $ref, $s['success_message']);
        $redirect = $s['confirmation'] === 'redirect' && $s['redirect_url'] ? $s['redirect_url'] : null;
        if ($json) {
            return response()->json(['ok' => true, 'message' => $message, 'number' => $ref, 'redirect' => $redirect]);
        }

        return $redirect ? redirect()->away($redirect) : back()->with('form_sent_'.$f->id, $message);
    }
}
