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
    /* Day strip + time slots */
    .daybar { display:flex; align-items:center; gap:6px; }
    .days { flex:1; display:grid; grid-template-columns:repeat(7, 1fr); gap:6px; min-width:0; }
    .nav { flex:none; width:34px; height:34px; border-radius:50%; border:1px solid var(--line); background:var(--card); color:var(--fg); font-size:20px; line-height:1; cursor:pointer; }
    .nav:disabled { opacity:.35; cursor:default; }
    .day { border:1px solid var(--line); border-radius:10px; padding:7px 0 6px; background:var(--card); color:var(--fg); cursor:pointer; font:inherit; text-align:center; min-width:0; }
    .day .dow { display:block; font-size:11.5px; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; }
    .day .num { display:block; font-size:19px; font-weight:700; line-height:1.2; }
    .day .mon { display:block; font-size:11px; color:var(--muted); }
    .day:hover:not(:disabled) { border-color:var(--accent); }
    .day[aria-pressed="true"] { background:var(--accent); border-color:var(--accent); color:var(--accent-fg); }
    .day[aria-pressed="true"] .dow, .day[aria-pressed="true"] .mon { color:var(--accent-fg); }
    .day:disabled { opacity:.38; cursor:default; }
    .day:disabled .num { text-decoration:line-through; }
    .slots { margin-top:14px; }
    .slot-group { margin-bottom:10px; }
    .slot-group-title { font-size:12.5px; font-weight:600; color:var(--muted); margin:0 0 6px; }
    .slot-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(86px, 1fr)); gap:8px; }
    .slot { padding:9px 0; border:1px solid var(--line); border-radius:8px; background:var(--card); color:var(--fg); font:600 14px/1.2 inherit; font-family:inherit; cursor:pointer; }
    .slot:hover:not(:disabled) { border-color:var(--accent); }
    .slot[aria-pressed="true"] { background:var(--accent); border-color:var(--accent); color:var(--accent-fg); }
    .slot:disabled { opacity:.35; cursor:default; text-decoration:line-through; }
    .slots-empty { color:var(--muted); font-size:14px; padding:10px 0; }
    .picked { margin-top:6px; font-size:14px; font-weight:600; min-height:1.2em; }
    .field.has-err .daybar .day:not(:disabled), .field.has-err .slot:not(:disabled) { border-color:#dc2626; }
    @media (max-width:420px) { .day .num { font-size:16px; } .day .mon { display:none; } .days { gap:4px; } }
    .form-logo { text-align:center; margin:0 0 16px; }
    .form-logo img { max-width:100%; height:auto; object-fit:contain; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:22px; }
    h1 { font-size:22px; margin:0 0 4px; }
    .sub { color:var(--muted); margin:0 0 18px; font-size:14px; }
    label { display:block; font-weight:600; font-size:14px; margin:0 0 6px; }
    .req { color:#dc2626; }
    .field { margin-bottom:14px; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .row.row-1 { grid-template-columns:1fr; }
    @media (max-width:560px) { .row { grid-template-columns:1fr; } }
    .field-help { color:var(--muted); font-size:12.5px; margin-top:5px; }
    .field-err { color:var(--err-fg); font-size:12.5px; margin-top:5px; min-height:0; }
    .field-err:empty { display:none; }
    .field.has-err input, .field.has-err select { border-color:#dc2626; }
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
    {{-- Header brand only when the form shows no logo of its own (no double logo). --}}
    @if(!$embed && empty($brand['form_logo']))
      <div class="brand">
        @if(!empty($brand['logo']))
          <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">
        @else
          <span class="mark">{{ mb_strtoupper(mb_substr($brand['name'], 0, 1)) }}</span>
          <span class="name">{{ $brand['name'] }}</span>
        @endif
      </div>
    @endif

    <div class="card">
      {{-- Settings -> Defaults -> Booking -> "Logo on the booking form". --}}
      @if(!empty($brand['form_logo']))
        <div class="form-logo">
          <img src="{{ $brand['form_logo']['url'] }}" alt="{{ $brand['name'] }}" style="width: {{ $brand['form_logo']['width'] }}px">
        </div>
      @endif
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

          {{-- Row 1: name. Row 2: email + phone. Row 3: service + staff. Row 4: date + time. --}}
          <div class="field">
            <label for="name">{{ __('Name') }} <span class="req">*</span></label>
            <input type="text" id="name" name="name" maxlength="120" required autocomplete="name" value="{{ $val('name') }}">
            <div class="field-err" id="nameErr"></div>
          </div>
          <div class="row">
            <div class="field">
              <label for="email">{{ __('Email') }} <span class="req">*</span></label>
              <input type="email" id="email" name="email" maxlength="190" required autocomplete="email"
                     placeholder="name@example.com" value="{{ $val('email') }}">
              <div class="field-err" id="emailErr"></div>
            </div>
            <div class="field">
              <label for="phone">{{ __('Phone') }}</label>
              <input type="tel" id="phone" name="phone" maxlength="25" autocomplete="tel" inputmode="tel"
                     placeholder="(555) 123-4567" value="{{ $val('phone') }}">
              <div class="field-help" id="phoneHelp">{{ __('10-digit number, e.g. (555) 123-4567 — or +country code for international.') }}</div>
              <div class="field-err" id="phoneErr"></div>
            </div>
          </div>
          <div class="row{{ $staff->isEmpty() ? ' row-1' : '' }}">
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
            @if($staff->isNotEmpty())
              <div class="field">
                <label for="staff_id">{{ __('Staff') }}</label>
                <select id="staff_id" name="staff_id">
                  <option value="">{{ __('Any staff') }}</option>
                  @foreach($staff as $p)
                    <option value="{{ $p->id }}" @selected((string) ($old['staff_id'] ?? '') === (string) $p->id)>{{ $p->name }}</option>
                  @endforeach
                </select>
              </div>
            @endif
          </div>
          {{-- Date & time: tap a day, then a time slot (Settings -> Defaults -> Booking ->
               Online booking hours). Fills the two hidden fields the server reads. --}}
          <div class="field" id="dtField">
            <label id="dtLabel">{{ __('Date & time') }} <span class="req">*</span></label>
            <div class="daybar" role="group" aria-labelledby="dtLabel">
              <button type="button" class="nav" id="dayPrev" aria-label="{{ __('Previous days') }}">&#8249;</button>
              <div class="days" id="days"></div>
              <button type="button" class="nav" id="dayNext" aria-label="{{ __('Next days') }}">&#8250;</button>
            </div>
            <div id="slots" class="slots" aria-live="polite"></div>
            <div class="picked" id="picked"></div>
            <div class="field-err" id="dtErr"></div>
            <input type="hidden" id="appointment_date" name="appointment_date" value="{{ $val('appointment_date') }}">
            <input type="hidden" id="appointment_time" name="appointment_time" value="{{ $val('appointment_time') }}">
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
        if (o.dataset.duration) {
          var m = +o.dataset.duration, h = Math.floor(m / 60), r = m % 60;
          parts.push([h ? h + ' h' : '', r ? r + ' min' : ''].filter(Boolean).join(' '));
        }
        info.textContent = parts.join(' · ');
      }
      if (sel) { sel.addEventListener('change', showSvc); showSvc(); }
      // ---- date & time picker: day strip + slot grid ----
      // "Now" is the SERVER's clock in the app timezone, so a visitor elsewhere
      // still sees the salon's local times (and past slots greyed out correctly).
      var DT = {!! json_encode([
        'open' => $hours['open'], 'close' => $hours['close'], 'slot' => $hours['slot'], 'closed' => $hours['closed'],
        'capacity' => $hours['capacity'], 'busy' => (object) $busy,
        'today' => now()->toDateString(), 'nowMin' => now()->hour * 60 + now()->minute,
        'days' => 364, 'lang' => str_replace('_', '-', app()->getLocale()),
        't' => [
          'morning' => __('Morning'), 'afternoon' => __('Afternoon'), 'evening' => __('Evening'),
          'none' => __('No times available on this day — please pick another day.'),
          'closed' => __('Closed'), 'at' => __('at'), 'booked' => __('Already booked'), 'pick' => __('Please choose a day and a time.'),
        ],
      ]) !!};
      var daysEl = document.getElementById('days'), slotsEl = document.getElementById('slots'),
          pickedEl = document.getElementById('picked'), dateIn = document.getElementById('appointment_date'),
          timeIn = document.getElementById('appointment_time');
      var PAGE = 7, page = 0;
      function toMin(hm) { return (+hm.slice(0, 2)) * 60 + (+hm.slice(3, 5)); }
      function hm(min) { return String(Math.floor(min / 60)).padStart(2, '0') + ':' + String(min % 60).padStart(2, '0'); }
      function label12(min) {
        var h = Math.floor(min / 60), m = String(min % 60).padStart(2, '0');
        return ((h % 12) || 12) + ':' + m + ' ' + (h < 12 ? 'AM' : 'PM');
      }
      function dayAt(i) { // i days after the server's today, as a local-noon Date (DST-safe)
        var p = DT.today.split('-'); return new Date(+p[0], +p[1] - 1, +p[2] + i, 12);
      }
      function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
      function fmt(d, o) { try { return d.toLocaleDateString(DT.lang, o); } catch (e) { return d.toLocaleDateString(undefined, o); } }
      // Selected service's duration (Product form -> Service duration; 60 if unset).
      function curDur() {
        var o = sel && sel.options[sel.selectedIndex];
        return (o && +o.dataset.duration) || 60;
      }
      // Most bookings running at one moment inside [from, to) -- same as
      // PublicBookingController::maxConcurrent().
      function maxConcurrent(list, from, to) {
        var points = [from], max = 0;
        list.forEach(function (b) { if (b[0] > from && b[0] < to) points.push(b[0]); });
        points.forEach(function (p) {
          var n = list.filter(function (b) { return b[0] <= p && b[1] > p; }).length;
          if (n > max) max = n;
        });
        return max;
      }
      // Start times whose whole service duration fits before closing; blocked if
      // already past (today) or if the time is fully booked (capacity reached).
      function slotsFor(isoDay) {
        var out = [], open = toMin(DT.open), close = toMin(DT.close), isToday = isoDay === DT.today,
            dur = curDur(), busy = DT.busy[isoDay] || [];
        for (var t = open; t + dur <= close; t += DT.slot) {
          var past = isToday && t <= DT.nowMin;
          out.push({ min: t, past: past, taken: !past && maxConcurrent(busy, t, t + dur) >= DT.capacity });
        }
        return out;
      }
      function isClosed(d) { return DT.closed.indexOf(d.getDay()) !== -1; }
      function free(s) { return !s.past && !s.taken; }
      function hasFree(d) { return !isClosed(d) && slotsFor(iso(d)).some(free); }

      function renderDays() {
        daysEl.innerHTML = '';
        for (var i = page * PAGE; i < page * PAGE + PAGE; i++) {
          var d = dayAt(i), key = iso(d), b = document.createElement('button');
          b.type = 'button'; b.className = 'day'; b.dataset.date = key;
          b.disabled = i > DT.days || !hasFree(d);
          b.setAttribute('aria-pressed', key === dateIn.value ? 'true' : 'false');
          b.setAttribute('aria-label', fmt(d, { weekday: 'long', month: 'long', day: 'numeric' }) + (isClosed(d) ? ' — ' + DT.t.closed : ''));
          b.innerHTML = '<span class="dow"></span><span class="num"></span><span class="mon"></span>';
          b.children[0].textContent = fmt(d, { weekday: 'short' });
          b.children[1].textContent = d.getDate();
          b.children[2].textContent = fmt(d, { month: 'short' });
          b.addEventListener('click', function () { pickDay(this.dataset.date); });
          daysEl.appendChild(b);
        }
        document.getElementById('dayPrev').disabled = page === 0;
        document.getElementById('dayNext').disabled = (page + 1) * PAGE > DT.days;
      }
      function renderSlots() {
        slotsEl.innerHTML = '';
        if (!dateIn.value) return;
        var list = slotsFor(dateIn.value);
        if (!list.some(free)) {
          slotsEl.innerHTML = '<div class="slots-empty"></div>'; slotsEl.firstChild.textContent = DT.t.none; return;
        }
        [['morning', 0, 720], ['afternoon', 720, 1020], ['evening', 1020, 1440]].forEach(function (g) {
          var items = list.filter(function (s) { return s.min >= g[1] && s.min < g[2]; });
          if (!items.length) return;
          var box = document.createElement('div'), title = document.createElement('div'), grid = document.createElement('div');
          box.className = 'slot-group'; title.className = 'slot-group-title'; grid.className = 'slot-grid';
          title.textContent = DT.t[g[0]];
          items.forEach(function (s) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'slot'; b.textContent = label12(s.min); b.dataset.time = hm(s.min);
            b.disabled = !free(s);
            if (s.taken) b.title = DT.t.booked;
            b.setAttribute('aria-pressed', hm(s.min) === timeIn.value ? 'true' : 'false');
            b.addEventListener('click', function () { pickTime(this.dataset.time); });
            grid.appendChild(b);
          });
          box.appendChild(title); box.appendChild(grid); slotsEl.appendChild(box);
        });
      }
      function renderPicked() {
        if (!dateIn.value || !timeIn.value) { pickedEl.textContent = ''; return; }
        var p = dateIn.value.split('-'), d = new Date(+p[0], +p[1] - 1, +p[2], 12);
        pickedEl.textContent = '✓ ' + fmt(d, { weekday: 'long', month: 'long', day: 'numeric' }) + ' ' + DT.t.at + ' ' + label12(toMin(timeIn.value));
      }
      function pickDay(isoDay) {
        if (dateIn.value !== isoDay) timeIn.value = '';
        dateIn.value = isoDay;
        renderDays(); renderSlots(); renderPicked(); clearDtErr();
      }
      function pickTime(t) { timeIn.value = t; renderSlots(); renderPicked(); clearDtErr(); }
      function clearDtErr() { if (dateIn.value && timeIn.value) setDtErr(''); }
      function setDtErr(msg) {
        document.getElementById('dtErr').textContent = msg;
        document.getElementById('dtField').classList.toggle('has-err', !!msg);
      }
      if (daysEl) {
        document.getElementById('dayPrev').addEventListener('click', function () { if (page > 0) { page--; renderDays(); } });
        document.getElementById('dayNext').addEventListener('click', function () { page++; renderDays(); });
        // Start on the page of a previously chosen day (form re-shown with errors),
        // else pre-select the first day that still has free times.
        var start = -1;
        if (dateIn.value) {
          for (var i = 0; i <= DT.days; i++) { if (iso(dayAt(i)) === dateIn.value) { start = i; break; } }
          if (start === -1 || !hasFree(dayAt(start))) { dateIn.value = ''; timeIn.value = ''; start = -1; }
        }
        if (start === -1) {
          for (var j = 0; j <= DT.days && j < 60; j++) { if (hasFree(dayAt(j))) { start = j; dateIn.value = iso(dayAt(j)); break; } }
        }
        page = start > 0 ? Math.floor(start / PAGE) : 0;
        renderDays(); renderSlots(); renderPicked();

        // A different service = a different duration: re-check what's free. The
        // chosen time is kept only if the new service still fits there.
        if (sel) sel.addEventListener('change', function () {
          if (dateIn.value && timeIn.value) {
            var still = slotsFor(dateIn.value).some(function (s) { return free(s) && hm(s.min) === timeIn.value; });
            if (!still) timeIn.value = '';
          }
          renderDays(); renderSlots(); renderPicked();
        });
      }

      // ---- field checks (same rules as PublicBookingController::store) ----
      var MSG = {!! json_encode([
        'name' => __('Please enter your name.'),
        'email' => __('Please enter a valid email address, e.g. name@example.com.'),
        'phone' => __('Please enter a valid phone number: 10 digits, e.g. (555) 123-4567, or + and country code.'),
      ]) !!};
      // user@domain.tld -- no spaces, one @, a dot in the domain, 2+ letter TLD.
      var EMAIL_RE = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)*\.[A-Za-z]{2,}$/;
      function digits(v) { return (v || '').replace(/\D/g, ''); }
      function phoneOk(v) {
        v = (v || '').trim();
        if (!v) return true; // optional
        var d = digits(v);
        if (v.charAt(0) === '+') return d.length >= 8 && d.length <= 15;
        return d.length === 10 || (d.length === 11 && d.charAt(0) === '1');
      }
      // As-you-type helper: US numbers become (555) 123-4567; "+..." is left for international.
      function formatPhone(v) {
        if ((v || '').trim().charAt(0) === '+') return v;
        var d = digits(v);
        if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);
        d = d.slice(0, 10);
        if (d.length > 6) return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
        if (d.length > 3) return '(' + d.slice(0, 3) + ') ' + d.slice(3);
        return d;
      }
      var checks = {
        name: function (v) { return v.trim() ? '' : MSG.name; },
        email: function (v) { return EMAIL_RE.test(v.trim()) ? '' : MSG.email; },
        phone: function (v) { return phoneOk(v) ? '' : MSG.phone; },
      };
      function check(id) {
        var el = document.getElementById(id), box = document.getElementById(id + 'Err');
        if (!el || !box) return true;
        var msg = checks[id](el.value);
        box.textContent = msg;
        el.closest('.field').classList.toggle('has-err', !!msg);
        el.setAttribute('aria-invalid', msg ? 'true' : 'false');
        return !msg;
      }
      Object.keys(checks).forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('blur', function () { check(id); });
        // Once flagged, re-check while typing so the message clears as soon as it's fixed.
        el.addEventListener('input', function () {
          if (id === 'phone') {
            var f = formatPhone(el.value);
            if (f !== el.value) el.value = f;
          }
          if (el.closest('.field').classList.contains('has-err')) check(id);
        });
      });
      var phoneEl = document.getElementById('phone');
      if (phoneEl && phoneEl.value) phoneEl.value = formatPhone(phoneEl.value);

      var form = document.getElementById('bookForm'), btn = document.getElementById('bookBtn');
      if (form) form.addEventListener('submit', function (e) {
        var ok = true, first = null;
        Object.keys(checks).forEach(function (id) {
          if (!check(id)) { ok = false; first = first || document.getElementById(id); }
        });
        if (dateIn && (!dateIn.value || !timeIn.value)) {
          setDtErr(DT.t.pick);
          if (ok) { ok = false; first = slotsEl.querySelector('.slot:not(:disabled)') || daysEl.querySelector('.day:not(:disabled)'); }
        }
        if (!ok) { e.preventDefault(); if (first) first.focus(); return; }
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
