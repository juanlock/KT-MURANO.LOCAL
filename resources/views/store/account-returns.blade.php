@extends('layouts.store')

@section('content')
@php
  use App\Models\StoreSetting;
  $s = $s ?? StoreSetting::first();
  // Multi-Currency: refund amounts from the API are base-currency and the
  // list may span orders placed in different currencies — render in base.
  $currency = \App\Services\StoreCurrencyService::base()['symbol'];
@endphp

<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6 flex items-center justify-between flex-wrap gap-3">
    <div>
      <span class="section-kicker">{{ __('messages.Account') }}</span>
      <h1 class="section-title mt-1">{{ __('messages.MyReturns') }}</h1>
      <div class="text-fg-muted text-sm mt-1">{{ __('messages.ReturnsHistoryHint') }}</div>
    </div>
    <a href="{{ route('account.orders') }}" class="btn btn-outline">
      <x-store.icon name="package" class="w-4 h-4" />{{ __('messages.MyOrders') }}
    </a>
  </div>
</section>

<div class="container py-8" id="returns-app">
  <div class="account-layout">
    @include('store.partials.account-nav')
    <div>
  <div class="card">
    <div class="card-body">

      <div id="ret-loading" class="text-center text-fg-muted py-10">
        <span class="spinner spinner-lg"></span>
      </div>

      <div id="ret-empty" class="empty-state py-12 hidden">
        <div class="empty-icon"><x-store.icon name="rotate-ccw" class="w-10 h-10" /></div>
        <h3>{{ __('messages.NoReturnsYet') }}</h3>
        <p>{{ __('messages.NoReturnsHint') }}</p>
        <a href="{{ route('account.orders') }}" class="btn btn-outline mt-2">
          <x-store.icon name="package" class="w-4 h-4" />{{ __('messages.MyOrders') }}
        </a>
      </div>

      <div class="overflow-x-auto hidden" id="ret-table-wrap">
        <table class="w-full text-sm">
          <thead class="bg-bg-muted text-fg-secondary">
            <tr>
              <th class="text-start font-medium px-4 py-2">{{ __('messages.Order') }}</th>
              <th class="text-start font-medium px-4 py-2">{{ __('messages.ReturnType') }}</th>
              <th class="text-center font-medium px-4 py-2">{{ __('messages.Items') }}</th>
              <th class="text-end font-medium px-4 py-2">{{ __('messages.Refund') }}</th>
              <th class="text-center font-medium px-4 py-2">{{ __('messages.Status') }}</th>
              <th class="text-start font-medium px-4 py-2">{{ __('messages.Date') }}</th>
              <th class="text-end font-medium px-4 py-2">{{ __('messages.Action') }}</th>
            </tr>
          </thead>
          <tbody id="ret-rows" class="divide-y divide-line-subtle"></tbody>
        </table>
      </div>

    </div>
  </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Base symbol, NOT the session display currency — the amounts are base.
  const cur = @json($currency);
  const PRICE_DECIMALS = parseInt(document.querySelector('meta[name="price-decimals"]')?.content, 10) || 2;
  const loading = document.getElementById('ret-loading');
  const empty   = document.getElementById('ret-empty');
  const wrap    = document.getElementById('ret-table-wrap');
  const rowsEl  = document.getElementById('ret-rows');

  const TYPE = { cancellation: @json(__('messages.Cancellation')), return: @json(__('messages.ReturnLabel')) };
  const STATUS = {
    requested: @json(__('messages.ReturnStatusRequested')),
    approved:  @json(__('messages.ReturnStatusApproved')),
    rejected:  @json(__('messages.ReturnStatusRejected')),
    refunded:  @json(__('messages.ReturnStatusRefunded')),
  };
  const orderBase = '{{ url('/'.store_path_to('account/orders')) }}';

  function money(n){ return cur + Number(n||0).toLocaleString('en-US', { minimumFractionDigits: PRICE_DECIMALS, maximumFractionDigits: PRICE_DECIMALS }); }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function statusChip(st){
    const cls = st==='refunded' ? 'chip-success' : st==='approved' ? 'chip-info' : st==='rejected' ? 'chip-danger' : 'chip-warning';
    return `<span class="chip ${cls}">${esc(STATUS[st]||st)}</span>`;
  }
  function fmtDate(s){ return s ? String(s).substring(0,10) : '—'; }

  async function load(){
    try {
      const res = await fetch('{{ route('my_returns.index') }}', { headers:{'Accept':'application/json'} });
      if (!res.ok) throw new Error('not ok');
      const d = await res.json();
      const rows = Array.isArray(d.data) ? d.data : [];
      loading.classList.add('hidden');

      if (!rows.length){ empty.classList.remove('hidden'); return; }

      rowsEl.innerHTML = rows.map(r => `
        <tr>
          <td class="px-4 py-3"><a class="text-accent-500 hover:underline font-medium" href="${orderBase}/${r.order_id}">${esc(r.order_ref)}</a></td>
          <td class="px-4 py-3">${esc(TYPE[r.type]||r.type)}</td>
          <td class="text-center px-4 py-3">${r.type==='cancellation' ? '—' : Number(r.items_count||0)}</td>
          <td class="text-end px-4 py-3 font-semibold">${r.refund_amount>0 ? money(r.refund_amount) : '—'}</td>
          <td class="text-center px-4 py-3">${statusChip(r.status)}</td>
          <td class="px-4 py-3 text-fg-muted">${fmtDate(r.created_at)}</td>
          <td class="text-end px-4 py-3">
            <a class="btn btn-outline btn-sm" href="${orderBase}/${r.order_id}">
              {{ __('messages.View') }}
            </a>
          </td>
        </tr>`).join('');
      wrap.classList.remove('hidden');
    } catch(e){
      loading.classList.add('hidden');
      empty.classList.remove('hidden');
    }
  }

  load();
})();
</script>
@endsection
