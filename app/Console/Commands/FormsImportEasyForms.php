<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Forms\FormsController;
use App\Support\FormSchema;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 * Forms module (2026-10-24): brings the DATA of the old Easy Forms install
 * (KT-FORMS, database dbform) into Murano's own Forms module -- the forms'
 * fields / settings and every submission. Only data: no Easy Forms code is used.
 *
 *   php artisan forms:import-easyforms            (dry run: what it would import)
 *   php artisan forms:import-easyforms --apply
 *   php artisan forms:import-easyforms --apply --map=6:2   (legacy form 6 -> company 2)
 *
 * Source: the DSN / user / password in EASYFORMS_DB_CONFIG (a Yii config/db.php,
 * default ../KT-FORMS.LOCAL/config/db.php next to this project), never copied
 * anywhere. Safe to run again: forms are matched by (easyforms, legacy id) and
 * submissions by their legacy id, so nothing is imported twice -- needed because a
 * developer PC's database is replaced by production's every morning.
 * Field keys are kept (text_1, selectlist_5...) so the old data lands as is.
 */
class FormsImportEasyForms extends Command
{
    protected $signature = 'forms:import-easyforms {--apply : Write (default: dry run)} {--map=* : legacyFormId:companyId}';

    protected $description = 'Import the forms and submissions of the old Easy Forms install into the Forms module';

    /** Legacy form id => the Murano company (by name) it belongs to. */
    private const COMPANIES = [
        1 => 'KcolTech',
        2 => 'Ruba Garden',
        5 => 'Turismo Cautivo',
        6 => 'Guimac',
        7 => 'NewEra',
    ];

    public function handle(): int
    {
        $src = $this->source();
        if (! $src) {
            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $map = [];
        foreach ((array) $this->option('map') as $m) {
            if (preg_match('/^(\d+):(\d+)$/', $m, $x)) {
                $map[(int) $x[1]] = (int) $x[2];
            }
        }
        $tz = config('app.timezone');
        $totals = ['forms' => 0, 'submissions' => 0];

        foreach ($src->table('form')->orderBy('id')->get() as $old) {
            $cid = $map[$old->id] ?? $this->company(self::COMPANIES[$old->id] ?? null);
            if (! $cid) {
                $this->warn("#{$old->id} {$old->name}: no Murano company for it -- skipped (use --map={$old->id}:COMPANY_ID).");

                continue;
            }
            $data = $src->table('form_data')->where('form_id', $old->id)->first();
            $builder = json_decode((string) ($data->builder ?? ''), true) ?: [];
            [$fields, $submitText] = $this->fields($builder['initForm'] ?? []);
            $conf = $src->table('form_confirmation')->where('form_id', $old->id)->first();
            $mail = $src->table('form_email')->where('form_id', $old->id)->first();
            $es = str_starts_with((string) $old->language, 'es');
            $settings = FormSchema::settings([
                'language' => $es ? 'es' : 'en',
                'submit_text' => $submitText ?: ($es ? 'Enviar' : 'Send'),
                'confirmation' => ((int) ($conf->type ?? 0)) === 2 && ! empty($conf->url) ? 'redirect' : 'message',
                'success_message' => trim(strip_tags((string) ($conf->message ?? ''))) ?: ($es ? '¡Gracias! Recibimos su mensaje.' : 'Thank you! We received your message.'),
                'redirect_url' => (string) ($conf->url ?? ''),
                'hide_after_submit' => true,
                'notify' => ! empty($mail->to),
                'notify_emails' => array_map('trim', explode(',', (string) ($mail->to ?? ''))),
                'notify_subject' => (string) ($mail->subject ?? '') ?: ($es ? 'Nuevo envío: {form}' : 'New submission: {form}'),
                'reply_to_field' => collect($fields)->firstWhere('type', 'email')['key'] ?? '',
                'honeypot' => (bool) $old->honeypot,
                'active' => (bool) $old->status,
            ]);

            $form = DB::table('forms')->where('legacy_source', 'easyforms')->where('legacy_id', $old->id)->whereNull('deleted_at')->first();
            $subs = $src->table('form_submission')->where('form_id', $old->id)->orderBy('id')->get();
            $have = $form ? DB::table('form_submissions')->where('form_id', $form->id)->whereNotNull('legacy_id')->pluck('legacy_id')->flip() : collect();
            $new = $subs->reject(fn ($s) => $have->has($s->id));
            $maxNumber = (int) $subs->max(fn ($s) => (int) $s->number);
            $company = DB::table('companies')->where('id', $cid)->value('name');
            $this->line(sprintf('#%d %s -> %s (%d): %s, %d field(s), %d submission(s) to import of %d',
                $old->id, $old->name, $company, $cid, $form ? 'form already imported' : 'new form', count($fields), $new->count(), $subs->count()));
            if (! $apply) {
                continue;
            }

            DB::transaction(function () use (&$form, $old, $cid, $fields, $settings, $new, $maxNumber, $tz, &$totals) {
                if (! $form) {
                    $id = DB::table('forms')->insertGetId([
                        'company_id' => $cid, 'name' => mb_substr($old->name, 0, 150), 'public_key' => FormsController::newKey(),
                        'status' => (bool) $old->status, 'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
                        'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                        'next_number' => max((int) $old->submission_number, $maxNumber + 1),
                        'legacy_source' => 'easyforms', 'legacy_id' => $old->id,
                        'created_at' => $old->created_at ? Carbon::createFromTimestamp($old->created_at, $tz) : now(), 'updated_at' => now(),
                    ]);
                    $form = DB::table('forms')->find($id);
                    $totals['forms']++;
                }
                $keys = array_column(FormSchema::inputs($fields), 'key');
                $multi = collect($fields)->filter(fn ($f) => $f['type'] === 'checkbox')->pluck('key')->flip();
                foreach ($new->chunk(200) as $chunk) {
                    $rows = [];
                    foreach ($chunk as $s) {
                        $raw = json_decode((string) $s->data, true) ?: [];
                        $out = [];
                        foreach ($raw as $k => $v) {
                            if (str_starts_with((string) $k, '_')) {
                                continue;   // Easy Forms' duplicate "_field" copies
                            }
                            if (is_array($v) && ! $multi->has($k)) {
                                $v = implode(', ', array_map('strval', array_filter($v, 'is_scalar')));
                            }
                            $out[$k] = $v;
                        }
                        // Fields in the order of the form first.
                        $ordered = array_merge(array_intersect_key(array_fill_keys($keys, null), $out), $out);
                        $sender = json_decode((string) $s->sender, true) ?: [];
                        $at = $s->created_at ? Carbon::createFromTimestamp($s->created_at, $tz) : now();
                        $rows[] = [
                            'company_id' => $cid, 'form_id' => $form->id, 'number' => (int) $s->number,
                            'data' => json_encode($ordered, JSON_UNESCAPED_UNICODE), 'is_read' => ! (int) $s->new,
                            'ip' => $s->ip ? mb_substr($s->ip, 0, 45) : null,
                            'user_agent' => isset($sender['user_agent']) ? mb_substr((string) $sender['user_agent'], 0, 255) : null,
                            'page_url' => isset($sender['url']) ? mb_substr((string) $sender['url'], 0, 500) : null,
                            'legacy_id' => $s->id, 'created_at' => $at,
                            'updated_at' => $s->updated_at ? Carbon::createFromTimestamp($s->updated_at, $tz) : $at,
                        ];
                    }
                    DB::table('form_submissions')->insert($rows);
                    $totals['submissions'] += count($rows);
                }
                $next = max((int) $form->next_number, $maxNumber + 1);
                DB::table('forms')->where('id', $form->id)->update(['next_number' => $next]);
            });
            $days = $this->importStats($src, (int) $old->id, $form, $tz);
            $this->line("    statistics: {$days} day(s)");
        }

        $this->info($apply
            ? "Imported: {$totals['forms']} form(s), {$totals['submissions']} submission(s)."
            : 'Dry run -- nothing written. Run with --apply.');

        return self::SUCCESS;
    }

    /**
     * The old install's statistics -> form_stats_daily (dashboard): per day, page
     * views (its "pv" events), unique users, starters (fills), conversions and
     * the total filling time (ms). Days before today only, written as they were
     * (re-running replaces them, never adds twice).
     */
    private function importStats($src, int $legacyId, object $form, string $tz): int
    {
        $today = now()->toDateString();
        $rows = [];
        foreach ($src->table('log_event')->where('event', 'pv')->where('app_id', (string) $legacyId)->pluck('collector_tstamp') as $ts) {
            $d = Carbon::createFromTimestamp((int) $ts, $tz)->toDateString();
            $rows[$d]['views'] = ($rows[$d]['views'] ?? 0) + 1;
        }
        foreach ($src->table('stats_performance')->where('app_id', (string) $legacyId)->get() as $p) {
            $d = (string) $p->day;
            $ms = (int) $p->conversionTime;
            $rows[$d]['users'] = (int) $p->users;
            $rows[$d]['starts'] = (int) $p->fills;
            $rows[$d]['submissions'] = (int) $p->conversions;
            $ok = $ms > 0 && (int) $p->conversions > 0 && $ms < \App\Support\FormStats::MAX_FILL_MS * max(1, (int) $p->conversions);
            $rows[$d]['fill_ms'] = $ok ? $ms : 0;
            $rows[$d]['timed'] = $ok ? (int) $p->conversions : 0;
        }
        $out = [];
        foreach ($rows as $d => $r) {
            if ($d >= $today) {
                continue;
            }
            $out[] = ['company_id' => $form->company_id, 'form_id' => $form->id, 'day' => $d,
                'views' => $r['views'] ?? 0, 'users' => $r['users'] ?? 0, 'starts' => $r['starts'] ?? 0,
                'submissions' => $r['submissions'] ?? 0, 'fill_ms' => $r['fill_ms'] ?? 0, 'timed' => $r['timed'] ?? 0];
        }
        foreach (array_chunk($out, 300) as $chunk) {
            DB::table('form_stats_daily')->upsert($chunk, ['form_id', 'day'], ['views', 'users', 'starts', 'submissions', 'fill_ms', 'timed']);
        }

        return count($out);
    }

    /** The old database, from its Yii config/db.php (credentials never printed or copied). */
    private function source()
    {
        $file = env('EASYFORMS_DB_CONFIG') ?: dirname(base_path()).'/KT-FORMS.LOCAL/config/db.php';
        if (! is_file($file)) {
            $this->error("Easy Forms config not found: {$file} (set EASYFORMS_DB_CONFIG).");

            return null;
        }
        $php = (string) file_get_contents($file);
        preg_match("/'dsn'\s*=>\s*'([^']*)'/", $php, $dsn);
        preg_match("/'username'\s*=>\s*'([^']*)'/", $php, $user);
        preg_match("/'password'\s*=>\s*'([^']*)'/", $php, $pass);
        parse_str(str_replace(';', '&', preg_replace('/^mysql:/', '', $dsn[1] ?? '')), $d);
        if (empty($d['dbname'])) {
            $this->error('Could not read the Easy Forms database from '.$file);

            return null;
        }
        $cfg = config('database.connections.mysql');
        $cfg = array_merge($cfg, ['host' => $d['host'] ?? '127.0.0.1', 'port' => $d['port'] ?? 3306, 'database' => $d['dbname'],
            'username' => $user[1] ?? '', 'password' => $pass[1] ?? '']);
        config(['database.connections.easyforms_import' => $cfg]);
        $c = DB::connection('easyforms_import');
        $this->line("Source: {$d['dbname']} on ".($d['host'] ?? '127.0.0.1'));

        return $c;
    }

    private function company(?string $name): ?int
    {
        if (! $name) {
            return null;
        }
        $id = DB::table('companies')->whereNull('deleted_at')->where('name', 'like', $name.'%')->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Easy Forms builder components -> Murano fields (keys kept).
     *
     * @return array{0: array, 1: ?string}  [fields, submit button text]
     */
    private function fields(array $components): array
    {
        $fields = [];
        $submit = null;
        foreach ($components as $c) {
            $v = fn ($k) => $c['fields'][$k]['value'] ?? null;
            $id = (string) ($v('id') ?? '');
            $width = match ((string) $v('containerClass')) {
                'col-6', 'col-md-6', 'col-sm-6' => 'half',
                'col-4', 'col-md-4', 'col-sm-4' => 'third',
                default => 'full',
            };
            $selected = function ($opt) {
                foreach ((array) $opt as $o) {
                    if (is_array($o) && ! empty($o['selected'])) {
                        return (string) ($o['value'] ?? '');
                    }
                }

                return null;
            };
            $base = ['key' => $id, 'label' => trim((string) $v('label')), 'width' => $width,
                'required' => (bool) $v('required'), 'placeholder' => (string) $v('placeholder'), 'help' => (string) $v('helpText')];
            switch ($c['name']) {
                case 'heading':
                    $fields[] = ['key' => $id, 'type' => 'heading', 'text' => (string) $v('text'), 'level' => (int) ltrim((string) $selected($v('type')) ?: 'h3', 'h'), 'width' => 'full'];
                    break;
                case 'paragraph':
                    $fields[] = ['key' => $id, 'type' => 'paragraph', 'text' => trim(strip_tags((string) $v('text'))), 'width' => 'full'];
                    break;
                case 'spacer':
                    break;   // spacing is part of Murano's layout
                case 'text':
                    $t = $selected($v('inputType')) ?: 'text';
                    $fields[] = $base + ['type' => ['tel' => 'phone', 'url' => 'url', 'email' => 'email'][$t] ?? 'text'];
                    break;
                case 'email':
                case 'textarea':
                case 'date':
                    $fields[] = $base + ['type' => $c['name'], 'rows' => max(2, (int) $v('fieldSize') + 1)];
                    break;
                case 'number':
                    $fields[] = $base + ['type' => 'number', 'min' => $v('minNumber'), 'max' => $v('maxNumber')];
                    break;
                case 'selectlist':
                case 'radio':
                case 'checkbox':
                    $opts = [];
                    $default = '';
                    $raw = $v('options');
                    $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
                    foreach ($list as $o) {
                        if (! is_scalar($o)) {
                            continue;
                        }
                        $o = (string) $o;
                        if (str_ends_with($o, '|selected')) {
                            $o = substr($o, 0, -9);
                            $default = $o;
                        }
                        $opts[] = $o;
                    }
                    $type = ['selectlist' => 'select', 'radio' => 'radio', 'checkbox' => 'checkbox'][$c['name']];
                    $fields[] = $base + ['type' => $type, 'options' => $opts, 'default' => $type === 'checkbox' ? '' : $default,
                        'multiple' => $c['name'] === 'selectlist' && (bool) $v('multiple')];
                    break;
                case 'file':
                    $fields[] = $base + ['type' => 'file'];
                    break;
                case 'hidden':
                    $fields[] = $base + ['type' => 'hidden', 'default' => (string) $v('value')];
                    break;
                case 'button':
                    $submit = trim((string) $v('buttonText')) ?: $submit;
                    break;
            }
        }

        return [FormSchema::fields($fields), $submit];
    }
}
