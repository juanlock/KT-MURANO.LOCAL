@extends('layouts.store')

@section('content')
<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6">
    <h1 class="section-title">{{ __('messages.ResetPassword') }}</h1>
  </div>
</section>

<div class="container py-10">
  <div class="max-w-md mx-auto space-y-3">

    @if ($errors->any())
      <div class="alert alert-danger">
        <ul class="m-0 list-disc ps-5">
          @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
      </div>
    @endif

    <div class="card">
      <div class="card-body p-6 md:p-8">
        <div class="text-center mb-6">
          <div class="inline-flex w-14 h-14 rounded-full items-center justify-center text-accent-500 mb-3"
               style="background: rgb(var(--color-accent-500) / .1);">
            <x-store.icon name="shield-check" class="w-7 h-7" />
          </div>
          <h2 class="text-xl font-bold m-0">{{ __('messages.ResetPasswordTitle') }}</h2>
        </div>

        <form method="POST" action="{{ route('store.password.update') }}" novalidate class="space-y-4">
          @csrf
          <input type="hidden" name="token" value="{{ $token }}">

          <div>
            <label class="form-label">{{ __('messages.EmailAddress') }}</label>
            <input type="email" name="email" value="{{ $email ?? old('email') }}"
                   class="input" required autocomplete="email" readonly>
          </div>

          <div>
            <label class="form-label">{{ __('messages.NewPassword') }}</label>
            <div class="flex gap-2">
              <input type="password" name="password" id="rpPass"
                     class="input flex-1" autocomplete="new-password" required>
              <button class="btn btn-outline btn-icon" type="button" onclick="rpToggle('rpPass', this)" aria-label="Show password">
                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></span>
              </button>
            </div>
            <small class="text-fg-muted text-xs mt-1 block">{{ __('messages.PasswordRequirementsHint') }}</small>
          </div>

          <div>
            <label class="form-label">{{ __('messages.ConfirmPassword') }}</label>
            <input type="password" name="password_confirmation" class="input" autocomplete="new-password" required>
          </div>

          <button class="btn btn-primary btn-block btn-lg" type="submit">
            <x-store.icon name="shield-check" class="w-5 h-5" />{{ __('messages.ResetPassword') }}
          </button>
        </form>

        <div class="text-center mt-5 text-sm text-fg-muted">
          <a href="{{ route('store.login.show') }}" class="text-accent-500 hover:underline font-medium">{{ __('messages.BackToSignIn') }}</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  function rpToggle(id, btn){
    var el = document.getElementById(id);
    if (!el) return;
    el.type = el.type === 'password' ? 'text' : 'password';
  }
</script>
@endsection
