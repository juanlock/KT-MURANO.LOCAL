@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  use App\Models\StoreSetting;

  $s = $s ?? StoreSetting::first();
  $u = auth('store')->user();
  $client = $u ? $u->client : null;
  // Cards need the publishable key AND the admin switch (kept apart from the
  // stored keys so turning cards off does not delete the credentials). Switched
  // off hides the option outright; configured-but-keyless keeps the existing
  // greyed-out row so the admin notices.
  $cardsEnabled = StoreSetting::stripeEnabled();
  $stripeKey = $cardsEnabled ? config('services.stripe.key') : null;

  // Country is a dropdown, not free text: shipping regions and tax rates are
  // matched by country, and a typo used to read as "no shipping available".
  $countryOptions = \App\Services\CountryService::options();
  $clientCountryCode = \App\Services\CountryService::toCode($client->country ?? null);

  // PayPal is offered when enabled by the admin AND both credentials are set.
  $paypalEnabled = $s
      && (bool) ($s->paypal_enabled ?? false)
      && trim((string) ($s->paypal_client_id ?? '')) !== ''
      && trim((string) ($s->paypal_client_secret ?? '')) !== '';

  // Paystack: same rule (test vs live is decided by the key pair).
  $paystackEnabled = $s
      && (bool) ($s->paystack_enabled ?? false)
      && trim((string) ($s->paystack_public_key ?? '')) !== ''
      && trim((string) ($s->paystack_secret_key ?? '')) !== '';

  // Flutterwave: same rule (v3 classic keys, test/live per key prefix).
  $flutterwaveEnabled = $s
      && (bool) ($s->flutterwave_enabled ?? false)
      && trim((string) ($s->flutterwave_public_key ?? '')) !== ''
      && trim((string) ($s->flutterwave_secret_key ?? '')) !== '';

  // Razorpay: same rule (test/live per rzp_test_/rzp_live_ key prefix).
  $razorpayEnabled = $s
      && (bool) ($s->razorpay_enabled ?? false)
      && trim((string) ($s->razorpay_key_id ?? '')) !== ''
      && trim((string) ($s->razorpay_key_secret ?? '')) !== '';

  // bKash: enabled + all four Tokenized Checkout credentials, AND the active
  // store currency is BDT — bKash charges BDT only (server enforces the same).
  $bkashEnabled = $s
      && (bool) ($s->bkash_enabled ?? false)
      && trim((string) ($s->bkash_app_key ?? '')) !== ''
      && trim((string) ($s->bkash_app_secret ?? '')) !== ''
      && trim((string) ($s->bkash_username ?? '')) !== ''
      && trim((string) ($s->bkash_password ?? '')) !== ''
      && strtoupper((string) (\App\Services\StoreCurrencyService::active()['code'] ?? '')) === 'BDT';

  // SSLCommerz: same enabled-plus-credentials rule as the other gateways.
  $sslcommerzEnabled = $s
      && (bool) ($s->sslcommerz_enabled ?? false)
      && trim((string) ($s->sslcommerz_store_id ?? '')) !== ''
      && trim((string) ($s->sslcommerz_store_password ?? '')) !== '';

  $walletEnabled = (bool) optional($s)->wallet_enabled;
  $walletBalance = ($walletEnabled && $client)
      ? (float) (\App\Models\Wallet::where('client_id', $client->id)->value('balance') ?? 0)
      : 0.0;
  $walletUsable = $walletEnabled && ($walletBalance > 0 || optional($s)->wallet_allow_negative);

  // Admin on/off for the checkout payment methods (default on when unset).
  $codEnabled = $s === null ? true : (bool) ($s->payment_cod_enabled ?? true);
  $mobileMoneyEnabled = $s === null ? true : (bool) ($s->payment_mobile_money_enabled ?? true);

  // Manual/offline methods (GCash, bank transfer, cash on pickup) with the
  // account details the shopper pays to.
  $manualMethods = \App\Services\StorePaymentMethodService::enabled($s);
  $gcash = $manualMethods['gcash'] ?? null;
  $bankTransfer = $manualMethods['bank_transfer'] ?? null;

  // Cash on pickup needs at least one branch open for collection.
  $pickupBranches = isset($manualMethods['cash_on_pickup'])
      ? \App\Models\StorePickupBranch::selectable()
      : collect();
  $pickupEnabled = $pickupBranches->isNotEmpty();
  $pickupInstructions = $manualMethods['cash_on_pickup']['instructions'] ?? '';
@endphp

<section class="border-b border-line-subtle bg-bg-surface">
  <div class="container py-5 flex items-center justify-between flex-wrap gap-3">
    <div class="flex items-center gap-2 text-sm">
      <span class="font-semibold text-fg-primary">{{ __('messages.Cart') }}</span>
      <x-store.icon name="chevron-right" class="w-3.5 h-3.5 text-fg-muted rtl:rotate-180" />
      <span class="font-semibold text-accent-500">{{ __('messages.Checkout') }}</span>
    </div>
    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-success">
      <x-store.icon name="shield-check" class="w-4 h-4" />{{ __('messages.SecurePayment') }}
    </span>
  </div>
</section>

<div class="container py-8" id="checkout-app">
  <div class="grid lg:grid-cols-[minmax(0,1fr)_400px] gap-6 items-start">

    {{-- ===== Left column: address + shipping + payment ===== --}}
    <div class="space-y-6">

      @if ($client)
      <div class="card">
        <div class="card-body space-y-6">
        <div>
          <h5 class="font-semibold mb-3 flex items-center gap-2.5">
            <span class="trust-icon !w-8 !h-8 !rounded-lg"><x-store.icon name="truck" class="w-4 h-4" /></span>
            <span id="address-section-title">{{ __('messages.ShippingAddress') }}</span>
          </h5>
          <p id="pickup-address-note" class="text-xs text-fg-muted mb-3 hidden">
            {{ __('messages.PickupNoAddressNeeded') }}
          </p>
          <div class="grid md:grid-cols-2 gap-3">
            <div>
              <label class="form-label text-xs">{{ __('messages.FullName') }} *</label>
              <input type="text" id="ship-name" class="input" value="{{ $client->name }}" autocomplete="name">
            </div>
            <div>
              <label class="form-label text-xs">{{ __('messages.Phone') }} *</label>
              <input type="tel" id="ship-phone" class="input" value="{{ $client->phone }}" autocomplete="tel">
            </div>
            <div class="md:col-span-2 js-ship-only">
              <label class="form-label text-xs">{{ __('messages.Address') }} *</label>
              <input type="text" id="ship-address" class="input" value="{{ $client->adresse }}" autocomplete="street-address">
            </div>
            <div class="js-ship-only">
              <label class="form-label text-xs">{{ __('messages.City') }}</label>
              <input type="text" id="ship-city" class="input" value="{{ $client->city }}" autocomplete="address-level2">
            </div>
            <div class="js-ship-only">
              <label class="form-label text-xs" for="ship-state">{{ __('messages.State') }}</label>
              <input type="text" id="ship-state" class="input" list="ship-state-options"
                     value="{{ $client->state }}" autocomplete="address-level1">
              <datalist id="ship-state-options"></datalist>
            </div>
            <div class="js-ship-only">
              <label class="form-label text-xs">{{ __('messages.Zip') }}</label>
              <input type="text" id="ship-zip" class="input" value="{{ $client->zip }}" autocomplete="postal-code">
            </div>
            <div class="js-ship-only">
              <label class="form-label text-xs" for="ship-country">{{ __('messages.Country') }} *</label>
              <select id="ship-country" class="input" autocomplete="country">
                <option value="">{{ __('messages.SelectCountry') }}</option>
                @foreach($countryOptions as $co)
                  <option value="{{ $co['canonical'] }}" data-code="{{ $co['code'] }}"
                          @selected($clientCountryCode === $co['code'])>{{ $co['name'] }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div id="address-error" class="text-danger text-xs mt-2 hidden"></div>
        </div>

        {{-- ===== SHIPPING METHOD ===== --}}
        <div id="shipping-method-section" class="hidden">
          <h5 class="font-semibold mb-3 flex items-center gap-2.5">
            <span class="trust-icon !w-8 !h-8 !rounded-lg"><x-store.icon name="package" class="w-4 h-4" /></span>
            {{ __('messages.ShippingMethod') }}
          </h5>
          <div id="shipping-methods" class="space-y-2"></div>
          <div id="shipping-methods-empty" class="text-fg-muted text-sm hidden">{{ __('messages.NoShippingForRegion') }}</div>
        </div>
        <hr class="border-line-subtle hidden" id="shipping-method-divider" style="display:none">
        </div>
      </div>
      @endif

      {{-- ===== PAYMENT METHOD SELECTION ===== --}}
      <div class="card" id="payment-section">
        <div class="card-body">
          <h5 class="font-semibold mb-4 flex items-center gap-2.5">
            <span class="trust-icon !w-8 !h-8 !rounded-lg"><x-store.icon name="credit-card" class="w-4 h-4" /></span>
            {{ __('messages.PaymentMethod') }}
          </h5>

          <div class="pay-methods" id="payment-methods">
            {{-- Credit Card (Stripe) --}}
            @if($cardsEnabled)
            <label class="pay-option {{ $stripeKey ? '' : 'pay-option-disabled' }}" data-method="credit_card">
              <input type="radio" name="payment_method" value="credit_card" class="pay-radio" {{ $stripeKey ? '' : 'disabled' }}>
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-card"><x-store.icon name="credit-card" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">{{ __('messages.CreditCard') }}</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.PayWithStripe') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                @if($stripeKey)
                <div class="pay-body" id="stripe-card-wrapper">
                  <div id="stripe-card-element" class="stripe-card-element"></div>
                  <div id="stripe-card-errors" class="text-danger text-xs mt-2" role="alert"></div>
                </div>
                @else
                <div class="pay-body pay-body-warning">
                  <div class="alert alert-warning text-xs mb-0 flex items-start gap-2">
                    <x-store.icon name="alert" class="w-4 h-4 mt-0.5 shrink-0" />
                    <span>{{ __('messages.StripeNotConfigured') }}</span>
                  </div>
                </div>
                @endif
              </div>
            </label>
            @endif

            {{-- PayPal --}}
            @if($paypalEnabled)
            <label class="pay-option" data-method="paypal">
              <input type="radio" name="payment_method" value="paypal" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-card" style="background: rgba(0, 48, 135, 0.1); color: #003087;"><x-store.icon name="globe" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">PayPal</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.PayWithPayPal') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body">
                  <div class="alert alert-info text-xs mb-0 flex items-start gap-2">
                    <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
                    <span>{{ __('messages.PayPalRedirectNotice') }}</span>
                  </div>
                </div>
              </div>
            </label>
            @endif

            {{-- Paystack --}}
            @if($paystackEnabled)
            <label class="pay-option" data-method="paystack">
              <input type="radio" name="payment_method" value="paystack" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-card" style="background: rgba(0, 195, 247, 0.12); color: #0092ba;"><x-store.icon name="wallet" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">Paystack</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.PayWithPaystack') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body">
                  <div class="alert alert-info text-xs mb-0 flex items-start gap-2">
                    <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
                    <span>{{ __('messages.PaystackRedirectNotice') }}</span>
                  </div>
                </div>
              </div>
            </label>
            @endif

            {{-- Flutterwave --}}
            @if($flutterwaveEnabled)
            <label class="pay-option" data-method="flutterwave">
              <input type="radio" name="payment_method" value="flutterwave" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-card" style="background: rgba(245, 166, 35, 0.14); color: #c77f00;"><x-store.icon name="globe" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">Flutterwave</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.PayWithFlutterwave') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body">
                  <div class="alert alert-info text-xs mb-0 flex items-start gap-2">
                    <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
                    <span>{{ __('messages.FlutterwaveRedirectNotice') }}</span>
                  </div>
                </div>
              </div>
            </label>
            @endif

            {{-- Razorpay --}}
            @if($razorpayEnabled)
            <label class="pay-option" data-method="razorpay">
              <input type="radio" name="payment_method" value="razorpay" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-card" style="background: rgba(51, 149, 255, 0.12); color: #1a73e8;"><x-store.icon name="credit-card" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">Razorpay</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.PayWithRazorpay') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body">
                  <div class="alert alert-info text-xs mb-0 flex items-start gap-2">
                    <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
                    <span>{{ __('messages.RazorpayRedirectNotice') }}</span>
                  </div>
                </div>
              </div>
            </label>
            @endif

            {{-- bKash --}}
            @if($bkashEnabled)
            <label class="pay-option" data-method="bkash">
              <input type="radio" name="payment_method" value="bkash" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-bkash"><x-store.icon name="phone" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">bKash</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.PayWithBkash') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body">
                  <div class="alert alert-info text-xs mb-0 flex items-start gap-2">
                    <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
                    <span>{{ __('messages.BkashRedirectNotice') }}</span>
                  </div>
                </div>
              </div>
            </label>
            @endif

            {{-- SSLCommerz --}}
            @if($sslcommerzEnabled)
            <label class="pay-option" data-method="sslcommerz">
              <input type="radio" name="payment_method" value="sslcommerz" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-sslcommerz"><x-store.icon name="credit-card" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">SSLCommerz</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.PayWithSslcommerz') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body">
                  <div class="alert alert-info text-xs mb-0 flex items-start gap-2">
                    <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
                    <span>{{ __('messages.SslcommerzRedirectNotice') }}</span>
                  </div>
                </div>
              </div>
            </label>
            @endif

            {{-- Mobile Money --}}
            @if($mobileMoneyEnabled)
            <label class="pay-option" data-method="mobile_money">
              <input type="radio" name="payment_method" value="mobile_money" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-mobile"><x-store.icon name="phone" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">{{ __('messages.MobileMoney') }}</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.MobileMoneyDesc') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body" id="mobile-money-wrapper">
                  <div class="alert alert-info text-xs mb-0 flex items-start gap-2">
                    <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
                    <span>{{ __('messages.MobileMoneyInstructions') }}</span>
                  </div>
                </div>
              </div>
            </label>

            {{-- /Mobile Money --}}
            @endif

            {{-- Cash on Delivery --}}
            @if($codEnabled)
            <label class="pay-option" data-method="cod">
              <input type="radio" name="payment_method" value="cod" class="pay-radio" checked>
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-cod"><x-store.icon name="cash" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">{{ __('messages.CashOnDelivery') }}</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.CashOnDeliveryDesc') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
              </div>
            </label>
            @endif

            {{-- GCash --}}
            @if($gcash)
            <label class="pay-option" data-method="gcash">
              <input type="radio" name="payment_method" value="gcash" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-gcash"><x-store.icon name="phone" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">{{ $gcash['label'] }}</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.GCashDesc') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body">
                  @include('store.partials.manual-payment-details', ['method' => $gcash])
                  @include('store.partials.payment-proof-fields', ['code' => 'gcash'])
                </div>
              </div>
            </label>
            @endif

            {{-- Bank Transfer --}}
            @if($bankTransfer)
            <label class="pay-option" data-method="bank_transfer">
              <input type="radio" name="payment_method" value="bank_transfer" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-bank"><x-store.icon name="receipt" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">{{ $bankTransfer['label'] }}</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.BankTransferDesc') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body">
                  @include('store.partials.manual-payment-details', ['method' => $bankTransfer])
                  @include('store.partials.payment-proof-fields', ['code' => 'bank_transfer'])
                </div>
              </div>
            </label>
            @endif

            {{-- Cash on Pickup --}}
            @if($pickupEnabled)
            <label class="pay-option" data-method="cash_on_pickup">
              <input type="radio" name="payment_method" value="cash_on_pickup" class="pay-radio">
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-pickup"><x-store.icon name="map-pin" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">{{ __('messages.CashOnPickup') }}</div>
                      <div class="text-xs text-fg-muted">{{ __('messages.CashOnPickupDesc') }}</div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
                <div class="pay-body">
                  @if($pickupInstructions)
                    <div class="alert alert-info text-xs mb-3 flex items-start gap-2">
                      <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
                      <span>{{ $pickupInstructions }}</span>
                    </div>
                  @endif

                  <div class="text-xs font-semibold text-fg-secondary mb-2">{{ __('messages.ChoosePickupBranch') }} *</div>
                  <div class="space-y-2">
                    @foreach($pickupBranches as $branch)
                      <label class="branch-option">
                        <input type="radio" name="pickup_branch_id" value="{{ $branch->warehouse_id }}"
                               class="branch-radio" @checked($loop->first)>
                        <span class="branch-inner">
                          <span class="branch-dot"></span>
                          <span class="min-w-0">
                            <span class="block font-semibold text-sm">{{ $branch->warehouse->name }}</span>
                            @if($branch->address || $branch->warehouse->city)
                              <span class="block text-xs text-fg-muted">{{ $branch->address ?: $branch->warehouse->city }}</span>
                            @endif
                            @if($branch->hours)
                              <span class="block text-xs text-fg-muted">{{ __('messages.PickupHours') }}: {{ $branch->hours }}</span>
                            @endif
                            @if($branch->contact)
                              <span class="block text-xs text-fg-muted">{{ __('messages.Phone') }}: {{ $branch->contact }}</span>
                            @endif
                          </span>
                        </span>
                      </label>
                    @endforeach
                  </div>
                  <div id="pickup-branch-error" class="text-danger text-xs mt-2 hidden"></div>
                </div>
              </div>
            </label>
            @endif

            {{-- E-Wallet balance --}}
            @if($walletEnabled)
            <label class="pay-option {{ $walletUsable ? '' : 'pay-option-disabled' }}" data-method="wallet">
              <input type="radio" name="payment_method" value="wallet" class="pay-radio" {{ $walletUsable ? '' : 'disabled' }}>
              <div class="pay-inner">
                <div class="pay-header">
                  <div class="flex items-center gap-3">
                    <div class="pay-icon pay-icon-cod"><x-store.icon name="wallet" class="w-5 h-5" /></div>
                    <div>
                      <div class="font-semibold">{{ __('messages.PayWithWallet') }}</div>
                      <div class="text-xs text-fg-muted">
                        {{ __('messages.WalletBalance') }}: {{ store_money($walletBalance) }}
                      </div>
                    </div>
                  </div>
                  <div class="pay-check"><x-store.icon name="check-circle" class="w-6 h-6" /></div>
                </div>
              </div>
            </label>
            @endif
          </div>
        </div>
      </div>

      <div id="place-order-section">
        <button class="btn btn-primary btn-lg btn-block" id="btnPlaceOrder">
          <span id="btn-text" class="inline-flex items-center gap-2">
            <x-store.icon name="shield-check" class="w-5 h-5" />{{ __('messages.PlaceOrder') }}
          </span>
          <span id="btn-spinner" class="hidden inline-flex items-center gap-2">
            <svg class="animate-spin w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
            {{ __('messages.Processing') }}
          </span>
        </button>
      </div>
    </div>

    {{-- ===== Right column: order summary ===== --}}
    <div class="card lg:sticky lg:top-24">
      <div class="card-body space-y-5">
        <h5 class="font-semibold flex items-center gap-2.5 m-0">
          <span class="trust-icon !w-8 !h-8 !rounded-lg"><x-store.icon name="package" class="w-4 h-4" /></span>
          {{ __('messages.OrderSummary') }}
        </h5>

        <div id="summary-empty" class="empty-state py-8 hidden">
          <div class="empty-icon"><x-store.icon name="cart" class="w-10 h-10" /></div>
          <p class="mt-2 text-fg-muted">{{ __('messages.YourCartIsEmpty') }}</p>
          <a href="{{ route('store.shop') }}" class="btn btn-outline mt-3">{{ __('messages.GoToShop') }}</a>
        </div>

        <div id="summary-list" class="divide-y divide-line-subtle"></div>

        {{-- ===== COUPON ===== --}}
        <div id="coupon-box" class="border-t border-line-subtle pt-4">
          <label class="form-label text-xs">{{ __('messages.CouponCode') }}</label>
          <div class="flex gap-2">
            <input type="text" id="coupon-input" class="input flex-1" placeholder="{{ __('messages.EnterCouponCode') }}" autocomplete="off">
            <button type="button" id="coupon-apply" class="btn btn-outline">{{ __('messages.Apply') }}</button>
            <button type="button" id="coupon-remove" class="btn btn-ghost hidden">{{ __('messages.Remove') }}</button>
          </div>
          <div id="coupon-msg" class="text-xs mt-1"></div>
        </div>

        <div class="space-y-2.5 border-t border-line-subtle pt-4" id="payment-divider">
          <div class="flex justify-between text-sm text-fg-muted">
            <span>{{ __('messages.Subtotal') }}</span>
            <strong id="sum-subtotal" class="price text-fg-primary">{{ $currency }}0.00</strong>
          </div>
          <div class="flex justify-between text-sm text-success" id="sum-discount-row" style="display:none">
            <span>{{ __('messages.Discount') }} <span id="sum-coupon-code" class="text-xs"></span></span>
            <strong id="sum-discount" class="price text-success">-{{ $currency }}0.00</strong>
          </div>
          <div class="flex justify-between text-sm text-fg-muted" id="sum-tax-row">
            <span>{{ __('messages.Tax') }} <span id="sum-tax-rate" class="text-xs"></span></span>
            <strong id="sum-tax" class="price text-fg-primary">{{ $currency }}0.00</strong>
          </div>
          <div class="flex justify-between text-sm text-fg-muted" id="sum-shipping-row">
            <span>{{ __('messages.Shipping') }}</span>
            <strong id="sum-shipping" class="price text-fg-primary">{{ $currency }}0.00</strong>
          </div>
          <div class="flex justify-between items-baseline border-t border-line-subtle pt-3">
            <span class="font-semibold">{{ __('messages.GrandTotal') }}</span>
            <strong id="sum-grand" class="price text-xl text-fg-primary">{{ $currency }}0.00</strong>
          </div>
        </div>

        <div class="flex items-center justify-center gap-1.5 border-t border-line-subtle pt-4 text-xs text-fg-muted">
          <x-store.icon name="shield-check" class="w-3.5 h-3.5 text-success" />
          {{ __('messages.SecurePayment') }}
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  /* Compact stacked layout — the summary lives in a 400px sidebar now. */
  .co-line {
    display: grid;
    grid-template-columns: 54px minmax(0, 1fr) auto;
    grid-template-areas:
      "thumb info  remove"
      "thumb qty   price";
    align-items: center;
    column-gap: .75rem;
    row-gap: .5rem;
    padding: .85rem 0;
  }
  .co-thumb {
    width:54px; height:54px; object-fit:cover; border-radius:.65rem;
    border:1px solid rgb(var(--color-border-subtle));
    grid-area: thumb; align-self: start;
  }
  .co-line .co-info    { grid-area: info; }
  .co-line .qty-stepper{ grid-area: qty; width: 100%; max-width: 132px; }
  .co-line .js-line {
    grid-area: price; min-width: 0; text-align: end; white-space: nowrap; font-weight:600;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-variant-numeric: tabular-nums;
  }
  .co-line .js-remove {
    width:36px; height:36px; display:inline-flex; justify-content:center; align-items:center; padding:0;
    grid-area: remove; margin: 0; align-self: start; justify-self: end;
  }

  /* Payment methods */
  .pay-methods { display: flex; flex-direction: column; gap: .75rem; }

  .pay-option { cursor: pointer; margin: 0; display: block; }
  .pay-option .pay-radio { position: absolute; opacity: 0; pointer-events: none; }

  .pay-inner {
    border: 1px solid rgb(var(--color-border-subtle));
    border-radius: 12px;
    transition: all .2s ease;
    overflow: hidden;
    background: rgb(var(--color-bg-surface));
  }
  .pay-option:hover .pay-inner { border-color: rgb(var(--color-border-strong)); }
  .pay-option .pay-radio:checked ~ .pay-inner {
    border-color: rgb(var(--color-accent-500));
    background: rgb(var(--color-accent-500) / .04);
    box-shadow: 0 0 0 1px rgb(var(--color-accent-500));
  }

  .pay-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.25rem;
  }

  .pay-icon {
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #fff;
    flex-shrink: 0;
  }
  .pay-icon-card   { background: linear-gradient(135deg, #667eea, #764ba2); }
  .pay-icon-mobile { background: linear-gradient(135deg, #f093fb, #f5576c); }
  .pay-icon-cod    { background: linear-gradient(135deg, #4facfe, #00f2fe); }
  .pay-icon-gcash  { background: linear-gradient(135deg, #0075c9, #00b0f0); }
  .pay-icon-bkash      { background: linear-gradient(135deg, #e2136e, #ff5fa2); }
  .pay-icon-sslcommerz { background: linear-gradient(135deg, #2e3192, #1a73e8); }
  .pay-icon-bank   { background: linear-gradient(135deg, #1f2a5a, #4c5fd7); }
  .pay-icon-pickup { background: linear-gradient(135deg, #f7971e, #ffd200); }

  /* Branch picker (cash on pickup) */
  .branch-option { display:block; cursor:pointer; margin:0; }
  .branch-option .branch-radio { position:absolute; opacity:0; pointer-events:none; }
  .branch-inner {
    display:flex; gap:.75rem; align-items:flex-start;
    padding:.75rem .875rem;
    border:1px solid rgb(var(--color-border-subtle));
    border-radius:10px;
    background: rgb(var(--color-bg-surface));
    transition: border-color .15s, background .15s;
  }
  .branch-option:hover .branch-inner { border-color: rgb(var(--color-border-strong)); }
  .branch-dot {
    width:16px; height:16px; margin-top:2px; border-radius:50%; flex-shrink:0;
    border:2px solid rgb(var(--color-border-strong));
  }
  .branch-option .branch-radio:checked ~ .branch-inner {
    border-color: rgb(var(--color-accent-500));
    background: rgb(var(--color-accent-500) / .05);
  }
  .branch-option .branch-radio:checked ~ .branch-inner .branch-dot {
    border-color: rgb(var(--color-accent-500));
    background: rgb(var(--color-accent-500));
    box-shadow: inset 0 0 0 3px rgb(var(--color-bg-surface));
  }

  /* Manual payment account details */
  .pay-detail-row {
    display:flex; align-items:center; justify-content:space-between; gap:.75rem;
    padding:.5rem .75rem;
    border-radius:8px;
    background: rgb(var(--color-bg-muted));
  }
  .pay-detail-row + .pay-detail-row { margin-top:.375rem; }
  .pay-copy-btn { flex-shrink:0; }
  .pay-qr { max-width:180px; border-radius:10px; border:1px solid rgb(var(--color-border-subtle)); }

  .pay-check { color: rgb(var(--color-border-subtle)); transition: color .2s; }
  .pay-option .pay-radio:checked ~ .pay-inner .pay-check { color: rgb(var(--color-accent-500)); }

  .pay-body { display: none; padding: 0 1.25rem 1rem; }
  .pay-option .pay-radio:checked ~ .pay-inner .pay-body { display: block; }

  .pay-option-disabled { cursor: not-allowed; }
  .pay-option-disabled .pay-inner { opacity: .55; background: rgb(var(--color-bg-muted)); }
  .pay-option-disabled:hover .pay-inner { border-color: rgb(var(--color-border-subtle)); }
  .pay-body-warning { display: block !important; }

  .stripe-card-element {
    padding: .75rem;
    border: 1px solid rgb(var(--color-border-subtle));
    border-radius: 8px;
    background: rgb(var(--color-bg-surface));
    transition: border-color .2s;
  }
  .stripe-card-element.StripeElement--focus {
    border-color: rgb(var(--color-accent-500));
    box-shadow: 0 0 0 2px rgb(var(--color-accent-500) / .18);
  }
  .stripe-card-element.StripeElement--invalid {
    border-color: rgb(var(--color-danger));
  }

</style>

@if($stripeKey)
<script src="https://js.stripe.com/v3/"></script>
@endif

<script>
(function(){
  var currencyMeta = document.querySelector('meta[name="currency"]');
  var csrfMeta     = document.querySelector('meta[name="csrf-token"]');
  var CURRENCY     = currencyMeta ? currencyMeta.content : @json($currency);
  var PRICE_DECIMALS = parseInt(document.querySelector('meta[name="price-decimals"]')?.content, 10) || 2;
  var CURRENCY_RATE = parseFloat(document.querySelector('meta[name="currency-rate"]')?.content) || 1;
  var CSRF         = csrfMeta ? csrfMeta.content : '';
  var NOIMG        = @json(asset('images/products/no-image.png'));
  var STRIPE_KEY   = @json($stripeKey ?? '');
  var T_PREORDER   = @json(__('messages.PreOrder'));
  var T_REMOVE     = @json(__('messages.Remove'));

  function fmt(v){ return CURRENCY + (Number(v||0) * CURRENCY_RATE).toLocaleString('en-US', { minimumFractionDigits: PRICE_DECIMALS, maximumFractionDigits: PRICE_DECIMALS }); }
  function esc(s){ return String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  function getCart(){
    if (window.CartLS && typeof window.CartLS.get === 'function') return window.CartLS.get();
    try {
      var raw = JSON.parse(localStorage.getItem('shop.cart.v1')||'{}');
      if (!raw || !Array.isArray(raw.items)) return { items:[], currency:CURRENCY, subtotal:0, grand:0 };
      raw.subtotal = raw.items.reduce(function(a,i){ return a + (Number(i.price)||0)*(Number(i.qty)||0); }, 0);
      raw.grand = raw.subtotal;
      return raw;
    } catch(e){ return { items:[], currency:CURRENCY, subtotal:0, grand:0 }; }
  }

  function extractIds(item){
    var pid  = item.product_id != null ? Number(item.product_id) : null;
    var pvid = item.product_variant_id != null ? Number(item.product_variant_id) : null;
    if (pid == null){
      var parts = String(item.id||'').split(':');
      pid  = Number(parts[0] || 0) || 0;
      pvid = (pvid != null) ? pvid : (parts[1] ? Number(parts[1]) : null);
    }
    return { product_id: pid, product_variant_id: pvid };
  }

  var listEl   = document.getElementById('summary-list');
  var emptyEl  = document.getElementById('summary-empty');
  var subEl    = document.getElementById('sum-subtotal');
  var grandEl  = document.getElementById('sum-grand');
  var btn      = document.getElementById('btnPlaceOrder');
  var btnText  = document.getElementById('btn-text');
  var btnSpin  = document.getElementById('btn-spinner');
  var paySec   = document.getElementById('payment-section');
  var payDiv   = document.getElementById('payment-divider');
  var placeSec = document.getElementById('place-order-section');

  var stripe, cardElement;
  if (STRIPE_KEY) {
    stripe = Stripe(STRIPE_KEY);
    var elements = stripe.elements();
    var isDark = document.documentElement.classList.contains('dark');
    cardElement = elements.create('card', {
      style: {
        base: {
          fontSize: '15px',
          color: isDark ? '#e6e7eb' : '#111827',
          fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
          '::placeholder': { color: isDark ? '#6b7280' : '#9ca3af' }
        },
        invalid: { color: '#ef4444' }
      }
    });
    cardElement.mount('#stripe-card-element');
    cardElement.on('change', function(event) {
      var errEl = document.getElementById('stripe-card-errors');
      errEl.textContent = event.error ? event.error.message : '';
    });
  }

  function render(){
    var cart = getCart();

    if (!cart.items || !cart.items.length){
      emptyEl.classList.remove('hidden');
      listEl.innerHTML = '';
      subEl.textContent   = fmt(0);
      grandEl.textContent = fmt(0);
      if (paySec)   paySec.classList.add('hidden');
      if (payDiv)   payDiv.classList.add('hidden');
      if (placeSec) placeSec.classList.add('hidden');
      return;
    }

    emptyEl.classList.add('hidden');
    if (paySec)   paySec.classList.remove('hidden');
    if (payDiv)   payDiv.classList.remove('hidden');
    if (placeSec) placeSec.classList.remove('hidden');
    listEl.innerHTML = '';

    cart.items.forEach(function(it){
      var row = document.createElement('div');
      row.className = 'co-line';
      row.dataset.id = it.id;

      var variantBadge  = it.variant_name ? '<div class="mt-1"><span class="chip text-xs">'+ esc(it.variant_name) +'</span></div>' : '';
      var preorderBadge = it.is_preorder ? '<div class="mt-1"><span class="chip text-xs" style="color: rgb(var(--color-warning));">'+ T_PREORDER +'</span></div>' : '';

      row.innerHTML =
        '<img class="co-thumb" src="'+ esc(it.image || NOIMG) +'" alt="'+ esc(it.name||'') +'">' +
        '<div class="co-info min-w-0">' +
          '<div class="font-semibold truncate" title="'+ esc(it.name||'') +'">'+ esc(it.name||'') +'</div>' +
          variantBadge +
          preorderBadge +
          '<div class="text-xs text-fg-muted mt-1">'+ fmt(it.price) +'</div>' +
        '</div>' +
        '<div class="qty-stepper">' +
          '<button class="js-dec" type="button">−</button>' +
          '<input type="number" class="js-qty" value="'+ (it.qty||1) +'" min="1">' +
          '<button class="js-inc" type="button">+</button>' +
        '</div>' +
        '<div class="js-line">'+ fmt((Number(it.price)||0)*(Number(it.qty)||0)) +'</div>' +
        '<button class="btn btn-ghost btn-icon btn-sm js-remove text-danger" type="button" title="'+ T_REMOVE +'" aria-label="'+ T_REMOVE +'">' +
          '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
        '</button>';

      listEl.appendChild(row);
    });

    var sub = cart.items.reduce(function(a,i){ return a + (Number(i.price)||0)*(Number(i.qty)||0); }, 0);
    subEl.textContent   = fmt(sub);
    grandEl.textContent = fmt(sub);

    // Pull authoritative subtotal/tax/shipping/total (and shipping methods) from the server.
    refreshQuote();
  }

  listEl.addEventListener('click', function(e){
    var row = e.target.closest('.co-line'); if(!row) return;
    var id  = row.dataset.id;

    if (e.target.closest('.js-dec')) {
      var inp = row.querySelector('.js-qty');
      var v   = Math.max(1, parseInt(inp.value||'1',10) - 1);
      inp.value = v;
      if (window.CartLS && CartLS.setQty) CartLS.setQty(id, v);
      render();
    }
    if (e.target.closest('.js-inc')) {
      var inp = row.querySelector('.js-qty');
      var v   = Math.max(1, parseInt(inp.value||'1',10) + 1);
      inp.value = v;
      if (window.CartLS && CartLS.setQty) CartLS.setQty(id, v);
      render();
    }
    if (e.target.closest('.js-remove')) {
      if (window.CartLS && CartLS.remove) CartLS.remove(id);
      render();
    }
  });

  listEl.addEventListener('change', function(e){
    var inp = e.target.closest('.js-qty'); if(!inp) return;
    var row = inp.closest('.co-line');
    var id  = row.dataset.id;
    var v   = Math.max(1, parseInt(inp.value||'1',10));
    inp.value = v;
    if (window.CartLS && CartLS.setQty) CartLS.setQty(id, v);
    render();
  });

  window.addEventListener('cart:changed', render);

  function getSelectedPaymentMethod() {
    var checked = document.querySelector('input[name="payment_method"]:checked');
    return checked ? checked.value : 'cod';
  }

  // Paying at the counter means collecting at a branch: no address needed,
  // no shipping method, no shipping cost.
  var PICKUP_METHODS = ['cash_on_pickup'];
  var PROOF_METHODS  = ['gcash', 'bank_transfer'];

  function isPickupSelected(){
    return PICKUP_METHODS.indexOf(getSelectedPaymentMethod()) !== -1;
  }

  function getSelectedPickupBranch(){
    var checked = document.querySelector('input[name="pickup_branch_id"]:checked');
    return checked ? Number(checked.value) : null;
  }

  // Copy an account number to the clipboard from the details block.
  document.addEventListener('click', function(e){
    var b = e.target.closest('.js-copy');
    if (!b) return;
    e.preventDefault();
    var text = b.getAttribute('data-copy') || '';
    var done = function(){
      var old = b.getAttribute('title');
      b.setAttribute('title', @json(__('messages.Copied')));
      setTimeout(function(){ b.setAttribute('title', old || ''); }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function(){});
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch(err){}
      document.body.removeChild(ta);
    }
  });

  /**
   * Proof fields for the selected method, when the shopper filled them in.
   * All three (reference, amount, file) travel together or not at all —
   * a partial submission is rejected before the order is placed.
   */
  function collectProof(method){
    if (PROOF_METHODS.indexOf(method) === -1) return { ok: true, data: null };

    var refEl  = document.querySelector('.js-proof-ref[data-method="'+ method +'"]');
    var amtEl  = document.querySelector('.js-proof-amount[data-method="'+ method +'"]');
    var fileEl = document.querySelector('.js-proof-file[data-method="'+ method +'"]');
    var errEl  = document.querySelector('.js-proof-error[data-method="'+ method +'"]');
    if (errEl) { errEl.textContent = ''; errEl.classList.add('hidden'); }

    var ref  = refEl ? String(refEl.value || '').trim() : '';
    var amt  = amtEl ? Number(amtEl.value || 0) : 0;
    var file = (fileEl && fileEl.files && fileEl.files[0]) ? fileEl.files[0] : null;

    // Nothing filled in: the shopper uploads later from the order page.
    if (!ref && !amt && !file) return { ok: true, data: null };

    if (!ref || !(amt > 0) || !file) {
      if (errEl) {
        errEl.textContent = @json(__('messages.ProofIncomplete'));
        errEl.classList.remove('hidden');
      }
      return { ok: false, data: null };
    }

    return { ok: true, data: { reference_number: ref, amount: amt, file: file } };
  }

  /** Upload the proof against the order that was just created. */
  function uploadProof(orderId, proof){
    var fd = new FormData();
    fd.append('reference_number', proof.reference_number);
    fd.append('amount', proof.amount);
    fd.append('file', proof.file);

    return fetch(PROOF_URL_BASE + '/' + orderId + '/payment-proofs', {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: fd
    }).then(function(res){
      // A failed upload must not lose the order: the shopper can retry from
      // the order page, so this only logs.
      if (!res.ok) return res.json().then(function(e){ console.warn('proof upload failed', e); });
    }).catch(function(e){ console.warn('proof upload failed', e); });
  }

  function setLoading(loading) {
    btn.disabled = loading;
    btnText.classList.toggle('hidden', loading);
    btnSpin.classList.toggle('hidden', !loading);
  }

  var THANKYOU_URL       = "{{ route('store.thankyou') }}";
  var CREATE_URL         = "{{ route('store.orders.store') }}";
  var PAYMENT_INTENT_URL = "{{ route('store.payment.intent') }}";
  var QUOTE_URL          = "{{ route('store.checkout.quote') }}";
  var PROOF_URL_BASE     = @json(url('/'.store_path_to('my/orders')));

  // ---- State/province suggestions for the chosen country ----
  var SUBDIVISIONS = @json(\App\Services\CountryService::subdivisionMap());
  (function stateOptions(){
    var countryEl = document.getElementById('ship-country');
    var listEl = document.getElementById('ship-state-options');
    var stateEl = document.getElementById('ship-state');
    if (!countryEl || !listEl) return;

    function fill(){
      var opt = countryEl.options[countryEl.selectedIndex];
      var code = opt ? opt.getAttribute('data-code') : '';
      var rows = (code && SUBDIVISIONS[code]) || [];
      listEl.innerHTML = rows.map(function(s){
        return '<option value="' + String(s).replace(/"/g, '&quot;') + '"></option>';
      }).join('');
      // A state from another country is worse than none at all.
      if (stateEl && rows.length && stateEl.value &&
          rows.indexOf(stateEl.value) === -1 && stateEl.dataset.userTyped !== '1') {
        stateEl.value = '';
      }
    }

    if (stateEl) stateEl.addEventListener('input', function(){ stateEl.dataset.userTyped = '1'; });
    countryEl.addEventListener('change', function(){
      if (stateEl) stateEl.dataset.userTyped = '';
      fill();
    });
    fill();
  })();

  // ---- Address + shipping method + server quote state ----
  var addr = {
    name:    document.getElementById('ship-name'),
    phone:   document.getElementById('ship-phone'),
    address: document.getElementById('ship-address'),
    city:    document.getElementById('ship-city'),
    state:   document.getElementById('ship-state'),
    zip:     document.getElementById('ship-zip'),
    country: document.getElementById('ship-country')
  };
  var shipSection = document.getElementById('shipping-method-section');
  var shipDivider = document.getElementById('shipping-method-divider');
  var shipListEl  = document.getElementById('shipping-methods');
  var shipEmptyEl = document.getElementById('shipping-methods-empty');
  var addrErrEl   = document.getElementById('address-error');
  var taxRow      = document.getElementById('sum-tax-row');
  var taxEl       = document.getElementById('sum-tax');
  var taxRateEl   = document.getElementById('sum-tax-rate');
  var shipRow     = document.getElementById('sum-shipping-row');
  var shipCostEl  = document.getElementById('sum-shipping');

  var quote = { subtotal: 0, tax: 0, tax_rate: 0, shipping_cost: 0, total: 0, shipping_required: false };
  var selectedShipping = null;
  var quoteTimer = null;
  var appliedCoupon = '';

  var couponInput = document.getElementById('coupon-input');
  var couponApply = document.getElementById('coupon-apply');
  var couponRemove = document.getElementById('coupon-remove');
  var couponMsg = document.getElementById('coupon-msg');
  var discountRow = document.getElementById('sum-discount-row');
  var discountEl = document.getElementById('sum-discount');
  var couponCodeEl = document.getElementById('sum-coupon-code');

  function addrVal(k){ return addr[k] ? String(addr[k].value || '').trim() : ''; }

  function buildItems(cart){
    var items = (cart.items || []).map(function(i){
      var ids = extractIds(i);
      return {
        product_id:         Number(ids.product_id || 0),
        product_variant_id: (ids.product_variant_id != null ? Number(ids.product_variant_id) : null),
        qty:                Number(i.qty||1)
      };
    });
    return items.filter(function(x){ return x.product_id > 0 && x.qty > 0; });
  }

  function getSelectedShippingMethod(){
    var checked = document.querySelector('input[name="shipping_method"]:checked');
    return checked ? Number(checked.value) : null;
  }

  function renderShippingMethods(methods){
    if (!shipListEl) return;
    if (!methods || !methods.length){
      shipListEl.innerHTML = '';
      if (shipEmptyEl) shipEmptyEl.classList.remove('hidden');
      selectedShipping = null;
      return;
    }
    if (shipEmptyEl) shipEmptyEl.classList.add('hidden');

    // Keep prior selection if still available, else default to first.
    var ids = methods.map(function(m){ return Number(m.id); });
    if (selectedShipping == null || ids.indexOf(Number(selectedShipping)) === -1) {
      selectedShipping = ids[0];
    }

    shipListEl.innerHTML = methods.map(function(m){
      var checked = Number(m.id) === Number(selectedShipping) ? 'checked' : '';
      return '<label class="flex items-center justify-between gap-2 p-3 rounded border border-line-subtle cursor-pointer">' +
        '<span class="flex items-center gap-2">' +
          '<input type="radio" name="shipping_method" value="'+ m.id +'" '+ checked +'>' +
          '<span>'+ esc(m.name) +'</span>' +
        '</span>' +
        '<strong>'+ fmt(m.price) +'</strong>' +
      '</label>';
    }).join('');
  }

  function applyQuote(q){
    quote = q;
    // Discount
    var disc = Number(q.discount || 0);
    if (discountRow) discountRow.style.display = disc > 0 ? '' : 'none';
    if (discountEl) discountEl.textContent = '-' + fmt(disc);
    if (couponCodeEl) couponCodeEl.textContent = q.coupon_code ? ('('+ q.coupon_code +')') : '';
    // Tax
    if (taxRow) taxRow.style.display = (Number(q.tax) > 0) ? '' : 'none';
    if (taxEl)  taxEl.textContent = fmt(q.tax);
    if (taxRateEl) taxRateEl.textContent = Number(q.tax_rate) > 0 ? ('('+ Number(q.tax_rate) +'%)') : '';
    // Shipping
    if (shipRow) shipRow.style.display = (Number(q.shipping_cost) > 0) ? '' : 'none';
    if (shipCostEl) shipCostEl.textContent = fmt(q.shipping_cost);
    // Grand total from server
    grandEl.textContent = fmt(q.total);
    if (subEl) subEl.textContent = fmt(q.subtotal);
  }

  function refreshQuote(){
    var cart = getCart();
    var items = buildItems(cart);
    if (!items.length) return;

    fetch(QUOTE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        items: items,
        country: addrVal('country'),
        state: addrVal('state'),
        shipping_method_id: getSelectedShippingMethod(),
        coupon_code: appliedCoupon || null,
        payment_method: getSelectedPaymentMethod(),
        pickup_branch_id: getSelectedPickupBranch()
      })
    })
    .then(function(res){ return res.json().then(function(d){ if(!res.ok) throw d; return d; }); })
    .then(function(data){
      if (shipSection) shipSection.classList.toggle('hidden', !data.shipping_required);
      if (shipDivider) shipDivider.style.display = data.shipping_required ? '' : 'none';
      renderShippingMethods(data.shipping_methods || []);
      // Re-read selection after render then recompute shipping cost locally for display.
      var chosen = getSelectedShippingMethod();
      var cost = 0;
      (data.shipping_methods || []).forEach(function(m){ if (Number(m.id) === Number(chosen)) cost = Number(m.price); });

      var discount = Number(data.discount || 0);
      applyQuote({
        subtotal: data.subtotal,
        discount: discount,
        coupon_code: data.coupon_code,
        tax: data.tax,
        tax_rate: data.tax_rate,
        shipping_cost: cost,
        total: Math.max(0, Number(data.subtotal) - discount) + Number(data.tax) + cost,
        shipping_required: data.shipping_required
      });

      // Coupon feedback.
      if (couponMsg) {
        if (data.coupon_error) {
          couponMsg.textContent = data.coupon_error;
          couponMsg.className = 'text-xs mt-1 text-danger';
          appliedCoupon = ''; // invalid → drop it
          if (couponRemove) couponRemove.classList.add('hidden');
        } else if (data.coupon_code) {
          couponMsg.textContent = @json(__('messages.CouponApplied'));
          couponMsg.className = 'text-xs mt-1 text-success';
          if (couponRemove) couponRemove.classList.remove('hidden');
        } else {
          couponMsg.textContent = '';
          if (couponRemove) couponRemove.classList.add('hidden');
        }
      }
    })
    .catch(function(err){ /* keep last known totals on transient errors */ });
  }

  if (couponApply) couponApply.addEventListener('click', function(){
    appliedCoupon = (couponInput.value || '').trim();
    refreshQuote();
  });
  if (couponRemove) couponRemove.addEventListener('click', function(){
    appliedCoupon = ''; if (couponInput) couponInput.value = '';
    if (couponMsg) couponMsg.textContent = '';
    refreshQuote();
  });

  function scheduleQuote(){
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(refreshQuote, 350);
  }

  // Recompute when the region changes or a shipping method is picked.
  // Country is a <select> now — listen for change as well as input so the
  // shipping/tax quote refreshes the moment a country is picked.
  ['country','state'].forEach(function(k){
    if (!addr[k]) return;
    addr[k].addEventListener('input', scheduleQuote);
    addr[k].addEventListener('change', scheduleQuote);
  });
  if (shipListEl) shipListEl.addEventListener('change', function(e){
    if (e.target && e.target.name === 'shipping_method') {
      selectedShipping = Number(e.target.value);
      refreshQuote();
    }
  });

  // Switching to/from Cash on Pickup reshapes the form: the branch replaces
  // the delivery address and no shipping method is chosen.
  function applyDeliveryMode(){
    var pickup = isPickupSelected();
    document.querySelectorAll('.js-ship-only').forEach(function(el){
      el.classList.toggle('hidden', pickup);
    });
    var title = document.getElementById('address-section-title');
    if (title) title.textContent = pickup
      ? @json(__('messages.ContactDetails'))
      : @json(__('messages.ShippingAddress'));
    var note = document.getElementById('pickup-address-note');
    if (note) note.classList.toggle('hidden', !pickup);
    if (pickup) {
      if (shipSection) shipSection.classList.add('hidden');
      if (shipDivider) shipDivider.style.display = 'none';
    }
  }

  document.addEventListener('change', function(e){
    if (!e.target) return;
    if (e.target.name === 'payment_method') {
      applyDeliveryMode();
      refreshQuote();
    } else if (e.target.name === 'pickup_branch_id') {
      refreshQuote();
    }
  });

  function validateAddress(){
    var pickup = isPickupSelected();
    var branchErrEl = document.getElementById('pickup-branch-error');
    if (branchErrEl) branchErrEl.classList.add('hidden');

    if (pickup) {
      if (!addrVal('name') || !addrVal('phone')) {
        if (addrErrEl){ addrErrEl.textContent = '{{ __("messages.CustomerInfoIncomplete") }}'; addrErrEl.classList.remove('hidden'); }
        return false;
      }
      if (addrErrEl) addrErrEl.classList.add('hidden');
      if (!getSelectedPickupBranch()) {
        if (branchErrEl){ branchErrEl.textContent = '{{ __("messages.PickupBranchRequired") }}'; branchErrEl.classList.remove('hidden'); }
        return false;
      }
      return true;
    }

    var missing = [];
    if (!addrVal('name'))    missing.push('name');
    if (!addrVal('phone'))   missing.push('phone');
    if (!addrVal('address')) missing.push('address');
    if (!addrVal('country')) missing.push('country');
    if (missing.length){
      if (addrErrEl){ addrErrEl.textContent = '{{ __("messages.CustomerInfoIncomplete") }}'; addrErrEl.classList.remove('hidden'); }
      return false;
    }
    if (addrErrEl) addrErrEl.classList.add('hidden');
    if (quote.shipping_required && !getSelectedShippingMethod()){
      if (addrErrEl){ addrErrEl.textContent = '{{ __("messages.ShippingMethodRequired") }}'; addrErrEl.classList.remove('hidden'); }
      return false;
    }
    return true;
  }

  if (btn) {
    btn.addEventListener('click', function(){
      var cart = getCart();
      if (!cart.items || !cart.items.length) { alert('{{ __("messages.YourCartIsEmpty") }}'); return; }

      var items = cart.items.map(function(i){
        var ids = extractIds(i);
        return {
          product_id:         Number(ids.product_id || 0),
          product_variant_id: (ids.product_variant_id != null ? Number(ids.product_variant_id) : null),
          qty:                Number(i.qty||1),
          price:              Number(i.price||0),
          name:               i.name || null
        };
      });

      items = items.filter(function(x){ return x.product_id > 0 && x.qty > 0 && x.price >= 0; });
      if (!items.length){ alert('{{ __("messages.YourCartIsEmpty") }}'); return; }

      if (!validateAddress()) { return; }

      var paymentMethod = getSelectedPaymentMethod();

      // Proof is optional, but a half-filled one is a mistake worth catching
      // before the order exists.
      var proof = collectProof(paymentMethod);
      if (!proof.ok) return;

      setLoading(true);

      if (paymentMethod === 'credit_card') {
        handleStripePayment(items, cart);
      } else {
        submitOrder(items, cart, paymentMethod, null, proof.data);
      }
    });
  }

  function handleStripePayment(items, cart) {
    if (!stripe || !cardElement) {
      alert('Stripe is not available.');
      setLoading(false);
      return;
    }

    // Amount is computed SERVER-side from items + shipping; never sent from here.
    fetch(PAYMENT_INTENT_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': CSRF
      },
      body: JSON.stringify({
        items: buildItems(cart),
        shipping_method_id: getSelectedShippingMethod(),
        country: addrVal('country'),
        state: addrVal('state'),
        coupon_code: appliedCoupon || null
      })
    })
    .then(function(res){
      if (!res.ok) return res.json().then(function(e){ throw e; });
      return res.json();
    })
    .then(function(data){
      return stripe.confirmCardPayment(data.clientSecret, {
        payment_method: { card: cardElement }
      });
    })
    .then(function(result){
      if (result.error) {
        document.getElementById('stripe-card-errors').textContent = result.error.message;
        setLoading(false);
        return;
      }
      if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
        submitOrder(items, cart, 'credit_card', result.paymentIntent.id);
      } else {
        alert('{{ __("messages.PaymentFailed") }}');
        setLoading(false);
      }
    })
    .catch(function(err){
      console.error(err);
      var msg = (err && (err.message || err.error)) || '{{ __("messages.PaymentFailed") }}';
      alert(msg);
      setLoading(false);
    });
  }

  function submitOrder(items, cart, paymentMethod, stripePaymentIntentId, proof) {
    var pickup = PICKUP_METHODS.indexOf(paymentMethod) !== -1;
    var payload = {
      items: items,
      payment_method: paymentMethod,
      stripe_payment_intent_id: stripePaymentIntentId,
      pickup_branch_id: pickup ? getSelectedPickupBranch() : null,
      shipping_method_id: pickup ? null : getSelectedShippingMethod(),
      coupon_code: appliedCoupon || null,
      customer_name: addrVal('name'),
      customer_phone: addrVal('phone'),
      shipping_address: addrVal('address'),
      shipping_city: addrVal('city'),
      shipping_state: addrVal('state'),
      shipping_zip: addrVal('zip'),
      shipping_country: addrVal('country')
    };

    fetch(CREATE_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': CSRF
      },
      body: JSON.stringify(payload)
    })
    .then(function(res){
      if (!res.ok) return res.json().then(function(e){ throw e || new Error('Request failed'); });
      return res.json();
    })
    .then(function(order){
      var subtotal = cart.items.reduce(function(a,i){ return a + (Number(i.price)||0)*(Number(i.qty)||0); }, 0);
      var receipt  = {
        order_id: order.id,
        order_no: order.ref || order.code || ('#'+order.id),
        placed_at: new Date().toISOString(),
        currency: cart.currency || CURRENCY,
        items: cart.items,
        payment_method: paymentMethod,
        payment_status: order.payment_status || 'pending',
        totals: { subtotal: subtotal.toFixed(PRICE_DECIMALS), grand: Number(order.total||subtotal).toFixed(PRICE_DECIMALS) }
      };
      try { localStorage.setItem('shop.last_order', JSON.stringify(receipt)); } catch(e){}

      // Redirect gateways (PayPal / Paystack / Flutterwave): the order exists
      // as payment-pending — hand the customer to the gateway to approve.
      // Keep a cart backup so a cancel can restore it.
      if (['paypal', 'paystack', 'flutterwave', 'razorpay', 'bkash', 'sslcommerz'].indexOf(paymentMethod) !== -1 && order.approve_url) {
        try { localStorage.setItem('shop.cart.backup', JSON.stringify(cart)); } catch(e){}
        try { if (window.CartLS && CartLS.clear) CartLS.clear(); else localStorage.removeItem('shop.cart.v1'); } catch(e){}
        window.location.href = order.approve_url;
        return;
      }

      try { if (window.CartLS && CartLS.clear) CartLS.clear(); else localStorage.removeItem('shop.cart.v1'); } catch(e){}
      try { localStorage.removeItem('shop.cart.backup'); } catch(e){}

      if (proof && order.id) {
        return uploadProof(order.id, proof).then(function(){
          window.location.href = THANKYOU_URL;
        });
      }
      window.location.href = THANKYOU_URL;
    })
    .catch(function(err){
      console.error(err);
      var msg = (err && (err.message || err.error)) || '{{ __("messages.CouldNotPlaceOrder") }}';
      if (err && Array.isArray(err.items) && err.items.length) {
        msg = '{{ __("messages.InsufficientStockFor") }}\n' + err.items.map(function(x){
          return (x.name || ('#'+x.product_id)) + ' — {{ __("messages.Available") }}: ' + x.available + ', {{ __("messages.Required") }}: ' + x.required;
        }).join('\n');
      }
      alert(msg);
      setLoading(false);
    });
  }

  // Back from a redirect gateway without paying: restore the backed-up cart
  // (the order that was created for the attempt was cancelled server-side).
  (function(){
    var q = new URLSearchParams(window.location.search);
    var gw = ['paypal', 'paystack', 'flutterwave', 'razorpay', 'bkash', 'sslcommerz'].find(function(g){ return q.get(g); }) || null;
    var st = gw ? q.get(gw) : null;
    if (st !== 'cancelled' && st !== 'failed') return;
    try {
      var backup = JSON.parse(localStorage.getItem('shop.cart.backup') || 'null');
      if (backup && Array.isArray(backup.items) && backup.items.length) {
        localStorage.setItem('shop.cart.v1', JSON.stringify(backup));
      }
      localStorage.removeItem('shop.cart.backup');
    } catch(e){}
    // Clean the query string so a refresh doesn't re-trigger the notice.
    try { history.replaceState(null, '', window.location.pathname); } catch(e){}
    var MSGS = {
      paypal:      { cancelled: @json(__('messages.PayPalCancelled')),      failed: @json(__('messages.PayPalFailed')) },
      paystack:    { cancelled: @json(__('messages.PaystackCancelled')),    failed: @json(__('messages.PaystackFailed')) },
      flutterwave: { cancelled: @json(__('messages.FlutterwaveCancelled')), failed: @json(__('messages.FlutterwaveFailed')) },
      razorpay:    { cancelled: @json(__('messages.RazorpayCancelled')),    failed: @json(__('messages.RazorpayFailed')) },
      bkash:       { cancelled: @json(__('messages.BkashCancelled')),       failed: @json(__('messages.BkashFailed')) },
      sslcommerz:  { cancelled: @json(__('messages.SslcommerzCancelled')),  failed: @json(__('messages.SslcommerzFailed')) }
    };
    alert(MSGS[gw][st]);
  })();

  // COD carries the default `checked`; when the admin turns it off, fall back
  // to the first method that is actually offered.
  (function ensurePaymentSelected(){
    if (document.querySelector('input[name="payment_method"]:checked')) return;
    var first = document.querySelector('input[name="payment_method"]:not([disabled])');
    if (first) first.checked = true;
  })();

  applyDeliveryMode();
  render();
})();
</script>
@endsection
