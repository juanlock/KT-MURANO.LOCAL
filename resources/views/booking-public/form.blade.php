{{-- Bookings public page: "Book an appointment". Standalone (inline CSS/JS, no build step).
     $embed = compact layout for iframes on external websites (no page chrome, transparent
     background, reports its height to the parent page for auto-resize). --}}
@php
  $rtl = app()->getLocale() === 'ar';
  $old = $old ?? [];
  $val = fn ($k, $d = '') => e($old[$k] ?? $d);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ __('Book an appointment') }} · {{ $brand['name'] }}</title>
  <style>
    :root { --bg:#f4f6fa; --card:#fff; --fg:#1f2937; --muted:#6b7280; --line:#e5e7eb; --accent:#b8860b; --accent-fg:#fff;
            --ok-bg:#ecfdf5; --ok-fg:#065f46; --err-bg:#fef2f2; --err-fg:#991b1b; --pick:#fdf6e3; }
    @media (prefers-color-scheme: dark) {
      :root:not(.embed) { --bg:#0f172a; --card:#111827; --fg:#e5e7eb; --muted:#9ca3af; --line:#1f2937; --accent:#d4a72c; --accent-fg:#111;
              --ok-bg:#052e24; --ok-fg:#6ee7b7; --err-bg:#3b0d0d; --err-fg:#fca5a5; --pick:#2a2110; }
    }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--bg); color:var(--fg); font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
    html.embed body { background:transparent; }
    .wrap { max-width:760px; margin:0 auto; padding:24px 16px 40px; }
    html.embed .wrap { padding:8px; max-width:none; }
    .brand { display:flex; align-items:center; gap:12px; margin-bottom:18px; }
    .brand img { max-height:48px; max-width:220px; object-fit:contain; }
    .brand .mark { width:40px; height:40px; border-radius:10px; background:var(--accent); color:var(--accent-fg); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:18px; }
    .brand .name { font-weight:600; font-size:17px; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:22px; }
    h1 { font-size:22px; margin:0 0 4px; }
    .sub { color:var(--muted); margin:0 0 18px; font-size:14px; }
    label { display:block; font-weight:600; font-size:14px; margin:0 0 6px; }
    .req { color:#dc2626; }
    .field { margin-bottom:14px; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:560px) { .row { grid-template-columns:1fr; } }
    input, select, textarea { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:var(--card); color:var(--fg); font:inherit; }
    textarea { min-height:90px; resize:vertical; }
    input:focus, select:focus, textarea:focus { outline:2px solid var(--accent); outline-offset:-1px; }
    .svc-info { color:var(--muted); font-size:13px; margin-top:6px; min-height:1em; }
    .btn { display:inline-block; background:var(--accent); color:var(--accent-fg); border:0; border-radius:8px; padding:12px 22px; font:600 15px/1.2 inherit; font-family:inherit; cursor:pointer; text-decoration:none; }
    .btn:disabled { opacity:.6; cursor:default; }
    .center { text-align:center; }
    .alert { border-radius:8px; padding:12px 14px; margin-bottom:16px; font-size:14px; }
    .alert ul { margin:0; padding-inline-start:18px; }
    .alert-ok { background:var(--ok-bg); color:var(--ok-fg); }
    .alert-err { background:var(--err-bg); color:var(--err-fg); }
    .done dl { display:grid; grid-template-columns:max-content 1fr; gap:6px 16px; margin:14px 0 18px; }
    .done dt { color:var(--muted); } .done dd { margin:0; font-weight:600; }
    .hp { position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden; }
    footer { color:var(--muted); font-size:12.5px; text-align:center; margin-top:18px; }
  </style>
  @if($embed)<script>document.documentElement.classList.add('embed');</script>@endif
</head>
<body>
  <div class="wrap">
    @unless($embed)
      <div class="brand">
        @if(!empty($brand['logo']))
          <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">
        @else
          <span class="mark">{{ mb_strtoupper(mb_substr($brand['name'], 0, 1)) }}</span>
          <span class="name">{{ $brand['name'] }}</span>
        @endif
      </div>
    @endunless

    <div class="card">
      @if($done)
        <div class="done" role="status">
          <h1>{{ __('Thank you, :name!', ['name' => $done['name']]) }}</h1>
          <p class="sub">{{ __('Your appointment request was received. We sent the details to your email and will contact you to confirm it.') }}</p>
          <dl>
            <dt>{{ __('Reference') }}</dt><dd>{{ $done['ref'] }}</dd>
            <dt>{{ __('Service') }}</dt><dd>{{ $done['service'] }}</dd>
            <dt>{{ __('Date') }}</dt><dd>{{ $done['when'] }}</dd>
          </dl>
          <a class="btn" href="{{ route('booking.public', $formQuery) }}">{{ __('Book another appointment') }}</a>
        </div>
      @elseif($services->isEmpty())
        <h1>{{ __('Book an appointment') }}</h1>
        <p class="sub">{{ __('Online booking is not available right now. Please contact us directly.') }}</p>
      @else
        <h1>{{ __('Make an appointment') }}</h1>
        <p class="sub">{{ __('Choose a service, a date and a time — we will confirm your booking.') }}</p>

        @if(!empty($formErrors))
          <div class="alert alert-err" role="alert"><ul>@foreach($formErrors as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('booking.public.store', $formQuery) }}" id="bookForm" novalidate>
          <input type="hidden" name="_ts" value="{{ $formTs }}">
          <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

          <div class="row">
            <div class="field">
              <label for="name">{{ __('Name') }} <span class="req">*</span></label>
              <input type="text" id="name" name="name" maxlength="120" required autocomplete="name" value="{{ $val('name') }}">
            </div>
            <div class="field">
              <label for="email">{{ __('Email') }} <span class="req">*</span></label>
              <input type="email" id="email" name="email" maxlength="190" required autocomplete="email" value="{{ $val('email') }}">
            </div>
          </div>
          <div class="row">
            <div class="field">
              <label for="phone">{{ __('Phone') }}</label>
              <input type="tel" id="phone" name="phone" maxlength="40" autocomplete="tel" value="{{ $val('phone') }}">
            </div>
            <div class="field">
              <label for="service_id">{{ __('Service') }} <span class="req">*</span></label>
              <select id="service_id" name="service_id" required>
                <option value="">{{ __('Select a service') }}</option>
                {{-- One <optgroup> per Group Name (ungrouped services last, without a heading). --}}
                @foreach($services->groupBy(fn ($s) => $s->group ?? '') as $group => $items)
                  @if($group !== '')<optgroup label="{{ $group }}">@endif
                  @foreach($items as $s)
                    <option value="{{ $s->id }}" data-price="{{ number_format($s->price, 2) }}" data-duration="{{ $s->duration }}"
                      @selected((string) ($old['service_id'] ?? request('service')) === (string) $s->id)>{{ $s->name }}</option>
                  @endforeach
                  @if($group !== '')</optgroup>@endif
                @endforeach
              </select>
              <div class="svc-info" id="svcInfo"></div>
            </div>
          </div>
          @if($staff->isNotEmpty())
            <div class="row">
              <div class="field">
                <label for="staff_id">{{ __('Staff') }}</label>
                <select id="staff_id" name="staff_id">
                  <option value="">{{ __('Any staff') }}</option>
                  @foreach($staff as $p)
                    <option value="{{ $p->id }}" @selected((string) ($old['staff_id'] ?? '') === (string) $p->id)>{{ $p->name }}</option>
                  @endforeach
                </select>
              </div>
            </div>
          @endif
          <div class="row">
            <div class="field">
              <label for="appointment_date">{{ __('Date') }} <span class="req">*</span></label>
              <input type="date" id="appointment_date" name="appointment_date" required min="{{ now()->toDateString() }}" max="{{ now()->addYear()->subDay()->toDateString() }}" value="{{ $val('appointment_date') }}">
            </div>
            <div class="field">
              <label for="appointment_time">{{ __('Time') }} <span class="req">*</span></label>
              <input type="time" id="appointment_time" name="appointment_time" required step="300" value="{{ $val('appointment_time') }}">
            </div>
          </div>
          <div class="field">
            <label for="special_requests">{{ __('Special requests') }}</label>
            <textarea id="special_requests" name="special_requests" maxlength="2000" placeholder="{{ __('Anything we should know before your visit…') }}">{{ $val('special_requests') }}</textarea>
          </div>
          <div class="center"><button type="submit" class="btn" id="bookBtn">{{ __('Submit appointment') }}</button></div>
        </form>
      @endif
    </div>

    @unless($embed)<footer>{{ $brand['name'] }} · {{ __('Online booking') }}</footer>@endunless
  </div>

  <script>
    (function () {
      var sel = document.getElementById('service_id'), info = document.getElementById('svcInfo');
      function showSvc() {
        if (!sel || !info) return;
        var o = sel.options[sel.selectedIndex];
        if (!o || !o.value) { info.textContent = ''; return; }
        var parts = [];
        if (o.dataset.price) parts.push({!! json_encode(__('Price')) !!} + ': $' + o.dataset.price);
        if (o.dataset.duration) parts.push(o.dataset.duration + ' min');
        info.textContent = parts.join(' · ');
      }
      if (sel) { sel.addEventListener('change', showSvc); showSvc(); }
      var form = document.getElementById('bookForm'), btn = document.getElementById('bookBtn');
      if (form) form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) { e.preventDefault(); form.reportValidity(); return; }
        btn.disabled = true; btn.textContent = {!! json_encode(__('Sending…')) !!};
      });
      // Embedded: tell the parent page our height so the iframe can grow with the content.
      if (window.parent !== window) {
        var last = 0;
        var post = function () {
          var h = document.documentElement.scrollHeight;
          if (h !== last) { last = h; window.parent.postMessage({ type: 'murano-booking-height', height: h }, '*'); }
        };
        window.addEventListener('load', post);
        if (window.ResizeObserver) new ResizeObserver(post).observe(document.body); else setInterval(post, 500);
        post();
      }
    })();
  </script>
</body>
</html>
