{{-- Helpdesk public pages: standalone layout (inline CSS, no build step). --}}
@php $rtl = app()->getLocale() === 'ar'; @endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  {{-- The private ticket link is the page URL: never leak it through Referer. --}}
  <meta name="referrer" content="no-referrer">
  <title>@yield('title') · {{ $brand['name'] }}</title>
  <style>
    :root { --bg:#f4f6fa; --card:#fff; --fg:#1f2937; --muted:#6b7280; --line:#e5e7eb; --accent:#2563eb; --accent-fg:#fff;
            --ok-bg:#ecfdf5; --ok-fg:#065f46; --err-bg:#fef2f2; --err-fg:#991b1b; --agent:#eff6ff; --me:#f9fafb; }
    @media (prefers-color-scheme: dark) {
      :root { --bg:#0f172a; --card:#111827; --fg:#e5e7eb; --muted:#9ca3af; --line:#1f2937; --accent:#3b82f6;
              --ok-bg:#052e24; --ok-fg:#6ee7b7; --err-bg:#3b0d0d; --err-fg:#fca5a5; --agent:#0b1a33; --me:#161e2e; }
    }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--bg); color:var(--fg); font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
    a { color:var(--accent); }
    .wrap { max-width:760px; margin:0 auto; padding:24px 16px 48px; }
    .brand { display:flex; align-items:center; gap:12px; margin-bottom:20px; }
    .brand img { max-height:44px; max-width:200px; object-fit:contain; }
    .brand .mark { width:40px; height:40px; border-radius:10px; background:var(--accent); color:var(--accent-fg); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:18px; }
    .brand .name { font-weight:600; font-size:17px; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:16px; }
    h1 { font-size:22px; margin:0 0 4px; }
    .sub { color:var(--muted); margin:0 0 18px; font-size:14px; }
    label { display:block; font-weight:600; font-size:14px; margin:0 0 6px; }
    .field { margin-bottom:14px; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:560px) { .row { grid-template-columns:1fr; } }
    input[type=text], input[type=email], select, textarea { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px;
      background:var(--card); color:var(--fg); font:inherit; }
    textarea { min-height:140px; resize:vertical; }
    input:focus, select:focus, textarea:focus { outline:2px solid var(--accent); outline-offset:-1px; }
    input[type=file] { font-size:14px; max-width:100%; }
    .hint { color:var(--muted); font-size:12.5px; margin-top:4px; }
    .btn { display:inline-block; background:var(--accent); color:var(--accent-fg); border:0; border-radius:8px; padding:10px 18px; font:600 15px/1.2 inherit; font-family:inherit; cursor:pointer; text-decoration:none; }
    .btn:disabled { opacity:.6; cursor:default; }
    .alert { border-radius:8px; padding:12px 14px; margin-bottom:16px; font-size:14px; }
    .alert ul { margin:0; padding-inline-start:18px; }
    .alert-ok { background:var(--ok-bg); color:var(--ok-fg); }
    .alert-err { background:var(--err-bg); color:var(--err-fg); }
    .hp { position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden; }
    .meta { display:flex; flex-wrap:wrap; gap:8px 16px; color:var(--muted); font-size:13.5px; }
    .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12.5px; font-weight:600; background:var(--line); color:var(--fg); }
    .badge-open { background:#dbeafe; color:#1e40af; } .badge-pending { background:#fef3c7; color:#92400e; }
    .badge-resolved { background:#d1fae5; color:#065f46; } .badge-closed { background:#e5e7eb; color:#374151; }
    .msg { border:1px solid var(--line); border-radius:10px; padding:12px 14px; margin-bottom:12px; background:var(--me); }
    .msg.agent { background:var(--agent); }
    .msg-head { display:flex; justify-content:space-between; flex-wrap:wrap; gap:4px 12px; font-size:13px; color:var(--muted); margin-bottom:6px; }
    .msg-head strong { color:var(--fg); }
    .msg-body { white-space:normal; overflow-wrap:anywhere; }
    .files { margin:8px 0 0; padding:0; list-style:none; font-size:13.5px; }
    .files li { margin-top:4px; }
    footer { color:var(--muted); font-size:12.5px; text-align:center; margin-top:24px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="brand">
      @if(!empty($brand['logo']))
        <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">
      @else
        <span class="mark">{{ mb_strtoupper(mb_substr($brand['name'], 0, 1)) }}</span>
        <span class="name">{{ $brand['name'] }}</span>
      @endif
    </div>

    @if(session('support_notice'))
      <div class="alert alert-ok" role="status">{{ session('support_notice') }}</div>
    @endif
    @if($errors->any())
      <div class="alert alert-err" role="alert">
        <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
      </div>
    @endif

    @yield('content')

    <footer>{{ $brand['name'] }} · {{ __('messages.SupportTickets') }}</footer>
  </div>
</body>
</html>
