{{-- Forms module: a public form (own page, or bare inside an iframe). PublicFormController. --}}
@php
    $es = $s['language'] === 'es';
    $err = $errors_bag ?? [];
    $val = fn ($k, $d = '') => $old[$k] ?? $d;
    $req = $es ? 'Obligatorio' : 'Required';
@endphp
<!DOCTYPE html>
<html lang="{{ $s['language'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $s['title'] ?: $form->name }}{{ $embed ? '' : ' - '.$brand['name'] }}</title>
<style>
:root {
    --fm-primary: {{ $s['primary_color'] }};
    --fm-bg: {{ $s['background'] }};
    --fm-radius: {{ $s['radius'] }}px;
    --fm-text: #1f2328;
    --fm-muted: #6b7280;
    --fm-border: #d0d7de;
    --fm-error: #d93025;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: {!! $s['font'] === 'serif' ? 'Georgia, "Times New Roman", serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif' !!};
    color: var(--fm-text); background: {{ $embed ? 'transparent' : '#f4f6f8' }}; font-size: 15px; line-height: 1.45;
}
.fm-page { max-width: 760px; margin: 0 auto; padding: {{ $embed ? '4px' : '32px 16px' }}; }
.fm-card { background: var(--fm-bg); border-radius: var(--fm-radius); padding: {{ $embed ? '12px 4px' : '28px' }}; {{ $embed ? '' : 'box-shadow: 0 2px 14px rgba(0,0,0,.07);' }} }
.fm-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
.fm-brand img { max-height: 48px; max-width: 180px; object-fit: contain; }
.fm-brand span { font-weight: 600; color: var(--fm-muted); }
.fm-title { margin: 0 0 6px; font-size: 1.5em; }
.fm-desc { margin: 0 0 18px; color: var(--fm-muted); white-space: pre-line; }
.fm-grid { display: flex; flex-wrap: wrap; margin: 0 -8px; }
.fm-col { padding: 0 8px; margin-bottom: 14px; width: 100%; }
.fm-col.half { width: 50%; }
.fm-col.third { width: 33.333%; }
@media (max-width: 560px) { .fm-col.half, .fm-col.third { width: 100%; } }
.fm-label { display: block; font-weight: 600; margin-bottom: 5px; }
.fm-label .fm-star { color: var(--fm-error); margin-left: 2px; }
.fm-input, .fm-select, .fm-textarea {
    width: 100%; padding: 9px 11px; border: 1px solid var(--fm-border); border-radius: calc(var(--fm-radius) * .75);
    font: inherit; color: inherit; background: #fff;
}
.fm-input:focus, .fm-select:focus, .fm-textarea:focus { outline: 2px solid color-mix(in srgb, var(--fm-primary) 35%, transparent); border-color: var(--fm-primary); }
.fm-textarea { resize: vertical; }
.fm-choice { display: flex; align-items: flex-start; gap: 8px; margin: 4px 0; font-weight: normal; }
.fm-choice input { margin-top: 3px; accent-color: var(--fm-primary); }
.fm-help { color: var(--fm-muted); font-size: .88em; margin-top: 4px; }
.fm-error { color: var(--fm-error); font-size: .88em; margin-top: 4px; }
.fm-col.has-error .fm-input, .fm-col.has-error .fm-select, .fm-col.has-error .fm-textarea { border-color: var(--fm-error); }
.fm-heading { margin: 10px 0 2px; }
.fm-paragraph { margin: 0; color: var(--fm-muted); white-space: pre-line; }
.fm-divider { border: 0; border-top: 1px solid var(--fm-border); margin: 6px 0; }
.fm-btn {
    background: var(--fm-primary); color: #fff; border: 0; border-radius: var(--fm-radius); padding: 11px 26px;
    font: inherit; font-weight: 600; cursor: pointer;
}
.fm-btn[disabled] { opacity: .6; cursor: default; }
.fm-alert { padding: 12px 14px; border-radius: var(--fm-radius); margin-bottom: 16px; white-space: pre-line; }
.fm-alert.ok { background: #e8f5e9; color: #1b5e20; }
.fm-alert.bad { background: #fdecea; color: #8a1c13; }
.fm-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
</style>
</head>
<body>
<div class="fm-page">
<div class="fm-card">
    @if (! $embed && ($brand['logo'] || $brand['name']))
        <div class="fm-brand">
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
          data-hide="{{ $s['hide_after_submit'] ? 1 : 0 }}" @if ($sent && $s['hide_after_submit']) hidden @endif>
        <input type="hidden" name="_t" value="{{ $stamp }}">
        <input type="hidden" name="_page" id="fm-page" value="">
        @if ($s['honeypot'])
            <div class="fm-hp" aria-hidden="true"><label>Website <input type="text" name="_hp" tabindex="-1" autocomplete="off"></label></div>
        @endif
        <div class="fm-grid">
        @foreach ($fields as $f)
            @php $k = $f['key']; $id = 'fm_'.$k; $w = $f['width'] ?? 'full'; @endphp
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
                    @if (! in_array($f['type'], ['checkbox', 'radio'], true))
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
        <button type="submit" class="fm-btn">{{ $s['submit_text'] ?: ($es ? 'Enviar' : 'Send') }}</button>
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

    if (!form || !window.fetch || !window.FormData) return;
    var msg = document.getElementById('fm-msg'), err = document.getElementById('fm-err');
    // Dashboard funnel: "started" once, when the visitor begins typing / choosing.
    var started = params.has('preview');
    function start() {
        if (started) return;
        started = true;
        fetch(form.action + '/start', { method: 'POST', keepalive: true, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(function () {});
    }
    form.addEventListener('input', start);
    form.addEventListener('change', start);
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('.fm-btn');
        btn.disabled = true;
        err.hidden = true;
        form.querySelectorAll('.fm-col').forEach(function (c) {
            c.classList.remove('has-error');
            var x = c.querySelector('.fm-error'); if (x) { x.hidden = true; x.textContent = ''; }
        });
        fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Error ' + r.status }; }); })
            .then(function (res) {
                btn.disabled = false;
                if (res.ok) {
                    if (res.redirect) {
                        if (window.parent !== window) window.parent.postMessage({ type: 'murano-form', key: key, redirect: res.redirect }, '*');
                        else location.href = res.redirect;
                        return;
                    }
                    msg.textContent = res.message; msg.hidden = false;
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
                    });
                    (first || err).scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                height();
            })
            .catch(function () { btn.disabled = false; err.textContent = @json(\App\Support\FormSchema::msg($s['language'], 'spam')); err.hidden = false; height(); });
    });
})();
</script>
</body>
</html>
