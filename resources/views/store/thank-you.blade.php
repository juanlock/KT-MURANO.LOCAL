@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  use App\Models\StoreSetting;

  $s = $s ?? StoreSetting::first();

  // Offline methods: repeat the account details here so the shopper can pay
  // straight after ordering, and point them at the proof upload.
  $manualMethods = \App\Services\StorePaymentMethodService::enabled($s);
@endphp

<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6 text-center">
    <span class="section-kicker">{{ __('messages.Order') }}</span>
    <h1 class="section-title mt-1">{{ __('messages.ThankYou') }}</h1>
  </div>
</section>

<div class="container py-10" id="ty-app">
  <div class="max-w-3xl mx-auto">
    <div class="card">
      <div class="card-body p-6 md:p-8">
        <div id="ty-empty" class="empty-state py-12 hidden">
          <div class="empty-icon"><x-store.icon name="cart" class="w-10 h-10" /></div>
          <h3>{{ __('messages.NoRecentOrder') }}</h3>
          <a href="{{ route('store.shop') }}" class="btn btn-outline mt-4">{{ __('messages.GoToShop') }}</a>
        </div>

        <div id="ty-receipt" class="hidden">
          <div class="flex items-center justify-center mb-6">
            <div class="w-16 h-16 rounded-full flex items-center justify-center text-success"
                 style="background: rgb(var(--color-success) / .12);">
              <x-store.icon name="check-circle" class="w-10 h-10" stroke="1.75" />
            </div>
          </div>

          <div class="flex justify-between items-center flex-wrap gap-2 mb-4">
            <div>
              <h5 class="font-semibold m-0">{{ __('messages.OrderPlaced') }}</h5>
              <div class="text-fg-muted text-sm" id="ty-date">—</div>
            </div>
            <div class="chip chip-accent" id="ty-no">#—</div>
          </div>

          <div id="ty-list" class="divide-y divide-line-subtle mb-4"></div>

          <hr class="border-line-subtle my-4">

          <div id="ty-payment-info" class="hidden mb-4">
            <div class="flex items-center gap-3 p-3 rounded-lg" style="background: rgb(var(--color-bg-muted));">
              <span id="ty-payment-icon" class="text-xl"></span>
              <div>
                <div class="font-semibold text-sm" id="ty-payment-label"></div>
                <div class="text-xs" id="ty-payment-status"></div>
              </div>
            </div>
          </div>

          {{-- Offline payment: how to pay + where to send the proof --}}
          @foreach($manualMethods as $code => $m)
            @if($m['requires_proof'])
            <div class="hidden ty-pay-block rounded-xl border border-line-subtle p-4 mb-4" data-method="{{ $code }}">
              <div class="font-semibold text-sm mb-2 flex items-center gap-2">
                <x-store.icon name="info" class="w-4 h-4 text-accent-500" />
                {{ __('messages.HowToPayWith', ['method' => $m['label']]) }}
              </div>
              @include('store.partials.manual-payment-details', ['method' => $m])
              <a href="#" class="btn btn-primary btn-sm ty-proof-link">
                <x-store.icon name="camera" class="w-4 h-4" />{{ __('messages.UploadProofOfPayment') }}
              </a>
            </div>
            @endif
          @endforeach

          {{-- Pickup: where to collect --}}
          <div id="ty-pickup-block" class="hidden rounded-xl border border-line-subtle p-4 mb-4">
            <div class="font-semibold text-sm mb-1 flex items-center gap-2">
              <x-store.icon name="map-pin" class="w-4 h-4 text-accent-500" />{{ __('messages.CollectAtBranch') }}
            </div>
            <div class="text-sm" id="ty-pickup-name"></div>
            <div class="text-xs text-fg-muted" id="ty-pickup-address"></div>
            <div class="text-xs text-fg-muted" id="ty-pickup-hours"></div>
          </div>

          <div class="space-y-2">
            <div class="flex justify-between text-sm text-fg-muted">
              <span>{{ __('messages.Subtotal') }}</span>
              <strong class="text-fg-primary" id="ty-subtotal">{{ $currency }}0.00</strong>
            </div>
            <div class="flex justify-between text-lg font-bold">
              <span>{{ __('messages.GrandTotal') }}</span>
              <strong class="text-accent-500" id="ty-grand">{{ $currency }}0.00</strong>
            </div>
          </div>

          <div class="mt-6 flex gap-2 flex-wrap">
            <a href="{{ route('store.shop') }}" class="btn btn-primary">
              <x-store.icon name="bag" class="w-4 h-4" />{{ __('messages.ContinueShopping') }}
            </a>
            <button class="btn btn-outline" id="ty-print">
              <x-store.icon name="printer" class="w-4 h-4" />{{ __('messages.Print') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="text-xs text-fg-muted text-center mt-3">
      {{ __('messages.ReceiptSavedInBrowser') }}
    </div>
  </div>
</div>

<style>
  .ty-thumb { width:54px; height:54px; object-fit:cover; border-radius:.5rem; border:1px solid rgb(var(--color-border-subtle)); }
  .ty-line  { display:flex; align-items:center; gap:.75rem; padding:.6rem 0; }
</style>

<script>
(function(){
  const CURRENCY = document.querySelector('meta[name="currency"]')?.content || @json($currency);
  const PRICE_DECIMALS = parseInt(document.querySelector('meta[name="price-decimals"]')?.content, 10) || 2;
  const CURRENCY_RATE = parseFloat(document.querySelector('meta[name="currency-rate"]')?.content) || 1;
  const NOIMG    = @json(asset('images/products/no-image.png'));
  const fmt = v => CURRENCY + (Number(v || 0) * CURRENCY_RATE).toLocaleString('en-US', { minimumFractionDigits: PRICE_DECIMALS, maximumFractionDigits: PRICE_DECIMALS });
  function esc(s){ return String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  const empty   = document.getElementById('ty-empty');
  const wrap    = document.getElementById('ty-receipt');
  const noEl    = document.getElementById('ty-no');
  const dateEl  = document.getElementById('ty-date');
  const listEl  = document.getElementById('ty-list');
  const subEl   = document.getElementById('ty-subtotal');
  const grandEl = document.getElementById('ty-grand');
  const printBtn= document.getElementById('ty-print');

  let rec = null;
  try { rec = JSON.parse(localStorage.getItem('shop.last_order') || 'null'); } catch(e){ rec = null; }

  // Landing here from a captured/verified gateway payment (PayPal, Paystack,
  // Flutterwave): the receipt was pending before the redirect — reflect paid.
  const gwQuery = new URLSearchParams(window.location.search);
  if (rec && ['paypal', 'paystack', 'flutterwave', 'razorpay', 'bkash', 'sslcommerz'].some(g => gwQuery.get(g) === 'paid')) {
    rec.payment_status = 'paid';
    try { localStorage.setItem('shop.last_order', JSON.stringify(rec)); } catch(e){}
    try { localStorage.removeItem('shop.cart.backup'); } catch(e){}
  }

  if (!rec || !Array.isArray(rec.items) || !rec.items.length){
    empty.classList.remove('hidden');
    return;
  }

  wrap.classList.remove('hidden');
  noEl.textContent = '#' + (rec.order_no || '—');

  const dt = new Date(rec.placed_at || Date.now());
  const htmlLang = document.documentElement.getAttribute('lang') || undefined;
  dateEl.textContent = htmlLang ? dt.toLocaleString(htmlLang) : dt.toLocaleString();

  listEl.innerHTML = '';
  rec.items.forEach(it => {
    const name = esc(it.name || '');
    const line = document.createElement('div');
    line.className = 'ty-line';
    line.innerHTML = `
      <img class="ty-thumb" src="${esc(it.image || NOIMG)}" alt="${name}">
      <div class="flex-1 min-w-0">
        <div class="font-semibold truncate text-fg-primary" title="${name}">${name}</div>
        <div class="text-xs text-fg-muted">{{ __('messages.Qty') }}: ${Number(it.qty||0)}</div>
      </div>
      <div class="font-semibold">${fmt((Number(it.price)||0) * (Number(it.qty)||0))}</div>
    `;
    listEl.appendChild(line);
  });

  const subtotal = Number(rec.totals?.subtotal || 0);
  subEl.textContent   = fmt(subtotal);
  grandEl.textContent = fmt(Number(rec.totals?.grand ?? subtotal));

  const paymentInfo  = document.getElementById('ty-payment-info');
  const paymentIcon  = document.getElementById('ty-payment-icon');
  const paymentLabel = document.getElementById('ty-payment-label');
  const paymentStat  = document.getElementById('ty-payment-status');

  if (rec.payment_method && paymentInfo) {
    paymentInfo.classList.remove('hidden');
    const methodMap = {
      credit_card:    { icon: '💳', label: @json(__('messages.CreditCard')) },
      mobile_money:   { icon: '📱', label: @json(__('messages.MobileMoney')) },
      cod:            { icon: '💵', label: @json(__('messages.CashOnDelivery')) },
      gcash:          { icon: '📲', label: @json(__('messages.GCash')) },
      bank_transfer:  { icon: '🏦', label: @json(__('messages.BankTransfer')) },
      cash_on_pickup: { icon: '🏬', label: @json(__('messages.CashOnPickup')) }
    };
    const pm = methodMap[rec.payment_method] || { icon: '💰', label: rec.payment_method };
    paymentIcon.textContent  = pm.icon;
    paymentLabel.textContent = pm.label;

    const statusMap = {
      paid:    { text: @json(__('messages.Paid')),          cls: 'text-success' },
      pending: { text: @json(__('messages.PaymentPending')), cls: 'text-warning' }
    };
    const ps = statusMap[rec.payment_status] || statusMap.pending;
    paymentStat.textContent = ps.text;
    paymentStat.className   = 'text-xs ' + ps.cls;
  }

  // Offline methods: reveal the matching pay-now block and point the upload
  // link at this order. Pickup: fetch the branch the shopper chose.
  (function offlineExtras(){
    const ORDER_URL_BASE = @json(url('/'.store_path_to('account/orders')));
    const MY_ORDER_BASE  = @json(url('/'.store_path_to('my/orders')));

    const block = document.querySelector('.ty-pay-block[data-method="'+ rec.payment_method +'"]');
    if (block) {
      block.classList.remove('hidden');
      const link = block.querySelector('.ty-proof-link');
      if (link && rec.order_id) link.href = ORDER_URL_BASE + '/' + rec.order_id + '#payment-proof';
    }

    if (rec.payment_method !== 'cash_on_pickup' || !rec.order_id) return;

    fetch(MY_ORDER_BASE + '/' + rec.order_id, { headers: { 'Accept': 'application/json' } })
      .then(r => r.ok ? r.json() : null)
      .then(o => {
        const b = o && o.pickup_branch;
        if (!b) return;
        document.getElementById('ty-pickup-block').classList.remove('hidden');
        document.getElementById('ty-pickup-name').textContent = b.name || '';
        document.getElementById('ty-pickup-address').textContent = b.address || '';
        document.getElementById('ty-pickup-hours').textContent = b.hours
          ? (@json(__('messages.PickupHours')) + ': ' + b.hours) : '';
      })
      .catch(() => {});
  })();

  printBtn?.addEventListener('click', () => window.print());
})();
</script>

@endsection
