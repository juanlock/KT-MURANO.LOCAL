@extends('layouts.store')

@section('content')
@php
  use App\Models\StoreSetting;
  $s = $s ?? StoreSetting::first();
  $currency = $s->currency_code ?? '$';
@endphp

<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6 flex items-center justify-between flex-wrap gap-3">
    <div>
      <span class="section-kicker">{{ __('messages.Account') }}</span>
      <h1 class="section-title mt-1">{{ __('messages.MyWallet') }}</h1>
      <div class="text-fg-muted text-sm mt-1">{{ __('messages.WalletHint') }}</div>
    </div>
    <a href="{{ route('account.orders') }}" class="btn btn-outline">
      <x-store.icon name="package" class="w-4 h-4" />{{ __('messages.MyOrders') }}
    </a>
  </div>
</section>

<div class="container py-8" id="wallet-app">
  <div class="account-layout">
    @include('store.partials.account-nav')
    <div>

  <div id="wal-loading" class="text-center text-fg-muted py-10">
    <span class="spinner spinner-lg"></span>
  </div>

  <div id="wal-disabled" class="empty-state py-12 hidden">
    <div class="empty-icon"><x-store.icon name="wallet" class="w-10 h-10" /></div>
    <h3>{{ __('messages.WalletUnavailable') }}</h3>
  </div>

  <div id="wal-content" class="hidden">
    <div class="grid gap-6" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">

      <!-- Balance -->
      <div class="card">
        <div class="card-body">
          <div class="text-fg-muted text-sm">{{ __('messages.WalletBalance') }}</div>
          <div id="wal-balance" style="font-size:2rem;font-weight:800;margin-top:.25rem">—</div>
          <div id="wal-frozen" class="chip chip-warning mt-2 hidden">{{ __('messages.WalletFrozen') }}</div>
        </div>
      </div>

      <!-- Redeem gift card -->
      <div class="card">
        <div class="card-body">
          <div class="text-fg-muted text-sm mb-2">{{ __('messages.RedeemGiftCard') }}</div>
          <div class="flex gap-2">
            <input id="wal-code" type="text" class="input flex-1" placeholder="{{ __('messages.GiftCardCode') }}" style="text-transform:uppercase" />
            <button id="wal-redeem-btn" class="btn btn-primary">{{ __('messages.Redeem') }}</button>
          </div>
          <div id="wal-redeem-msg" class="text-sm mt-2"></div>
        </div>
      </div>

      <!-- Withdraw -->
      <div class="card hidden" id="wal-withdraw-card">
        <div class="card-body">
          <div class="text-fg-muted text-sm mb-2">{{ __('messages.RequestWithdrawal') }}</div>
          <div class="flex gap-2">
            <input id="wal-wd-amount" type="number" step="0.01" min="0" class="input flex-1" placeholder="{{ __('messages.Amount') }}" />
            <button id="wal-wd-btn" class="btn btn-outline">{{ __('messages.Request') }}</button>
          </div>
          <div id="wal-wd-hint" class="text-xs text-fg-muted mt-1"></div>
          <div id="wal-wd-msg" class="text-sm mt-2"></div>
        </div>
      </div>
    </div>

    <!-- Transactions -->
    <div class="card mt-6">
      <div class="card-body">
        <h3 class="font-semibold mb-3">{{ __('messages.RecentTransactions') }}</h3>
        <div id="wal-tx-empty" class="text-fg-muted text-sm py-6 hidden">{{ __('messages.NoTransactionsYet') }}</div>
        <div class="overflow-x-auto hidden" id="wal-tx-wrap">
          <table class="w-full text-sm">
            <thead class="bg-bg-muted text-fg-secondary">
              <tr>
                <th class="text-start font-medium px-4 py-2">{{ __('messages.Date') }}</th>
                <th class="text-start font-medium px-4 py-2">{{ __('messages.Description') }}</th>
                <th class="text-end font-medium px-4 py-2">{{ __('messages.Amount') }}</th>
                <th class="text-end font-medium px-4 py-2">{{ __('messages.Balance') }}</th>
              </tr>
            </thead>
            <tbody id="wal-tx-rows" class="divide-y divide-line-subtle"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
    </div>
  </div>
</div>

<script>
(function(){
  const cur = document.querySelector('meta[name="currency"]')?.content || '{{ $currency }}';
  const PRICE_DECIMALS = parseInt(document.querySelector('meta[name="price-decimals"]')?.content, 10) || 2;
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

  const loading = document.getElementById('wal-loading');
  const disabled = document.getElementById('wal-disabled');
  const content = document.getElementById('wal-content');

  const SOURCE = {
    checkout: @json(__('messages.WalletSourceCheckout')),
    pos_sale: @json(__('messages.WalletSourcePos')),
    refund: @json(__('messages.WalletSourceRefund')),
    withdrawal: @json(__('messages.WalletSourceWithdrawal')),
    adjustment: @json(__('messages.WalletSourceAdjustment')),
    gift_card: @json(__('messages.WalletSourceGiftCard')),
  };

  let minWithdrawal = 0;

  function money(n){ return cur + Number(n||0).toLocaleString('en-US', { minimumFractionDigits: PRICE_DECIMALS, maximumFractionDigits: PRICE_DECIMALS }); }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function fmtDate(s){ return s ? String(s).substring(0,16).replace('T',' ') : '—'; }

  function renderSummary(d){
    document.getElementById('wal-balance').textContent = money(d.balance);
    if (d.status === 'frozen') document.getElementById('wal-frozen').classList.remove('hidden');

    if (d.withdrawal_enabled){
      document.getElementById('wal-withdraw-card').classList.remove('hidden');
      minWithdrawal = Number(d.min_withdrawal || 0);
      if (minWithdrawal > 0) document.getElementById('wal-wd-hint').textContent =
        @json(__('messages.MinWithdrawalHint')).replace(':min', money(minWithdrawal));
    }

    const rows = Array.isArray(d.transactions) ? d.transactions : [];
    const wrap = document.getElementById('wal-tx-wrap');
    const empty = document.getElementById('wal-tx-empty');
    if (!rows.length){ empty.classList.remove('hidden'); return; }
    document.getElementById('wal-tx-rows').innerHTML = rows.map(t => `
      <tr>
        <td class="px-4 py-3 text-fg-muted">${fmtDate(t.created_at)}</td>
        <td class="px-4 py-3">${esc(SOURCE[t.source]||t.source)}${t.note ? `<div class="text-xs text-fg-muted">${esc(t.note)}</div>` : ''}</td>
        <td class="text-end px-4 py-3 font-semibold" style="color:${t.type==='credit' ? 'var(--color-success,green)' : 'var(--color-danger,#dc2626)'}">
          ${t.type==='credit' ? '+' : '-'}${money(t.amount)}
        </td>
        <td class="text-end px-4 py-3 text-fg-muted">${money(t.balance_after)}</td>
      </tr>`).join('');
    wrap.classList.remove('hidden');
  }

  async function load(){
    try {
      const res = await fetch('{{ route('my_wallet.summary') }}', { headers:{'Accept':'application/json'} });
      loading.classList.add('hidden');
      if (res.status === 403){ disabled.classList.remove('hidden'); return; }
      if (!res.ok) throw new Error('not ok');
      const d = await res.json();
      content.classList.remove('hidden');
      renderSummary(d);
    } catch(e){
      loading.classList.add('hidden');
      disabled.classList.remove('hidden');
    }
  }

  async function post(url, body){
    const res = await fetch(url, {
      method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF},
      body: JSON.stringify(body||{})
    });
    const d = await res.json().catch(()=>({}));
    return { ok: res.ok, d };
  }

  document.getElementById('wal-redeem-btn').addEventListener('click', async function(){
    const codeEl = document.getElementById('wal-code');
    const msg = document.getElementById('wal-redeem-msg');
    const code = (codeEl.value||'').trim();
    if (!code) return;
    this.disabled = true; msg.textContent = '';
    const { ok, d } = await post('{{ route('my_wallet.redeem') }}', { code });
    this.disabled = false;
    if (ok){
      msg.style.color = 'var(--color-success,green)';
      msg.textContent = @json(__('messages.GiftCardRedeemedMsg')).replace(':amount', money(d.credited));
      codeEl.value = '';
      load();
    } else {
      msg.style.color = 'var(--color-danger,#dc2626)';
      msg.textContent = d.error || @json(__('messages.GiftCardInvalid'));
    }
  });

  const wdBtn = document.getElementById('wal-wd-btn');
  if (wdBtn) wdBtn.addEventListener('click', async function(){
    const amtEl = document.getElementById('wal-wd-amount');
    const msg = document.getElementById('wal-wd-msg');
    const amount = Number(amtEl.value||0);
    if (!amount || amount <= 0) return;
    this.disabled = true; msg.textContent = '';
    const { ok, d } = await post('{{ route('my_wallet.withdraw') }}', { amount });
    this.disabled = false;
    if (ok){
      msg.style.color = 'var(--color-success,green)';
      msg.textContent = @json(__('messages.WithdrawalRequestedMsg'));
      amtEl.value = '';
      load();
    } else {
      msg.style.color = 'var(--color-danger,#dc2626)';
      msg.textContent = d.error || @json(__('messages.Failed'));
    }
  });

  load();
})();
</script>
@endsection
