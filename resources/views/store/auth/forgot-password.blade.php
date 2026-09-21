@extends('layouts.store')

@section('content')
<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6">
    <h1 class="section-title">{{ __('messages.ForgotPassword') }}</h1>
  </div>
</section>

<div class="container py-10">
  <div class="max-w-md mx-auto space-y-3">

    @if(session('reset_link_sent'))
      <div class="alert alert-success flex items-start gap-2">
        <x-store.icon name="mail" class="w-5 h-5 shrink-0 mt-0.5" />
        <div>{{ __('messages.ResetLinkSent') }}</div>
      </div>
    @endif

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
            <x-store.icon name="mail" class="w-7 h-7" />
          </div>
          <h2 class="text-xl font-bold m-0">{{ __('messages.ForgotPasswordTitle') }}</h2>
          <div class="text-fg-muted text-sm mt-1">{{ __('messages.ForgotPasswordHelp') }}</div>
        </div>

        <form method="POST" action="{{ route('store.password.email') }}" novalidate class="space-y-4">
          @csrf
          <div>
            <label class="form-label">{{ __('messages.EmailAddress') }}</label>
            <input type="email" name="email" value="{{ old('email') }}"
                   class="input" required autocomplete="email" placeholder="{{ __('messages.EmailPlaceholder') }}">
          </div>
          <button class="btn btn-primary btn-block btn-lg" type="submit">
            <x-store.icon name="mail" class="w-5 h-5" />{{ __('messages.SendResetLink') }}
          </button>
        </form>

        <div class="text-center mt-5 text-sm text-fg-muted">
          <a href="{{ route('store.login.show') }}" class="text-accent-500 hover:underline font-medium">{{ __('messages.BackToSignIn') }}</a>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
