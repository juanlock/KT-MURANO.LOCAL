@extends('layouts.store')

@section('content')
@php
  /** @var \App\Models\EcommerceClient|null $me */
  $me = Auth::guard('store')->user();
  $updateUrl = route('account.update');
  $ordersUrl = route('account.orders');
@endphp

<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6 flex items-center justify-between flex-wrap gap-3">
    <div>
      <span class="section-kicker">{{ __('messages.Account') }}</span>
      <h1 class="section-title mt-1">{{ __('messages.MyAccount') }}</h1>
      <div class="text-fg-muted text-sm mt-1">{{ __('messages.ManageProfileAndOrders') }}</div>
    </div>
    <a href="{{ $ordersUrl }}" class="btn btn-outline">
      <x-store.icon name="receipt" class="w-4 h-4" />{{ __('messages.MyOrders') }}
    </a>
  </div>
</section>

<div class="container py-8">
  <div class="account-layout">
    @include('store.partials.account-nav')
    <div class="space-y-4">

    @if (session('status'))
      <div class="alert alert-success flex items-start gap-2">
        <x-store.icon name="check-circle" class="w-5 h-5 mt-0.5 shrink-0" />
        <span>{{ session('status') }}</span>
      </div>
    @endif
    @if ($errors->any())
      <div class="alert alert-danger">
        <ul class="m-0 ps-5 list-disc">
          @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
      </div>
    @endif

    {{-- Profile --}}
    <div class="card" id="account-details">
      <div class="card-body">
        <h5 class="font-semibold mb-4 flex items-center gap-2">
          <x-store.icon name="user" class="w-5 h-5 text-accent-500" />{{ __('messages.Profile') }}
        </h5>

        @if(!$me)
          <div class="alert alert-warning m-0">
            {{ __('messages.MustBeSignedIn') }}
          </div>
        @else
          <form method="POST" action="{{ $updateUrl }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div class="grid md:grid-cols-2 gap-4">
              <div>
                <label class="form-label">{{ __('messages.Username') }}</label>
                <input name="username" type="text" class="input" value="{{ old('username', $me->username) }}">
              </div>
              <div>
                <label class="form-label">{{ __('messages.Email') }}</label>
                <input name="email" type="email" class="input" value="{{ old('email', $me->email) }}">
              </div>
              <div>
                <label class="form-label">{{ __('messages.NewPassword') }}</label>
                <input name="password" type="password" class="input" autocomplete="new-password" placeholder="••••••••">
              </div>
              <div>
                <label class="form-label">{{ __('messages.ConfirmPassword') }}</label>
                <input name="password_confirmation" type="password" class="input" autocomplete="new-password" placeholder="••••••••">
              </div>
            </div>

            <div class="flex gap-2 flex-wrap pt-2">
              <button class="btn btn-primary" type="submit">
                <x-store.icon name="check" class="w-4 h-4" />{{ __('messages.SaveChanges') }}
              </button>
              <a href="{{ $ordersUrl }}" class="btn btn-outline">
                <x-store.icon name="receipt" class="w-4 h-4" />{{ __('messages.ViewOrders') }}
              </a>
            </div>
          </form>
        @endif
      </div>
    </div>

    {{-- Preferences: the customer's default language / display currency. --}}
    @if($me)
      @php
        $prefLocales = store_locales();
        $prefCurrencies = \App\Services\StoreCurrencyService::options();
      @endphp
      <div class="card" id="preferences">
        <div class="card-body">
          <h5 class="font-semibold mb-4 flex items-center gap-2">
            <x-store.icon name="globe" class="w-5 h-5 text-accent-500" />{{ __('messages.Preferences') }}
          </h5>
          <p class="text-sm text-fg-muted -mt-2 mb-4">{{ __('messages.PreferencesHint') }}</p>

          <form method="POST" action="{{ route('account.preferences.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div class="grid md:grid-cols-2 gap-4">
              <div>
                <label class="form-label" for="pref-locale">{{ __('messages.PreferredLanguage') }}</label>
                <select name="preferred_locale" id="pref-locale" class="input">
                  <option value="">{{ __('messages.UseSiteDefault') }}</option>
                  @foreach($prefLocales as $code => $label)
                    <option value="{{ $code }}" @selected(old('preferred_locale', $me->preferred_locale) === $code)>{{ $label }}</option>
                  @endforeach
                </select>
              </div>

              @if($prefCurrencies->count() > 1)
                <div>
                  <label class="form-label" for="pref-currency">{{ __('messages.PreferredCurrency') }}</label>
                  <select name="preferred_currency_id" id="pref-currency" class="input">
                    <option value="">{{ __('messages.UseSiteDefault') }}</option>
                    @foreach($prefCurrencies as $cur)
                      <option value="{{ $cur->id }}" @selected((int) old('preferred_currency_id', $me->preferred_currency_id) === (int) $cur->id)>
                        {{ $cur->code }} — {{ $cur->symbol }}
                      </option>
                    @endforeach
                  </select>
                </div>
              @endif
            </div>

            <div class="flex gap-2 flex-wrap pt-2">
              <button class="btn btn-primary" type="submit">
                <x-store.icon name="check" class="w-4 h-4" />{{ __('messages.SaveChanges') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    @endif

    {{-- Address --}}
    @if($me)
      <div class="card" id="address">
        <div class="card-body">
          <h5 class="font-semibold mb-4 flex items-center gap-2">
            <x-store.icon name="map-pin" class="w-5 h-5 text-accent-500" />{{ __('messages.Address') }}
          </h5>
          <form method="POST" action="{{ route('account.address.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div class="grid md:grid-cols-2 gap-4">
              <div>
                <label class="form-label">{{ __('messages.FullName') }}</label>
                <input name="name" type="text" class="input" value="{{ old('name', optional($client)->name) }}">
              </div>
              <div>
                <label class="form-label">{{ __('messages.Phone') }}</label>
                <input name="phone" type="text" class="input" value="{{ old('phone', optional($client)->phone) }}">
              </div>
              <div class="md:col-span-2">
                <label class="form-label">{{ __('messages.Address') }}</label>
                <input name="adresse" type="text" class="input" value="{{ old('adresse', optional($client)->adresse) }}">
              </div>
              <div>
                <label class="form-label">{{ __('messages.City') }}</label>
                <input name="city" type="text" class="input" value="{{ old('city', optional($client)->city) }}">
              </div>
              <div>
                <label class="form-label">{{ __('messages.StateProvince') }}</label>
                <input name="state" type="text" class="input" value="{{ old('state', optional($client)->state) }}">
              </div>
              <div>
                <label class="form-label">{{ __('messages.ZipPostal') }}</label>
                <input name="zip" type="text" class="input" value="{{ old('zip', optional($client)->zip) }}">
              </div>
              <div>
                <label class="form-label" for="acc-country">{{ __('messages.Country') }}</label>
                @php
                  $accCountryOptions = \App\Services\CountryService::options();
                  $accCountryCode = \App\Services\CountryService::toCode(old('country', optional($client)->country));
                @endphp
                <select name="country" id="acc-country" class="input">
                  <option value="">{{ __('messages.SelectCountry') }}</option>
                  @foreach($accCountryOptions as $co)
                    <option value="{{ $co['canonical'] }}" @selected($accCountryCode === $co['code'])>{{ $co['name'] }}</option>
                  @endforeach
                </select>
              </div>
            </div>

            <div class="pt-2">
              <button class="btn btn-primary" type="submit">
                <x-store.icon name="check" class="w-4 h-4" />{{ __('messages.SaveAddress') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    @endif

    {{-- Account meta --}}
    @if($me)
      <div class="card">
        <div class="card-body">
          <h6 class="font-semibold mb-4 flex items-center gap-2">
            <x-store.icon name="info" class="w-4 h-4 text-accent-500" />{{ __('messages.AccountDetails') }}
          </h6>
          <div class="grid md:grid-cols-2 gap-4 text-sm">
            <div>
              <div class="text-fg-muted text-xs">{{ __('messages.ClientID') }}</div>
              <div class="font-semibold mt-0.5">{{ $me->client_id ?? '—' }}</div>
            </div>
            <div>
              <div class="text-fg-muted text-xs">{{ __('messages.Status') }}</div>
              <div class="mt-0.5">
                @if((int)$me->status === 1)
                  <span class="chip chip-success">{{ __('messages.Active') }}</span>
                @else
                  <span class="chip">{{ __('messages.Inactive') }}</span>
                @endif
              </div>
            </div>
            <div>
              <div class="text-fg-muted text-xs">{{ __('messages.CreatedAt') }}</div>
              <div class="font-semibold mt-0.5">{{ optional($me->created_at)->toDateTimeString() ?? '—' }}</div>
            </div>
            <div>
              <div class="text-fg-muted text-xs">{{ __('messages.UpdatedAt') }}</div>
              <div class="font-semibold mt-0.5">{{ optional($me->updated_at)->toDateTimeString() ?? '—' }}</div>
            </div>
          </div>
        </div>
      </div>
    @endif

    </div>
  </div>
</div>
@endsection
