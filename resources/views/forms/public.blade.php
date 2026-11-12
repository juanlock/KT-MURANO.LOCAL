{{-- Forms module: a public form (own page, or bare inside an iframe). PublicFormController. --}}
@php
    $es = $s['language'] === 'es';
    $err = $errors_bag ?? [];
    $val = fn ($k, $d = '') => $old[$k] ?? $d;
    $req = $es ? 'Obligatorio' : 'Required';
    $showHeader = ($brand['logo'] || $brand['name']) && (! $embed || $s['header_in_embed']);
    $band = $showHeader && $s['header_background'] !== '';
    $center = $s['align'] === 'center';
@endphp
<!DOCTYPE html>
<html lang="{{ $s['language'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
@if (! empty($go))<meta http-equiv="refresh" content="{{ (int) $go[1] }};url={{ $go[0] }}">@endif
<title>{{ $s['title'] ?: $form->name }}{{ $embed || $brand['name'] === '' ? '' : ' - '.$brand['name'] }}</title>
<style>
:root {
    --fm-primary: {{ $s['primary_color'] }};
    --fm-bg: {{ $s['background'] }};
    --fm-radius: {{ $s['radius'] }}px;
    --fm-text: {{ $s['text_color'] }};
    /* From the text color, so a dark form background keeps its descriptions readable. */
    --fm-muted: color-mix(in srgb, var(--fm-text) 72%, transparent);
    --fm-border: #d0d7de;
    --fm-line: color-mix(in srgb, var(--fm-text) 25%, transparent);
    --fm-error: #d93025;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: {!! $s['font'] === 'serif' ? 'Georgia, "Times New Roman", serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif' !!};
    color: var(--fm-text); background: {{ $embed ? 'transparent' : $s['page_background'] }}; font-size: 15px; line-height: 1.45;
}
.fm-page { max-width: {{ $s['width'] ? $s['width'].'px' : 'none' }}; margin: 0 auto; padding: {{ $embed ? '4px' : '32px 16px' }}; }
.fm-card { background: var(--fm-bg); border-radius: var(--fm-radius); padding: {{ $embed ? '12px 4px' : '28px' }}; {{ $embed ? '' : 'box-shadow: 0 2px 14px rgba(0,0,0,.07);' }} overflow: hidden; }
.fm-brand { display: flex; align-items: center; justify-content: {{ $center ? 'center' : 'flex-start' }}; gap: 12px; margin-bottom: 18px; }
.fm-brand img { max-height: {{ $s['logo_height'] }}px; max-width: 100%; object-fit: contain; }
.fm-brand span { font-weight: 600; color: var(--fm-muted); }
/* A band behind the logo, edge to edge of the card (cancels the card's padding). */
.fm-brand.band { background: {{ $s['header_background'] ?: 'transparent' }}; margin: {{ $embed ? '-12px -4px 18px' : '-28px -28px 22px' }}; padding: 12px {{ $embed ? '12px' : '28px' }}; }
.fm-brand.band span { color: #fff; }
.fm-title { margin: 0 0 6px; font-size: 1.5em; {{ $center ? 'text-align: center;' : '' }} }
.fm-desc { margin: 0 0 18px; color: var(--fm-muted); white-space: pre-line; {{ $center ? 'text-align: center;' : '' }} }
.fm-grid { display: flex; flex-wrap: wrap; margin: 0 -8px; }
.fm-col { padding: 0 8px; margin-bottom: 14px; width: 100%; }
.fm-col.half { width: 50%; }
.fm-col.third { width: 33.333%; }
@media (max-width: 560px) { .fm-col.half, .fm-col.third { width: 100%; } }
.fm-label { display: block; font-weight: 600; margin-bottom: 5px; }
.fm-label .fm-star { color: var(--fm-error); margin-left: 2px; }
.fm-input, .fm-select, .fm-textarea {
    width: 100%; padding: 9px 11px; border: 1px solid var(--fm-border); border-radius: calc(var(--fm-radius) * .75);
    font: inherit; color: #1f2328; background: #fff;   /* the boxes stay white: never the form's text color */
}
.fm-input::placeholder, .fm-textarea::placeholder { color: #8b949e; }
.fm-input:focus, .fm-select:focus, .fm-textarea:focus { outline: 2px solid color-mix(in srgb, var(--fm-primary) 35%, transparent); border-color: var(--fm-primary); }
.fm-textarea { resize: vertical; }
.fm-choice { display: flex; align-items: flex-start; gap: 8px; margin: 4px 0; font-weight: normal; }
.fm-choice input { margin-top: 3px; accent-color: var(--fm-primary); }
.fm-help { color: var(--fm-muted); font-size: .88em; margin-top: 4px; }
.fm-error { color: var(--fm-error); font-size: .88em; margin-top: 4px; }
.fm-col.has-error .fm-input, .fm-col.has-error .fm-select, .fm-col.has-error .fm-textarea { border-color: var(--fm-error); background: #fdecea; }
.fm-heading { margin: 10px 0 2px; {{ $center ? 'text-align: center;' : '' }} }
.fm-paragraph { margin: 0; color: var(--fm-muted); white-space: pre-line; {{ $center ? 'text-align: center;' : '' }} }
.fm-divider { border: 0; border-top: 1px solid var(--fm-line); margin: 6px 0; }
.fm-btn {
    background: var(--fm-primary); color: #fff; border: 0; border-radius: var(--fm-radius); padding: 11px 26px;
    font: inherit; font-weight: 600; cursor: pointer;
}
.fm-btn[disabled] { opacity: .6; cursor: default; }
.fm-alert { padding: 12px 14px; border-radius: var(--fm-radius); margin-bottom: 16px; white-space: pre-line; }
.fm-alert.ok { background: #e8f5e9; color: #1b5e20; }
.fm-alert.bad { background: #fdecea; color: #8a1c13; }
.fm-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
/* Tabs (2026-11-11) and the progress buttons. */
.fm-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 2px solid var(--fm-line); margin: 4px 0 16px; }
.fm-tab { background: none; border: 0; border-bottom: 3px solid transparent; margin-bottom: -2px; padding: 9px 14px; font: inherit; font-weight: 600; color: var(--fm-muted); cursor: pointer; }
.fm-tab.on { color: var(--fm-primary); border-bottom-color: var(--fm-primary); }
.fm-tab .fm-dot { display: inline-block; min-width: 18px; padding: 0 5px; margin-left: 6px; border-radius: 9px; font-size: .78em; background: color-mix(in srgb, var(--fm-primary) 15%, transparent); }
.fm-tab.err { color: var(--fm-error); }
.fm-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.fm-btn.light { background: #fff; color: var(--fm-primary); border: 1px solid var(--fm-primary); }
</style>
</head>
<body>
<div class="fm-page">
<div class="fm-card">
    @if ($showHeader)
        <div class="fm-brand {{ $band ? 'band' : '' }}">
            @if ($brand['logo'])<img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">@endif
            @if (! $brand['logo'])<span>{{ $brand['name'] }}</span>@endif
        </div>
    @endif
    @if ($s['title'])<h1 class="fm-title">{{ $s['title'] }}</h1>@endif
    @if ($s['description'])<p class="fm-desc">{{ $s['description'] }}</p>@endif

    <div id="fm-msg" class="fm-alert ok" @if (! $sent) hidden @endif>{{ is_string($sent) ? $sent : $s['success_message'] }}</div>
    <div id="fm-err" class="fm-alert bad" @if (empty($err['_']) && $open) hidden @endif>{{ $open ? ($err['_'] ?? '') : $closedMsg }}</div>

    @if ($open)
    <form id="fm-form" method="post" action="{{ $action }}" enctype="multipart/form-data" novalidate
          data-hide="{{ $s['hide_after_submit'] ? 1 : 0 }}" data-draft="{{ $s['save_draft'] ? 1 : 0 }}" @if ($sent && $s['hide_after_submit']) hidden @endif>
        @if ($s['save_draft'])
            {{-- 2026-11-11: answer some now and the rest later, on the same device --}}
            <p class="fm-draft-note" style="font-size: 13px; opacity: .75; margin: 0 0 12px; {{ $center ? 'text-align: center;' : '' }}">
                {{ $es ? 'Responda las que pueda y pulse «Guardar» para dejar su avance; puede volver cuando quiera a completarlo. «'.($s['submit_text'] ?: 'Enviar').'» nos envía sus respuestas, y puede volver a enviarlas si agrega más.'
                       : 'Answer what you can and press "Save" to keep your progress; come back any time to finish. "'.($s['submit_text'] ?: 'Send').'" sends us your answers, and you can send again if you add more.' }}
                <span id="fm-draft-saved" hidden> · {{ $es ? 'Guardado' : 'Saved' }}</span>
            </p>
        @endif
        <input type="hidden" name="_t" value="{{ $stamp }}">
        <input type="hidden" name="_page" id="fm-page" value="">
        @if ($s['honeypot'])
            <div class="fm-hp" aria-hidden="true"><label>Website <input type="text" name="_hp" tabindex="-1" autocomplete="off"></label></div>
        @endif
        @php $btnBar = function () use ($s, $es, $center) { return '<div class="fm-actions" style="'.($center ? 'justify-content: center;' : '').'">'.($s['save_draft'] ? '<button type="button" class="fm-btn light" id="fm-save">'.e($es ? 'Guardar' : 'Save').'</button>' : '').'<button type="submit" class="fm-btn">'.e($s['submit_text'] ?: ($es ? 'Enviar' : 'Send')).'</button></div>'; }; @endphp
        @if ($s['button_position'] === 'top')
            <div style="margin-bottom: 14px">{!! $btnBar() !!}</div>
        @endif
        @php $tabNames = collect($fields)->where('type', 'tab')->values(); $ti = 0; @endphp
        <div class="fm-grid">
        @foreach ($fields as $f)
            @php $k = $f['key']; $id = 'fm_'.$k; $w = $f['width'] ?? 'full'; @endphp
            {{-- A tab (2026-11-11): the fields after it, up to the next tab, are its panel; the tab bar comes with the first one. --}}
            @if ($f['type'] === 'tab')
                </div>
                @if ($ti > 0)</div>@endif
                @if ($ti === 0)
                    <div class="fm-tabs" role="tablist">
                        @foreach ($tabNames as $n => $tab)
                            <button type="button" class="fm-tab {{ $n === 0 ? 'on' : '' }}" data-tab="{{ $n }}" role="tab">{{ $tab['text'] ?: $tab['label'] }}<span class="fm-dot" data-count="{{ $n }}" hidden></span></button>
                        @endforeach
                    </div>
                @endif
                <div class="fm-panel" data-panel="{{ $ti }}" @if ($ti > 0) hidden @endif><div class="fm-grid">
                @php $ti++; @endphp
                @continue
            @endif
            @if ($f['type'] === 'hidden')
                <input type="hidden" name="{{ $k }}" value="{{ $val($k, $f['default'] ?? '') }}">
                @continue
            @endif
            <div class="fm-col {{ $w }} {{ isset($err[$k]) ? 'has-error' : '' }}" data-field="{{ $k }}">
            @switch($f['type'])
                @case('heading')
                    <h{{ $f['level'] ?? 3 }} class="fm-heading">{{ $f['text'] ?: $f['label'] }}</h{{ $f['level'] ?? 3 }}>
                    @break
                @case('paragraph')
                    <p class="fm-paragraph">{{ $f['text'] }}</p>
                    @break
                @case('divider')
                    <hr class="fm-divider">
                    @break
                @default
                    {{-- No label (placeholder-only fields): no empty line and no *; a required one
                         shows its error by the field's background. --}}
                    @if (trim((string) $f['label']) === '')
                    @elseif (! in_array($f['type'], ['checkbox', 'radio'], true))
                        <label class="fm-label" for="{{ $id }}">{{ $f['label'] }}@if ($f['required'])<span class="fm-star" title="{{ $req }}">*</span>@endif</label>
                    @else
                        <span class="fm-label">{{ $f['label'] }}@if ($f['required'])<span class="fm-star" title="{{ $req }}">*</span>@endif</span>
                    @endif
                    @switch($f['type'])
                        @case('textarea')
                            <textarea class="fm-textarea" id="{{ $id }}" name="{{ $k }}" rows="{{ $f['rows'] ?? 4 }}" maxlength="{{ $f['max'] ?? 5000 }}" placeholder="{{ $f['placeholder'] }}" @if ($f['required']) required @endif>{{ $val($k, $f['default'] ?? '') }}</textarea>
                            @break
                        @case('select')
                            @php $sel = (array) $val($k, $f['default'] ?? ''); @endphp
                            <select class="fm-select" id="{{ $id }}" name="{{ $k }}{{ ! empty($f['multiple']) ? '[]' : '' }}" @if (! empty($f['multiple'])) multiple size="{{ min(6, max(3, count($f['options'] ?? []))) }}" @endif @if ($f['required']) required @endif>
                                @if (empty($f['multiple']))<option value="">{{ $f['placeholder'] ?: ($es ? 'Seleccione…' : 'Choose…') }}</option>@endif
                                @foreach ($f['options'] ?? [] as $o)
                                    <option value="{{ $o }}" @selected(in_array($o, $sel, true))>{{ $o }}</option>
                                @endforeach
                            </select>
                            @break
                        @case('radio')
                            @foreach ($f['options'] ?? [] as $i => $o)
                                <label class="fm-choice"><input type="radio" name="{{ $k }}" value="{{ $o }}" @checked($val($k, $f['default'] ?? '') === $o) @if ($f['required'] && $i === 0) required @endif> <span>{{ $o }}</span></label>
                            @endforeach
                            @break
                        @case('checkbox')
                            @php $sel = (array) $val($k, []); @endphp
                            @foreach ($f['options'] ?? [] as $o)
                                <label class="fm-choice"><input type="checkbox" name="{{ $k }}[]" value="{{ $o }}" @checked(in_array($o, $sel, true))> <span>{{ $o }}</span></label>
                            @endforeach
                            @break
                        @case('file')
                            <input class="fm-input" type="file" id="{{ $id }}" name="{{ $k }}" accept="{{ $f['accept'] ?? '' }}" @if ($f['required']) required @endif>
                            @break
                        @default
                            @php $t = ['phone' => 'tel', 'text' => 'text', 'email' => 'email', 'url' => 'url', 'number' => 'number', 'date' => 'date', 'time' => 'time'][$f['type']] ?? 'text'; @endphp
                            <input class="fm-input" type="{{ $t }}" id="{{ $id }}" name="{{ $k }}" value="{{ $val($k, $f['default'] ?? '') }}" placeholder="{{ $f['placeholder'] }}"
                                   @if ($f['type'] === 'text') maxlength="{{ $f['max'] ?? 255 }}" @endif
                                   @if ($f['type'] === 'number' && isset($f['min']) && $f['min'] !== null) min="{{ $f['min'] }}" @endif
                                   @if ($f['type'] === 'number' && isset($f['max']) && $f['max'] !== null) max="{{ $f['max'] }}" @endif
                                   @if ($f['type'] === 'number') step="any" @endif
                                   @if ($f['type'] === 'email') autocomplete="email" @endif
                                   @if ($f['required']) required @endif>
                    @endswitch
                    @if ($f['help'])<div class="fm-help">{{ $f['help'] }}</div>@endif
                    <div class="fm-error" @if (! isset($err[$k])) hidden @endif>{{ $err[$k] ?? '' }}</div>
            @endswitch
            </div>
        @endforeach
        </div>
        @if ($ti > 0)</div>@endif
        @if ($s['button_position'] !== 'top')
            {!! $btnBar() !!}
        @endif
    </form>
    @endif
</div>
</div>
<script>
(function () {
    var form = document.getElementById('fm-form');
    var params = new URLSearchParams(location.search);
    var parentPage = params.get('page') || document.referrer || '';
    var key = @json($form->public_key);
    if (form) document.getElementById('fm-page').value = parentPage;

    // Height for the iframe on the other site (murano-forms.js listens).
    function height() {
        if (window.parent === window) return;
        var h = Math.ceil(document.documentElement.scrollHeight);
        window.parent.postMessage({ type: 'murano-form', key: key, height: h }, '*');
    }
    window.addEventListener('load', height);
    if (window.ResizeObserver) new ResizeObserver(height).observe(document.body); else setInterval(height, 500);

    // To another address: on its own page directly; in a frame, the website's page --
    // murano-forms.js moves it (postMessage), a bare <iframe> by moving the top window.
    function go(url) {
        if (window.parent === window) { location.href = url; return; }
        window.parent.postMessage({ type: 'murano-form', key: key, redirect: url }, '*');
        setTimeout(function () { try { window.top.location.href = url; } catch (e) {} }, 300);
    }

    if (!form || !window.fetch || !window.FormData) return;
    var msg = document.getElementById('fm-msg'), err = document.getElementById('fm-err');
    // Dashboard funnel: "started" once, when the visitor begins typing / choosing.
    var started = params.has('preview');
    var startedAt = 0;
    function start() {
        if (!startedAt) startedAt = Date.now();
        if (started) return;
        started = true;
        fetch(form.action + '/start', { method: 'POST', keepalive: true, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(function () {});
    }
    form.addEventListener('input', start);
    form.addEventListener('change', start);

    // Draft (2026-11-11): text, choices and checkboxes kept in this browser while typing, back on the next visit,
    // removed once the form is sent. Files and hidden fields are never kept.
    var draftKey = 'murano-form-draft:' + key;
    var useDraft = form.dataset.draft === '1' && !params.has('preview');
    function store() { try { return window.localStorage; } catch (e) { return null; } }
    function draftFields() {
        return Array.prototype.filter.call(form.elements, function (el) {
            return el.name && el.name.charAt(0) !== '_' && ['file', 'hidden', 'submit', 'button', 'password'].indexOf(el.type) === -1;
        });
    }
    var savedTag = document.getElementById('fm-draft-saved'), saveTimer = null;
    function saveDraft() {
        var ls = store(); if (!ls) return;
        var data = {};
        draftFields().forEach(function (el) {
            if (el.type === 'checkbox' || el.type === 'radio') { if (el.checked) (data[el.name] = data[el.name] || []).push(el.value); }
            else if (el.multiple) { data[el.name] = Array.prototype.filter.call(el.options, function (o) { return o.selected; }).map(function (o) { return o.value; }); }
            else data[el.name] = el.value;
        });
        try { ls.setItem(draftKey, JSON.stringify(data)); if (savedTag) savedTag.hidden = false; } catch (e) {}
    }
    if (useDraft) {
        var ls0 = store(), raw = null;
        try { raw = ls0 && ls0.getItem(draftKey); } catch (e) {}
        if (raw) {
            try {
                var data = JSON.parse(raw) || {};
                draftFields().forEach(function (el) {
                    if (!(el.name in data)) return;
                    var v = data[el.name];
                    if (el.type === 'checkbox' || el.type === 'radio') el.checked = Array.isArray(v) && v.indexOf(el.value) !== -1;
                    else if (el.multiple) Array.prototype.forEach.call(el.options, function (o) { o.selected = Array.isArray(v) && v.indexOf(o.value) !== -1; });
                    else el.value = v;
                });
                if (savedTag) savedTag.hidden = false;
            } catch (e) {}
        }
        form.addEventListener('input', function () { clearTimeout(saveTimer); saveTimer = setTimeout(saveDraft, 400); });
        form.addEventListener('change', saveDraft);
    }
    function clearDraft() { var ls = store(); try { if (ls) ls.removeItem(draftKey); } catch (e) {} if (savedTag) savedTag.hidden = true; }

    // Tabs (2026-11-11): one panel at a time; each tab counts its answered questions.
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.fm-tab'));
    function showTab(n) {
        tabs.forEach(function (t) { t.classList.toggle('on', t.dataset.tab === String(n)); });
        document.querySelectorAll('.fm-panel').forEach(function (p) { p.hidden = p.dataset.panel !== String(n); });
        height();
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { showTab(t.dataset.tab); }); });
    function countTabs() {
        document.querySelectorAll('.fm-panel').forEach(function (p) {
            var cols = p.querySelectorAll('.fm-col[data-field]'), total = 0, done = 0;
            cols.forEach(function (c) {
                var el = c.querySelector('textarea, input:not([type=hidden]), select');
                if (!el) return;
                total++;
                var choice = el.type === 'radio' || el.type === 'checkbox';
                if (choice ? c.querySelector('input:checked') : (el.value && el.value.trim() !== '')) done++;
            });
            var dot = document.querySelector('.fm-dot[data-count="' + p.dataset.panel + '"]');
            if (dot && total) { dot.textContent = done + '/' + total; dot.hidden = false; }
        });
    }
    if (tabs.length) { countTabs(); form.addEventListener('input', countTabs); form.addEventListener('change', countTabs); }

    // Progress (2026-11-11): "Guardar" keeps the answers on the server as a draft; "Guardar y enviar" sends them. Both
    // update the same submission (token kept in this browser), so they can go on and send again later.
    var resumeKey = 'murano-form-resume:' + key;
    function resumeToken() { var ls = store(); try { return (ls && ls.getItem(resumeKey)) || ''; } catch (e) { return ''; } }
    var saveBtn = document.getElementById('fm-save');
    var asDraft = false;
    if (saveBtn) saveBtn.addEventListener('click', function () { asDraft = true; if (form.requestSubmit) form.requestSubmit(); else form.dispatchEvent(new Event('submit', { cancelable: true })); });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var draftNow = asDraft;
        asDraft = false;
        var buttons = form.querySelectorAll('.fm-btn');
        buttons.forEach(function (b) { b.disabled = true; });
        err.hidden = true;
        msg.hidden = true;
        tabs.forEach(function (t) { t.classList.remove('err'); });
        form.querySelectorAll('.fm-col').forEach(function (c) {
            c.classList.remove('has-error');
            var x = c.querySelector('.fm-error'); if (x) { x.hidden = true; x.textContent = ''; }
        });
        var body = new FormData(form);
        if (startedAt) body.append('_ms', String(Date.now() - startedAt));   // filling time (dashboard)
        if (useDraft) {
            body.append('_resume', resumeToken());
            if (draftNow) body.append('_draft', '1');
        }
        fetch(form.action, { method: 'POST', body: body, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Error ' + r.status }; }); })
            .then(function (res) {
                buttons.forEach(function (b) { b.disabled = false; });
                if (res.ok && useDraft) {
                    // The answers stay on screen and in this browser, to go on later.
                    try { if (res.token) store().setItem(resumeKey, res.token); } catch (x) {}
                    saveDraft();
                    msg.textContent = res.message; msg.hidden = false;
                    msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (!res.draft && window.parent !== window) window.parent.postMessage({ type: 'murano-form', key: key, submitted: true }, '*');
                    height();
                    return;
                }
                if (res.ok) {
                    if (useDraft) clearDraft();
                    if (res.redirect && !res.redirect_after) { go(res.redirect); return; }
                    msg.textContent = res.message; msg.hidden = false;
                    if (res.redirect) {
                        // Message first, then the address (with a link in case the browser blocks it).
                        var a = document.createElement('a');
                        a.href = res.redirect; a.target = '_top'; a.textContent = @json($es ? 'Continuar' : 'Continue');
                        a.style.cssText = 'display:inline-block;margin-top:8px;color:inherit;font-weight:600';
                        msg.appendChild(document.createElement('br')); msg.appendChild(a);
                        setTimeout(function () { go(res.redirect); }, res.redirect_after * 1000);
                    }
                    if (form.dataset.hide === '1') form.hidden = true; else form.reset();
                    window.scrollTo(0, 0);
                    if (window.parent !== window) window.parent.postMessage({ type: 'murano-form', key: key, submitted: true }, '*');
                } else {
                    err.textContent = res.message || ''; err.hidden = !res.message;
                    var first = null;
                    Object.keys(res.errors || {}).forEach(function (k) {
                        var c = form.querySelector('.fm-col[data-field="' + k + '"]');
                        if (!c) return;
                        c.classList.add('has-error');
                        var x = c.querySelector('.fm-error'); if (x) { x.textContent = res.errors[k]; x.hidden = false; }
                        first = first || c;
                        // The tab holding the field turns red.
                        var panel = c.closest('.fm-panel');
                        if (panel) { var t = document.querySelector('.fm-tab[data-tab="' + panel.dataset.panel + '"]'); if (t) t.classList.add('err'); }
                    });
                    var firstPanel = first && first.closest('.fm-panel');
                    if (firstPanel) showTab(firstPanel.dataset.panel);
                    (first || err).scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                height();
            })
            .catch(function () { buttons.forEach(function (b) { b.disabled = false; }); err.textContent = @json(\App\Support\FormSchema::msg($s['language'], 'spam')); err.hidden = false; height(); });
    });
})();
</script>
</body>
</html>
