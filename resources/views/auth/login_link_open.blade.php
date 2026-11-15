@php $t = fn ($en, $sp) => $es ? $sp : $en; @endphp
<!doctype html>
<html lang="{{ $es ? 'es' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $t('Sign in', 'Entrar') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f4f6f8; color: #1f2328;
               font: 17px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding: 16px; box-sizing: border-box; }
        .box { background: #fff; border-radius: 14px; padding: 28px 24px; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
        h1 { font-size: 21px; margin: 0 0 14px; }
        button { width: 100%; padding: 14px 18px; border: 0; border-radius: 10px; background: #1677ff; color: #fff; font-size: 18px; cursor: pointer; }
        .help { font-size: 14px; color: #57606a; margin: 12px 0 0; }
    </style>
</head>
<body>
    {{-- The first opening of a sign-in link (2026-11-14): it claims the link only with a POST from a real browser. WhatsApp's
         link preview on the iPhone fetched the link first (as "Safari on Mac") and took it; previews don't run scripts or post. --}}
    <form class="box" id="enter" method="post" action="{{ url('acceso/'.$code) }}">
        @csrf
        <h1>{{ $t('Signing you in…', 'Entrando…') }}</h1>
        <button type="submit">{{ $t('Enter', 'Entrar') }}</button>
        <p class="help">{{ $t('This link will work only on this phone.', 'Este enlace funcionará solo en este celular.') }}</p>
    </form>
    <script>document.getElementById('enter').submit();</script>
</body>
</html>
