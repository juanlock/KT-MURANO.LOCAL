{{--
  SuperAdmin login chooser (2026-10-20) -- LoginChooseController@show: right
  after signing in, "Go to the dashboard" or "Enter as the admin" of a company.
  Same look as the login page (2026-10-25 KCOLTECH design: light blue page, navy
  palette; the login background image when one is set).
--}}
@php
  $es = app()->getLocale() === 'es';
  $L = fn ($en, $esText) => $es ? $esText : $en;
  $bgImage = $app_settings->login_bg_image ?? null;
  $bgImageUrl = $bgImage ? (str_contains($bgImage, '/') ? asset($bgImage) : asset('images/'.$bgImage)) : null;
  $firstName = trim($user->firstname ?? '') ?: ($user->username ?? '');
  $initial = fn ($s) => mb_strtoupper(mb_substr(trim((string) $s), 0, 1)) ?: '?';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{{ $L('Choose how to enter', 'Elija cómo entrar') }} · {{ $app_settings->app_name ?? 'Murano' }}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root { --primary: #0b4fa0; --primary-hover: #083f82; --text: #0f2747; --muted: #5b6b82; --border: #dbe5ef; --navy: #03387b; }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; color: var(--text);
      background: linear-gradient(160deg, #f7fbfe 0%, #f1f8fe 40%, #e3f0fd 75%, #d4e8fc 100%) fixed;
      @if ($bgImageUrl) background: url('{{ $bgImageUrl }}') center / cover no-repeat fixed; @endif
      display: flex; align-items: center; justify-content: center; padding: 24px 16px;
    }
    /* Soft curved light bands, like the login page. */
    .overlay { position: fixed; inset: 0; overflow: hidden; pointer-events: none; }
    .overlay::before, .overlay::after { content: ''; position: absolute; border-radius: 50%; }
    .overlay::before { width: 140vw; height: 70vw; left: -35vw; bottom: -52vw; background: radial-gradient(closest-side, rgba(255,255,255,.85), rgba(255,255,255,0)); }
    .overlay::after { width: 60vw; height: 60vw; right: -26vw; top: -30vw; background: radial-gradient(closest-side, rgba(150,200,248,.55), rgba(150,200,248,0)); }
    .modal {
      position: relative; width: 100%; max-width: 1080px; max-height: calc(100vh - 48px); display: flex; flex-direction: column;
      background: #fff; border: 1px solid rgba(219,229,239,.8); border-radius: 18px; overflow: hidden;
      box-shadow: 0 30px 70px -25px rgba(16,64,128,.25), 0 8px 24px -12px rgba(16,64,128,.12);
    }
    .head { padding: 26px 28px 14px; display: flex; align-items: center; gap: 16px; }
    .head img { height: 52px; width: auto; flex: none; }
    .head h1 { margin: 0; font-size: 1.6rem; font-weight: 800; color: var(--navy); letter-spacing: -.02em; }
    .head p { margin: 6px 0 0; color: var(--muted); }
    .dash {
      margin: 8px 28px 4px; display: flex; align-items: center; gap: 16px; width: calc(100% - 56px); text-align: left;
      padding: 16px 18px; border: 2px solid var(--primary); border-radius: 14px; background: #eef5fd; cursor: pointer; font: inherit; color: inherit;
      transition: background .15s, transform .15s;
    }
    .dash:hover { background: #dbe8f8; transform: translateY(-1px); }
    .dash:focus { outline: none; }
    .dash:focus-visible { outline: 3px solid #b9d3f2; outline-offset: 2px; }
    .dash strong { color: var(--navy); }
    .dash .ic { width: 44px; height: 44px; border-radius: 12px; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; flex: none; }
    .dash strong { display: block; font-size: 1.05rem; }
    .dash span { color: var(--muted); font-size: .9rem; }
    .sep { display: flex; align-items: center; gap: 12px; margin: 18px 28px 10px; color: var(--muted); font-size: .85rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
    .sep::before, .sep::after { content: ""; flex: 1; height: 1px; background: var(--border); }
    .search { margin: 0 28px 12px; }
    .search input { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 10px; font: inherit; }
    .search input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px #dbe8f8; }
    .list { padding: 0 28px 20px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; align-items: start; }
    .co { border: 1px solid var(--border); border-radius: 14px; padding: 14px; display: flex; flex-direction: column; gap: 10px; }
    .co-top { display: flex; align-items: center; gap: 12px; }
    .co-logo { width: 44px; height: 44px; border-radius: 10px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; overflow: hidden; flex: none; font-weight: 800; color: var(--primary); }
    .co-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .co-name { font-weight: 700; line-height: 1.2; }
    .co-type { color: var(--muted); font-size: .8rem; }
    .adm { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 10px; background: #fff; cursor: pointer; font: inherit; color: inherit; text-align: left; }
    .adm:hover { border-color: var(--primary); background: #f3f8fe; }
    /* The name / email side shrinks (ellipsis) so "Enter" never overlaps it. */
    .adm > span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .adm small { display: block; color: var(--muted); font-size: .78rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .adm b { flex: none; color: var(--primary); font-size: .85rem; white-space: nowrap; }
    .co-top > div:last-child { min-width: 0; }
    .none { color: var(--muted); font-size: .85rem; font-style: italic; }
    .foot { border-top: 1px solid var(--border); padding: 12px 28px; display: flex; justify-content: space-between; align-items: center; color: var(--muted); font-size: .85rem; }
    .foot button { background: none; border: 0; color: var(--muted); cursor: pointer; font: inherit; text-decoration: underline; }
    form { margin: 0; }
    .notice { margin: 0 28px 8px; padding: 10px 14px; border-radius: 10px; background: #fff7e6; border: 1px solid #ffd591; color: #874d00; font-size: .9rem; }
    @media (max-width: 560px) { .head, .foot { padding-left: 18px; padding-right: 18px; } .dash { margin-left: 18px; margin-right: 18px; width: calc(100% - 36px); } .sep, .search { margin-left: 18px; margin-right: 18px; } .list { padding: 0 18px 18px; grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="overlay"></div>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="ch-title">
    <div class="head">
      <img src="{{ asset('images/login/kcoltech-mark.png') }}" alt="KCOLTECH">
      <div>
        <h1 id="ch-title">{{ $L('Hi', 'Hola') }}, {{ $firstName }}</h1>
        <p>{{ $L('How do you want to enter?', '¿Cómo quiere entrar?') }}</p>
      </div>
    </div>
    @if (session('choose_notice'))
      <div class="notice" role="status">{{ session('choose_notice') }}</div>
    @endif

    <form method="POST" action="{{ route('login.choose.enter') }}">
      @csrf
      <input type="hidden" name="target" value="dashboard">
      <button type="submit" class="dash" autofocus>
        <span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg></span>
        <span><strong>{{ $L('Go to the dashboard', 'Ir al dashboard') }}</strong><span>{{ $L('As SuperAdmin: every company, every module.', 'Como SuperAdmin: todas las compañías, todos los módulos.') }}</span></span>
      </button>
    </form>

    <div class="sep">{{ $L('or enter as the admin of a company', 'o entre como admin de una compañía') }}</div>
    @if (count($companies) > 6)
      <div class="search"><input type="search" id="ch-search" placeholder="{{ $L('Search company or admin…', 'Buscar compañía o admin…') }}" autocomplete="off"></div>
    @endif

    <div class="list" id="ch-list">
      @foreach ($companies as $c)
        <div class="co" data-search="{{ mb_strtolower($c->name.' '.$c->admins->pluck('name')->implode(' ').' '.$c->admins->pluck('email')->implode(' ')) }}">
          <div class="co-top">
            <div class="co-logo">@if ($c->logo)<img src="{{ $c->logo }}" alt="">@else{{ $initial($c->name) }}@endif</div>
            <div><div class="co-name">{{ $c->name }}</div>@if ($c->type)<div class="co-type">{{ $c->type }}</div>@endif</div>
          </div>
          @forelse ($c->admins as $a)
            <form method="POST" action="{{ route('login.choose.enter') }}">
              @csrf
              <input type="hidden" name="target" value="{{ $a->id }}">
              <button type="submit" class="adm" title="{{ $a->email }}">
                <span>{{ $a->name }}<small>{{ $a->email }}</small></span>
                <b>{{ $L('Enter', 'Entrar') }} →</b>
              </button>
            </form>
          @empty
            <div class="none">{{ $L('No active admin login.', 'Sin un admin activo.') }}</div>
          @endforelse
        </div>
      @endforeach
    </div>

    <div class="foot">
      <span>{{ $user->email }}</span>
      <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">{{ $L('Sign out', 'Cerrar sesión') }}</button></form>
    </div>
  </div>

  <script>
    // Shown again from the browser's history (Back) -> its token is old: reload it.
    window.addEventListener('pageshow', function (e) { if (e.persisted) window.location.reload(); });
    (function () {
      var s = document.getElementById('ch-search');
      if (!s) return;
      s.addEventListener('input', function () {
        var q = s.value.trim().toLowerCase();
        document.querySelectorAll('#ch-list .co').forEach(function (el) {
          el.style.display = !q || el.dataset.search.indexOf(q) !== -1 ? '' : 'none';
        });
      });
    })();
  </script>
</body>
</html>
