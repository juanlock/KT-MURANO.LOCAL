{{-- Forgot password: same look as the login (auth/partials/theme), English / Spanish. --}}
@php
  $es = app()->getLocale() === 'es';
  $L = fn ($en, $esText) => $es ? $esText : $en;
  $favicon = $app_settings->favicon ?? 'favicon.ico';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex">
    <link rel="icon" href="{{ str_contains($favicon, '/') ? asset($favicon) : asset('images/'.$favicon) }}">
    <title>{{ $L('Forgot your password?', '¿Olvidó su contraseña?') }} · {{ $app_settings->app_name ?? 'KcolTech - Murano' }}</title>
    @include('auth.partials.theme')
  </head>
  <body class="kt-auth">
    <main class="kt-page">
      <div class="kt-card">
        <header class="kt-head">
          <img class="kt-mark" src="{{ asset('images/login/kcoltech-mark.png') }}" alt="KCOLTECH">
          <h1 class="kt-title">{{ $L('Forgot your password?', '¿Olvidó su contraseña?') }}</h1>
          <p class="kt-sub">{{ $L('Enter your email and we will send you a link to reset it.', 'Escriba su correo y le enviaremos un enlace para cambiarla.') }}</p>
        </header>

        @if (session('status'))
          <div class="kt-alert success" role="alert">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
          <div class="kt-alert error"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="kt-form" novalidate>
          @csrf
          <label class="kt-label" for="email">{{ $L('Email address', 'Correo electrónico') }}</label>
          <div class="kt-field">
            <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            <input id="email" type="email" name="email" class="kt-input" value="{{ old('email') }}" placeholder="{{ $L('Email address', 'Correo electrónico') }}" required autocomplete="email" autofocus />
          </div>
          <button type="submit" class="kt-btn">{{ $L('Send reset link', 'Enviar enlace') }}
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </button>
        </form>

        <div class="kt-center"><a class="kt-link" href="{{ route('login') }}">← {{ $L('Back to sign in', 'Volver a ingresar') }}</a></div>
        <div class="kt-foot">© {{ date('Y') }} KCOLTECH. {{ $L('All rights reserved.', 'Todos los derechos reservados.') }}</div>
      </div>
    </main>
  </body>
</html>
