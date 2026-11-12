@php
    $t = fn ($en, $sp) => $es ? $sp : $en;
    $messages = [
        'other_device' => $t('This link was already opened on another phone or computer, and it only works there.', 'Este enlace ya se abrió en otro celular o computadora, y solo funciona ahí.'),
        'expired' => $t('This link expired because it was not opened in time.', 'Este enlace venció porque no se abrió a tiempo.'),
        'revoked' => $t('This link is no longer valid: a new one was made or it was removed.', 'Este enlace ya no es válido: se creó uno nuevo o se quitó.'),
        'disabled' => $t('This login is disabled.', 'Este usuario está desactivado.'),
        'locked' => $t('This login is locked for a while. Try again later.', 'Este usuario está bloqueado por un momento. Intente más tarde.'),
        'not_allowed' => $t('This kind of login cannot enter with a link.', 'Este tipo de usuario no puede entrar con un enlace.'),
        'unknown' => $t('This link does not exist.', 'Este enlace no existe.'),
    ];
@endphp
<!doctype html>
<html lang="{{ $es ? 'es' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $t('Link not valid', 'Enlace no válido') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f4f6f8; color: #1f2328;
               font: 17px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding: 16px; box-sizing: border-box; }
        .box { background: #fff; border-radius: 14px; padding: 28px 24px; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
        .icon { font-size: 44px; line-height: 1; margin-bottom: 10px; }
        h1 { font-size: 21px; margin: 0 0 10px; }
        p { margin: 0 0 12px; }
        .help { font-size: 15px; color: #57606a; }
        a.btn { display: inline-block; margin-top: 6px; padding: 10px 18px; border-radius: 8px; background: #1677ff; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon">🔒</div>
        <h1>{{ $t('This link does not work here', 'Este enlace no funciona aquí') }}</h1>
        <p>{{ $messages[$reason] ?? $messages['unknown'] }}</p>
        <p class="help">{{ $t('Ask your administrator for a new link.', 'Pida a su administrador un enlace nuevo.') }}</p>
        <a class="btn" href="{{ url('/login') }}">{{ $t('Sign in with password', 'Entrar con contraseña') }}</a>
    </div>
</body>
</html>
