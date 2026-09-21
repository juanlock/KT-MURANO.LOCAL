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
      <h1 class="section-title mt-1">{{ __('messages.MyRewards') }}</h1>
      <div class="text-fg-muted text-sm mt-1">{{ __('messages.LoyaltyHint') }}</div>
    </div>
    <a href="{{ route('account.orders') }}" class="btn btn-outline">
      <x-store.icon name="package" class="w-4 h-4" />{{ __('messages.MyOrders') }}
    </a>
  </div>
</section>

<div class="container py-8" id="loyalty-app">
  <div class="account-layout">
    @include('store.partials.account-nav')
    <div>

  <div id="loy-loading" class="text-center text-fg-muted py-10"><span class="spinner spinner-lg"></span></div>

  <div id="loy-content" class="hidden">
    <div class="card mb-6">
      <div class="card-body flex items-center justify-between flex-wrap gap-3">
        <div>
          <div class="text-fg-muted text-sm">{{ __('messages.PointsBalance') }}</div>
          <div id="loy-points" style="font-size:2rem;font-weight:800">—</div>
        </div>
        <div id="loy-ineligible" class="chip chip-warning hidden">{{ __('messages.LoyaltyNotEligible') }}</div>
      </div>
    </div>

    <h3 class="font-semibold mb-3">{{ __('messages.AvailableRewards') }}</h3>
    <div id="loy-rewards-empty" class="text-fg-muted text-sm py-6 hidden">{{ __('messages.NoRewardsAvailable') }}</div>
    <div id="loy-rewards" class="grid gap-4 mb-8" style="grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));"></div>

    <h3 class="font-semibold mb-3">{{ __('messages.MyRedemptions') }}</h3>
    <div id="loy-hist-empty" class="text-fg-muted text-sm py-6 hidden">{{ __('messages.NoRedemptionsYet') }}</div>
    <div class="overflow-x-auto hidden" id="loy-hist-wrap">
      <table class="w-full text-sm">
        <thead class="bg-bg-muted text-fg-secondary">
          <tr>
            <th class="text-start font-medium px-4 py-2">{{ __('messages.Date') }}</th>
            <th class="text-start font-medium px-4 py-2">{{ __('messages.Reward') }}</th>
            <th class="text-end font-medium px-4 py-2">{{ __('messages.Points') }}</th>
            <th class="text-start font-medium px-4 py-2">{{ __('messages.Code') }}</th>
            <th class="text-center font-medium px-4 py-2">{{ __('messages.Status') }}</th>
          </tr>
        </thead>
        <tbody id="loy-hist-rows" class="divide-y divide-line-subtle"></tbody>
      </table>
    </div>
  </div>
    </div>
  </div>
</div>

<script>
(function(){
  const cur = document.querySelector('meta[name="currency"]')?.content || '{{ $currency }}';
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const loading = document.getElementById('loy-loading');
  const content = document.getElementById('loy-content');

  const TYPE = { gift_card: @json(__('messages.RewardGiftCard')), voucher: @json(__('messages.RewardVoucher')), product: @json(__('messages.RewardProduct')) };
  const STATUS = { issued: @json(__('messages.Issued')), fulfilled: @json(__('messages.Fulfilled')), cancelled: @json(__('messages.Cancelled')) };

  let currentPoints = 0;

  function money(n){ return cur + Number(n||0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function pts(n){ return Number(n||0).toLocaleString(); }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function fmtDate(s){ return s ? String(s).substring(0,16).replace('T',' ') : '—'; }

  function rewardCard(r){
    const affordable = currentPoints >= Number(r.points_cost);
    const valueLine = r.type === 'product' ? '' : `<div class="text-xs text-fg-muted mt-1">${esc(TYPE[r.type]||r.type)} · ${money(r.value)}</div>`;
    return `
      <div class="card">
        <div class="card-body">
          <div class="font-semibold">${esc(r.name)}</div>
          ${r.description ? `<div class="text-sm text-fg-muted mt-1">${esc(r.description)}</div>` : ''}
          ${valueLine}
          <div class="flex items-center justify-between mt-3">
            <span class="font-bold">${pts(r.points_cost)} {{ __('messages.Points') }}</span>
            <button class="btn btn-primary btn-sm loy-redeem" data-id="${r.id}" ${affordable ? '' : 'disabled'}>
              {{ __('messages.Redeem') }}
            </button>
          </div>
        </div>
      </div>`;
  }

  function render(d){
    currentPoints = Number(d.points || 0);
    document.getElementById('loy-points').textContent = pts(currentPoints);
    if (!d.eligible) document.getElementById('loy-ineligible').classList.remove('hidden');

    const rewards = Array.isArray(d.rewards) ? d.rewards : [];
    const grid = document.getElementById('loy-rewards');
    if (!rewards.length){ document.getElementById('loy-rewards-empty').classList.remove('hidden'); }
    else { grid.innerHTML = rewards.map(rewardCard).join(''); }

    const tx = Array.isArray(d.transactions) ? d.transactions : [];
    loadHistory();
  }

  async function loadHistory(){
    try {
      const res = await fetch('{{ route('my_loyalty.redemptions') }}', { headers:{'Accept':'application/json'} });
      const d = await res.json();
      const rows = Array.isArray(d.data) ? d.data : [];
      if (!rows.length){ document.getElementById('loy-hist-empty').classList.remove('hidden'); return; }
      document.getElementById('loy-hist-rows').innerHTML = rows.map(r => `
        <tr>
          <td class="px-4 py-3 text-fg-muted">${fmtDate(r.created_at)}</td>
          <td class="px-4 py-3">${esc(r.reward_name)} <span class="text-xs text-fg-muted">(${esc(TYPE[r.reward_type]||r.reward_type)})</span></td>
          <td class="text-end px-4 py-3">${pts(r.points_spent)}</td>
          <td class="px-4 py-3 font-mono">${esc(r.code || '—')}</td>
          <td class="text-center px-4 py-3">${esc(STATUS[r.status]||r.status)}</td>
        </tr>`).join('');
      document.getElementById('loy-hist-wrap').classList.remove('hidden');
    } catch(e){ /* silent */ }
  }

  async function load(){
    try {
      const res = await fetch('{{ route('my_loyalty.summary') }}', { headers:{'Accept':'application/json'} });
      loading.classList.add('hidden');
      if (!res.ok) throw new Error('not ok');
      const d = await res.json();
      content.classList.remove('hidden');
      render(d);
    } catch(e){ loading.classList.add('hidden'); }
  }

  document.addEventListener('click', async function(e){
    const btn = e.target.closest('.loy-redeem');
    if (!btn) return;
    btn.disabled = true;
    try {
      const res = await fetch('{{ route('my_loyalty.redeem') }}', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify({ reward_id: Number(btn.dataset.id) })
      });
      const d = await res.json().catch(()=>({}));
      if (res.ok){
        let msg = @json(__('messages.RewardRedeemedMsg'));
        if (d.code) msg += ' ' + @json(__('messages.YourCode')) + ': ' + d.code;
        alert(msg);
        load();
      } else {
        alert(d.error || @json(__('messages.Failed')));
        btn.disabled = false;
      }
    } catch(err){ btn.disabled = false; }
  });

  load();
})();
</script>
@endsection
