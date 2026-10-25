{{-- Reset password (from the emailed link): same look as the login (auth/partials/theme), English / Spanish. --}}
@php
  $es = app()->getLocale() === 'es';
  $L = fn ($en, $esText) => $es ? $esText : $en;
  $favicon = $app_settings->favicon ?? 'favicon.ico';
  $eye = '<svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex">
    <link rel="icon" href="{{ str_contains($favicon, '/') ? asset($favicon) : asset('images/'.$favicon) }}">
    <title>{{ $L('Reset password', 'Cambiar contraseña') }} · {{ $app_settings->app_name ?? 'KcolTech - Murano' }}</title>
    @include('auth.partials.theme')
    <style>
      .kt-eye { flex: none; border: 0; background: none; padding: 0 .9rem; cursor: pointer; display: flex; align-items: center; }
      .kt-eye svg { margin: 0; }
    </style>
  </head>
  <body class="kt-auth">
    <main class="kt-page">
      <div class="kt-card">
        <header class="kt-head">
          <img class="kt-mark" src="{{ asset('images/login/kcoltech-mark.png') }}" alt="KCOLTECH">
          <h1 class="kt-title">{{ $L('Reset password', 'Cambiar contraseña') }}</h1>
          <p class="kt-sub">{{ $L('Choose a new password for your account.', 'Elija una nueva contraseña para su cuenta.') }}</p>
        </header>

        @if ($errors->any())
          <div class="kt-alert error"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="kt-form" novalidate>
          @csrf
          <input type="hidden" name="token" value="{{ $token }}" />

          <label class="kt-label" for="email">{{ $L('Email address', 'Correo electrónico') }}</label>
          <div class="kt-field">
            <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            <input id="email" type="email" name="email" class="kt-input" value="{{ old('email', request('email')) }}" placeholder="{{ $L('Email address', 'Correo electrónico') }}" required autocomplete="email" />
          </div>

          <label class="kt-label" for="password">{{ $L('New password', 'Nueva contraseña') }}</label>
          <div class="kt-field">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input id="password" type="password" name="password" class="kt-input" placeholder="{{ $L('New password', 'Nueva contraseña') }}" required autocomplete="new-password" />
            <button type="button" class="kt-eye" data-target="password" aria-label="{{ $L('Show password', 'Mostrar contraseña') }}">{!! $eye !!}</button>
          </div>

          <label class="kt-label" for="password-confirm">{{ $L('Confirm password', 'Confirmar contraseña') }}</label>
          <div class="kt-field">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input id="password-confirm" type="password" name="password_confirmation" class="kt-input" placeholder="{{ $L('Confirm password', 'Confirmar contraseña') }}" required autocomplete="new-password" />
            <button type="button" class="kt-eye" data-target="password-confirm" aria-label="{{ $L('Show password', 'Mostrar contraseña') }}">{!! $eye !!}</button>
          </div>

          <button type="submit" class="kt-btn">{{ $L('Save new password', 'Guardar contraseña') }}
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </button>
        </form>

        <div class="kt-center"><a class="kt-link" href="{{ route('login') }}">← {{ $L('Back to sign in', 'Volver a ingresar') }}</a></div>
        <div class="kt-foot">© {{ date('Y') }} KCOLTECH. {{ $L('All rights reserved.', 'Todos los derechos reservados.') }}</div>
      </div>
    </main>
    <script>
      document.querySelectorAll('.kt-eye').forEach(function (b) {
        b.addEventListener('click', function () {
          var i = document.getElementById(b.dataset.target);
          i.type = i.type === 'password' ? 'text' : 'password';
        });
      });
    </script>
  </body>
</html>
