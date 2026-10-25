<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Forms module (2026-10-24): the field schema of a form, its settings and the
 * server-side validation of a submission. Murano's own design (no third-party
 * form-builder code).
 *
 * A field: {key, type, label, required, placeholder, help, options[], width,
 *           min, max, rows, accept, max_mb, multiple, default, text, level}
 *  - input types: text, email, phone, url, number, date, time, textarea,
 *    select, radio, checkbox, file, hidden
 *  - layout types (no value): heading (text, level), paragraph (text), divider
 *  - width: full | half | third
 * Submission data = {field key: value}; checkbox / multi-select = array;
 * file = {name, path, size}.
 */
class FormSchema
{
    public const INPUT_TYPES = ['text', 'email', 'phone', 'url', 'number', 'date', 'time', 'textarea', 'select', 'radio', 'checkbox', 'file', 'hidden'];

    public const LAYOUT_TYPES = ['heading', 'paragraph', 'divider'];

    public const WIDTHS = ['full', 'half', 'third'];

    /** Settings a new form starts with (merged under the saved ones). */
    public static function defaultSettings(): array
    {
        return [
            'title' => '',                       // shown above the form ('' = none)
            'description' => '',
            'submit_text' => 'Send',
            'language' => 'en',                  // en | es: built-in messages (required, invalid...)
            'confirmation' => 'message',         // message | redirect
            'success_message' => 'Thank you! We received your message.',
            'redirect_url' => '',
            'hide_after_submit' => true,
            'notify' => true,                    // email the company on every submission
            'notify_emails' => [],               // [] = the company's admins
            'notify_subject' => 'New submission: {form}',
            'reply_to_field' => '',              // an email field: replying to the notification answers the visitor
            // Receipt to the visitor (2026-10-24; e.g. the complaints book's copy for the consumer).
            'confirm_email' => false,
            'confirm_field' => '',               // the email field to send it to
            'confirm_subject' => '',             // '' = the form's name; {form} {number}
            'confirm_message' => '',             // text above the details
            'confirm_data' => true,              // include what they sent
            'confirm_bcc' => [],                 // hidden copy to staff
            'from_name' => '',                   // sender name ('' = the company)
            'number_prefix' => '',               // e.g. REC- -> REC-2001
            'honeypot' => true,
            'min_seconds' => 3,                  // faster than this = a bot
            'allowed_domains' => [],             // [] = any site may embed it
            'active' => true,
            'primary_color' => '#1677ff',
            'background' => '#ffffff',
            'radius' => 8,
            'font' => 'system',                  // system | serif
        ];
    }

    public static function settings(?array $saved): array
    {
        $s = array_merge(self::defaultSettings(), array_intersect_key($saved ?? [], self::defaultSettings()));
        foreach (['notify_emails', 'confirm_bcc'] as $k) {
            $s[$k] = array_values(array_unique(array_filter(array_map('trim', (array) $s[$k]), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
        }
        $s['allowed_domains'] = array_values(array_filter(array_map(fn ($d) => self::domain((string) $d), (array) $s['allowed_domains'])));
        $s['language'] = in_array($s['language'], ['en', 'es'], true) ? $s['language'] : 'en';
        $s['confirmation'] = in_array($s['confirmation'], ['message', 'redirect'], true) ? $s['confirmation'] : 'message';
        $s['redirect_url'] = preg_match('~^https?://~i', (string) $s['redirect_url']) ? mb_substr((string) $s['redirect_url'], 0, 500) : '';
        $s['min_seconds'] = max(0, min(60, (int) $s['min_seconds']));
        $s['radius'] = max(0, min(24, (int) $s['radius']));
        foreach (['primary_color', 'background'] as $c) {
            $s[$c] = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $s[$c]) ? $s[$c] : self::defaultSettings()[$c];
        }
        $s['font'] = in_array($s['font'], ['system', 'serif'], true) ? $s['font'] : 'system';
        foreach (['title', 'submit_text', 'notify_subject', 'number_prefix', 'reply_to_field', 'confirm_field', 'confirm_subject', 'from_name'] as $k) {
            $s[$k] = mb_substr(strip_tags((string) $s[$k]), 0, 190);
        }
        foreach (['description', 'success_message', 'confirm_message'] as $k) {
            $s[$k] = mb_substr(strip_tags((string) $s[$k]), 0, 5000);
        }
        foreach (['hide_after_submit', 'notify', 'honeypot', 'active', 'confirm_email', 'confirm_data'] as $k) {
            $s[$k] = (bool) $s[$k];
        }

        return $s;
    }

    /** "https://www.Example.com/x" -> "example.com" (www. dropped), '' when not a domain. */
    public static function domain(string $d): string
    {
        $d = strtolower(trim($d));
        $d = preg_replace('~^[a-z]+://~', '', $d);
        $d = preg_replace('~[/:?#].*$~', '', $d);
        $d = preg_replace('~^www\.~', '', $d);

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d) || $d === 'localhost' ? $d : '';
    }

    /** Cleans a field list coming from the builder (unknown types dropped, unique keys). */
    public static function fields(array $fields): array
    {
        $out = [];
        $keys = [];
        foreach ($fields as $i => $f) {
            if (! is_array($f)) {
                continue;
            }
            $type = (string) ($f['type'] ?? '');
            if (! in_array($type, array_merge(self::INPUT_TYPES, self::LAYOUT_TYPES), true)) {
                continue;
            }
            $key = strtolower(preg_replace('/[^a-z0-9_]/i', '_', (string) ($f['key'] ?? '')));
            $key = trim($key, '_');
            if ($key === '' || ! preg_match('/^[a-z]/', $key)) {
                $key = $type.'_'.($i + 1);
            }
            $base = mb_substr($key, 0, 40);
            for ($n = 2, $key = $base; isset($keys[$key]); $n++) {
                $key = $base.'_'.$n;
            }
            $keys[$key] = true;
            $width = (string) ($f['width'] ?? 'full');

            $field = [
                'key' => $key,
                'type' => $type,
                'label' => mb_substr(strip_tags((string) ($f['label'] ?? '')), 0, 255),
                'width' => in_array($width, self::WIDTHS, true) ? $width : 'full',
            ];
            if (in_array($type, self::LAYOUT_TYPES, true)) {
                $field['text'] = mb_substr(strip_tags((string) ($f['text'] ?? '')), 0, 5000);
                if ($type === 'heading') {
                    $field['level'] = in_array((int) ($f['level'] ?? 3), [2, 3, 4], true) ? (int) $f['level'] : 3;
                }
                $out[] = $field;

                continue;
            }
            $field['required'] = (bool) ($f['required'] ?? false);
            $field['placeholder'] = mb_substr(strip_tags((string) ($f['placeholder'] ?? '')), 0, 190);
            $field['help'] = mb_substr(strip_tags((string) ($f['help'] ?? '')), 0, 500);
            $field['default'] = is_scalar($f['default'] ?? null) ? mb_substr((string) $f['default'], 0, 500) : '';
            if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                $opts = array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim(strip_tags((string) $o)), 0, 190), (array) ($f['options'] ?? [])), fn ($o) => $o !== '')));
                $field['options'] = array_slice($opts, 0, 300);
                if ($type === 'select') {
                    $field['multiple'] = (bool) ($f['multiple'] ?? false);
                }
            }
            if ($type === 'number') {
                $field['min'] = is_numeric($f['min'] ?? null) ? (float) $f['min'] : null;
                $field['max'] = is_numeric($f['max'] ?? null) ? (float) $f['max'] : null;
            }
            if (in_array($type, ['text', 'textarea'], true)) {
                $field['max'] = max(1, min(20000, (int) ($f['max'] ?? ($type === 'text' ? 255 : 5000))));
            }
            if ($type === 'textarea') {
                $field['rows'] = max(2, min(20, (int) ($f['rows'] ?? 4)));
            }
            if ($type === 'file') {
                $field['accept'] = mb_substr(preg_replace('/[^a-z0-9.,]/i', '', (string) ($f['accept'] ?? '.pdf,.jpg,.jpeg,.png')), 0, 100);
                $field['max_mb'] = max(1, min(20, (int) ($f['max_mb'] ?? 5)));
            }
            $out[] = $field;
        }

        return array_slice($out, 0, 200);
    }

    /** Fields that carry a value (no headings / paragraphs / dividers). */
    public static function inputs(array $fields): array
    {
        return array_values(array_filter($fields, fn ($f) => in_array($f['type'] ?? '', self::INPUT_TYPES, true)));
    }

    /** Built-in messages in the form's language. */
    public static function msg(string $lang, string $key, string $label = ''): string
    {
        $m = [
            'en' => [
                'required' => '%s is required.', 'email' => '%s must be a valid email.', 'url' => '%s must be a valid web address (https://...).',
                'number' => '%s must be a number.', 'min' => '%s is too small.', 'max' => '%s is too large.', 'date' => '%s must be a valid date.',
                'time' => '%s must be a valid time.', 'option' => 'Choose a valid option for %s.', 'long' => '%s is too long.',
                'file' => '%s: the file type is not allowed.', 'file_big' => '%s: the file is too large.', 'closed' => 'This form is not accepting submissions.',
                'spam' => 'Your message could not be sent. Please try again.', 'errors' => 'Please check the highlighted fields.',
            ],
            'es' => [
                'required' => '%s es obligatorio.', 'email' => '%s debe ser un correo válido.', 'url' => '%s debe ser una dirección web válida (https://...).',
                'number' => '%s debe ser un número.', 'min' => '%s es demasiado pequeño.', 'max' => '%s es demasiado grande.', 'date' => '%s debe ser una fecha válida.',
                'time' => '%s debe ser una hora válida.', 'option' => 'Elija una opción válida en %s.', 'long' => '%s es demasiado largo.',
                'file' => '%s: ese tipo de archivo no está permitido.', 'file_big' => '%s: el archivo es demasiado grande.', 'closed' => 'Este formulario no está recibiendo envíos.',
                'spam' => 'No se pudo enviar su mensaje. Intente de nuevo.', 'errors' => 'Revise los campos marcados.',
            ],
        ];
        $label = trim(rtrim($label, ':')) ?: ($lang === 'es' ? 'Este campo' : 'This field');

        return sprintf($m[$lang][$key] ?? $m['en'][$key] ?? $key, $label);
    }

    /**
     * Validates a submission against the fields.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, UploadedFile>  $files
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, UploadedFile>}  [data, errors, files to store]
     */
    public static function validate(array $fields, array $input, array $files, string $lang): array
    {
        $data = [];
        $errors = [];
        $store = [];
        foreach (self::inputs($fields) as $f) {
            $k = $f['key'];
            $label = $f['label'] ?: $k;
            $type = $f['type'];

            if ($type === 'file') {
                $file = $files[$k] ?? null;
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    if ($f['required']) {
                        $errors[$k] = self::msg($lang, 'required', $label);
                    }

                    continue;
                }
                $ext = strtolower($file->getClientOriginalExtension());
                $allowed = array_filter(array_map(fn ($a) => ltrim(strtolower(trim($a)), '.'), explode(',', (string) ($f['accept'] ?? ''))));
                $blocked = ['php', 'phtml', 'phar', 'exe', 'js', 'html', 'htm', 'svg', 'sh', 'bat', 'cmd'];
                if (in_array($ext, $blocked, true) || ($allowed && ! in_array($ext, $allowed, true))) {
                    $errors[$k] = self::msg($lang, 'file', $label);
                } elseif ($file->getSize() > ($f['max_mb'] ?? 5) * 1048576) {
                    $errors[$k] = self::msg($lang, 'file_big', $label);
                } else {
                    $store[$k] = $file;
                }

                continue;
            }

            $v = $input[$k] ?? null;
            if ($type === 'checkbox' || ($type === 'select' && ! empty($f['multiple']))) {
                $v = array_values(array_filter(array_map(fn ($x) => trim((string) $x), (array) ($v ?? [])), fn ($x) => $x !== ''));
                if (! $v) {
                    if ($f['required']) {
                        $errors[$k] = self::msg($lang, 'required', $label);
                    }
                    $data[$k] = [];

                    continue;
                }
                if (array_diff($v, $f['options'] ?? [])) {
                    $errors[$k] = self::msg($lang, 'option', $label);
                }
                $data[$k] = $v;

                continue;
            }

            $v = is_array($v) ? '' : trim((string) $v);
            if ($v === '') {
                if ($f['required']) {
                    $errors[$k] = self::msg($lang, 'required', $label);
                }
                $data[$k] = '';

                continue;
            }
            switch ($type) {
                case 'email':
                    if (! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                        $errors[$k] = self::msg($lang, 'email', $label);
                    }
                    break;
                case 'url':
                    if (! preg_match('~^https?://[^\s]+$~i', $v)) {
                        $errors[$k] = self::msg($lang, 'url', $label);
                    }
                    break;
                case 'number':
                    if (! is_numeric($v)) {
                        $errors[$k] = self::msg($lang, 'number', $label);
                    } elseif (isset($f['min']) && $f['min'] !== null && (float) $v < $f['min']) {
                        $errors[$k] = self::msg($lang, 'min', $label);
                    } elseif (isset($f['max']) && $f['max'] !== null && (float) $v > $f['max']) {
                        $errors[$k] = self::msg($lang, 'max', $label);
                    }
                    break;
                case 'date':
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! strtotime($v)) {
                        $errors[$k] = self::msg($lang, 'date', $label);
                    }
                    break;
                case 'time':
                    if (! preg_match('/^\d{2}:\d{2}$/', $v)) {
                        $errors[$k] = self::msg($lang, 'time', $label);
                    }
                    break;
                case 'select':
                case 'radio':
                    if (! in_array($v, $f['options'] ?? [], true)) {
                        $errors[$k] = self::msg($lang, 'option', $label);
                    }
                    break;
                case 'phone':
                    $v = mb_substr(preg_replace('/[^0-9+()\-\s.]/', '', $v), 0, 40);
                    break;
            }
            $max = $f['max'] ?? null;
            if (in_array($type, ['text', 'textarea'], true) && $max && mb_strlen($v) > $max) {
                $errors[$k] = self::msg($lang, 'long', $label);
            }
            $data[$k] = mb_substr(strip_tags($v), 0, 20000);
        }

        return [$data, $errors, $store];
    }

    /** One value as text (lists, exports, emails). */
    public static function text($v): string
    {
        if (is_array($v)) {
            if (isset($v['name']) && isset($v['path'])) {
                return (string) $v['name'];
            }

            return implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : '', $v));
        }

        return (string) ($v ?? '');
    }
}
