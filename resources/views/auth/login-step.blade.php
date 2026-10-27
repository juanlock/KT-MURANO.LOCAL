{{--
  Login security (2026-10-27) -- LoginStepsController: the steps after the right
  password, before the session starts. mode = verify (two-step code: email or the
  authenticator app / a recovery code) | password (a new password instead of a
  standard one, App\Support\PasswordPolicy). Same look as the login chooser.
--}}
@php
  $es = app()->getLocale() === 'es';
  $L = fn ($en, $esText) => $es ? $esText : $en;
  $bgImage = $app_settings->login_bg_image ?? null;
  $bgImageUrl = $bgImage ? (str_contains($bgImage, '/') ? asset($bgImage) : asset('images/'.$bgImage)) : null;
  $firstName = trim($user->firstname ?? '') ?: ($user->username ?? '');
  $title = $mode === 'verify' ? $L('Two-step verification', 'Verificación en dos pasos') : $L('Choose a new password', 'Elija una contraseña nueva');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{{ $title }} · {{ $app_settings->app_name ?? 'Murano' }}</title>
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
    .overlay { position: fixed; inset: 0; overflow: hidden; pointer-events: none; }
    .overlay::before, .overlay::after { content: ''; position: absolute; border-radius: 50%; }
    .overlay::before { width: 140vw; height: 70vw; left: -35vw; bottom: -52vw; background: radial-gradient(closest-side, rgba(255,255,255,.85), rgba(255,255,255,0)); }
    .overlay::after { width: 60vw; height: 60vw; right: -26vw; top: -30vw; background: radial-gradient(closest-side, rgba(150,200,248,.55), rgba(150,200,248,0)); }
    .modal {
      position: relative; width: 100%; max-width: 460px; background: #fff; border: 1px solid rgba(219,229,239,.8); border-radius: 18px; overflow: hidden;
      box-shadow: 0 30px 70px -25px rgba(16,64,128,.25), 0 8px 24px -12px rgba(16,64,128,.12);
    }
    .head { padding: 26px 28px 8px; display: flex; align-items: center; gap: 14px; }
    .head img { height: 46px; width: auto; flex: none; }
    .head h1 { margin: 0; font-size: 1.35rem; font-weight: 800; color: var(--navy); letter-spacing: -.02em; }
    .head p { margin: 4px 0 0; color: var(--muted); font-size: .92rem; }
    .body { padding: 10px 28px 22px; }
    .lead { color: var(--muted); margin: 6px 0 16px; line-height: 1.5; }
    label { display: block; font-weight: 600; font-size: .9rem; margin: 12px 0 6px; }
    input[type=text], input[type=password] { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; font: inherit; }
    input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px #dbe8f8; }
    .code { font-size: 1.6rem; letter-spacing: .5em; text-align: center; font-weight: 700; }
    .check { display: flex; align-items: center; gap: 8px; margin: 14px 0 0; font-weight: 500; color: var(--muted); }
    .btn { width: 100%; margin-top: 18px; padding: 12px; border: 0; border-radius: 10px; background: var(--primary); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
    .btn:hover { background: var(--primary-hover); }
    .links { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; font-size: .88rem; }
    .links button { background: none; border: 0; padding: 0; color: var(--primary); cursor: pointer; font: inherit; text-decoration: underline; }
    .links .muted { color: var(--muted); }
    .err, .info { margin: 0 0 12px; padding: 10px 14px; border-radius: 10px; font-size: .9rem; }
    .err { background: #fff1f0; border: 1px solid #ffccc7; color: #a8071a; }
    .info { background: #f0f7ff; border: 1px solid #bcd8fb; color: #0b4fa0; }
    .rules { margin: 12px 0 0; padding-left: 18px; color: var(--muted); font-size: .85rem; line-height: 1.6; }
    form { margin: 0; }
  </style>
</head>
<body>
  <div class="overlay"></div>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="st-title">
    <div class="head">
      <img src="{{ asset('images/login/kcoltech-mark.png') }}" alt="KCOLTECH">
      <div>
        <h1 id="st-title">{{ $title }}</h1>
        <p>{{ $L('Hi', 'Hola') }}, {{ $firstName }}</p>
      </div>
    </div>
    <div class="body">
      @if ($errors->any())
        <div class="err" role="alert">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
      @endif
      @if (session('step_error'))<div class="err" role="alert">{{ session('step_error') }}</div>@endif
      @if (session('step_info'))<div class="info" role="status">{{ session('step_info') }}</div>@endif

      @if ($mode === 'verify')
        <p class="lead">
          @if ($method === 'app')
            {{ $L('Enter the 6-digit code of your authenticator app (or one of your recovery codes).', 'Escriba el código de 6 dígitos de su app autenticadora (o uno de sus códigos de recuperación).') }}
          @else
            {{ $L('We emailed a 6-digit code to', 'Le enviamos un código de 6 dígitos a') }} <b>{{ $masked }}</b>. {{ $L('It expires in 10 minutes.', 'Vence en 10 minutos.') }}
          @endif
        </p>
        <form method="POST" action="{{ route('login.verify') }}">
          @csrf
          <label for="code">{{ $L('Code', 'Código') }}</label>
          <input id="code" name="code" type="text" class="{{ $method === 'app' ? '' : 'code' }}" inputmode="{{ $method === 'app' ? 'text' : 'numeric' }}"
            autocomplete="one-time-code" maxlength="{{ $method === 'app' ? 12 : 6 }}" required autofocus>
          <label class="check"><input type="checkbox" name="remember_device" value="1"> {{ $L('Remember this computer for 30 days', 'Recordar este equipo por 30 días') }}</label>
          <button type="submit" class="btn">{{ $L('Verify and enter', 'Verificar y entrar') }}</button>
        </form>
        <div class="links">
          @if ($method === 'email')
            <form method="POST" action="{{ route('login.verify.resend') }}">@csrf<button type="submit">{{ $L('Send a new code', 'Enviar otro código') }}</button></form>
          @else
            <span></span>
          @endif
          <form method="POST" action="{{ route('login.cancel') }}">@csrf<button type="submit" class="muted">{{ $L('Cancel', 'Cancelar') }}</button></form>
        </div>
      @else
        <p class="lead">{{ $L('You signed in with a standard password. For your security, choose your own before entering.', 'Ingresó con una contraseña estándar. Por su seguridad, elija una propia antes de entrar.') }}</p>
        <form method="POST" action="{{ route('login.password') }}">
          @csrf
          <label for="password">{{ $L('New password', 'Contraseña nueva') }}</label>
          <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
          <label for="password_confirmation">{{ $L('Repeat it', 'Repítala') }}</label>
          <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
          <ul class="rules">
            <li>{{ $L('At least 8 characters, with letters and numbers', 'Al menos 8 caracteres, con letras y números') }}</li>
            <li>{{ $L('Not a standard password, not your email', 'Que no sea una contraseña estándar ni su email') }}</li>
            <li>{{ $L('Not one of your last 3 passwords', 'Que no sea una de sus 3 últimas contraseñas') }}</li>
          </ul>
          <button type="submit" class="btn">{{ $L('Save and enter', 'Guardar y entrar') }}</button>
        </form>
        <div class="links">
          <span></span>
          <form method="POST" action="{{ route('login.cancel') }}">@csrf<button type="submit" class="muted">{{ $L('Cancel', 'Cancelar') }}</button></form>
        </div>
      @endif
    </div>
  </div>
  <script>
    // Shown again from the browser's history (Back) -> its token is old: reload it.
    window.addEventListener('pageshow', function (e) { if (e.persisted) window.location.reload(); });
  </script>
</body>
</html>
