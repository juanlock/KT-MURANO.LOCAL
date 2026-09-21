@extends('layouts.store')

@section('content')
@php
  use App\Models\StoreSetting;

  $s = $s ?? StoreSetting::first();

  // Amounts on this page belong to ONE stored order — use its snapshotted currency.
  $orderCurrency = \App\Services\StoreCurrencyService::forDocument(
      \App\Models\OnlineOrder::where('client_id', (int) optional(auth('store')->user())->client_id)
          ->find($id ?? request()->route('id'))
  );
  $currency = $orderCurrency['symbol'];
@endphp

<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6">
    <div class="flex items-center justify-between flex-wrap gap-2">
      <div>
        <a href="{{ route('account.orders') }}" class="inline-flex items-center gap-2 text-sm text-fg-secondary hover:text-accent-500">
          <x-store.icon name="arrow-left" class="w-4 h-4" />{{ __('messages.BackToOrders') }}
        </a>
        <h1 class="section-title mt-2">{{ __('messages.OrderDetails') }}</h1>
      </div>
      <div class="flex gap-2">
        <a class="btn btn-outline btn-sm" href="{{ route('account.order.invoice', ['id' => $id ?? request()->route('id')]) }}">
          <x-store.icon name="file-text" class="w-4 h-4" />{{ __('messages.DownloadInvoice') }}
        </a>
        <button class="btn btn-outline btn-sm" id="btnPrint">
          <x-store.icon name="printer" class="w-4 h-4" />{{ __('messages.Print') }}
        </button>
      </div>
    </div>
  </div>
</section>

<div class="container py-8" id="order-app" data-order-id="{{ $id ?? request()->route('id') }}">
  <div class="max-w-5xl mx-auto">

    {{-- Header card --}}
    <div class="card mb-4">
      <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="text-fg-muted text-xs uppercase tracking-wide">{{ __('messages.Order') }}</div>
            <h3 class="text-xl font-bold m-0 mt-1">
              <span id="o-code">—</span>
            </h3>
            <div class="text-sm text-fg-muted mt-1">
              <span id="o-date">—</span> · <span id="o-time">—</span>
            </div>
          </div>
          <div class="text-end">
            <span id="o-status-badge" class="chip">—</span>
            <div class="text-xs text-fg-muted mt-2 flex items-center gap-1 justify-end">
              <x-store.icon name="package" class="w-3.5 h-3.5" />
              <span id="o-warehouse">—</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    {{-- Items + Summary --}}
    <div class="grid lg:grid-cols-[1.4fr_1fr] gap-4">
      <div>
        <div class="card">
          <div class="card-body p-0">
            <div class="px-4 py-3 border-b border-line-subtle">
              <h6 class="font-semibold m-0">{{ __('messages.Items') }}</h6>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-bg-muted text-fg-secondary">
                  <tr>
                    <th class="text-start font-medium px-4 py-2">{{ __('messages.Product') }}</th>
                    <th class="text-center font-medium px-4 py-2" style="width:100px">{{ __('messages.Qty') }}</th>
                    <th class="text-end font-medium px-4 py-2" style="width:120px">{{ __('messages.Price') }}</th>
                    <th class="text-end font-medium px-4 py-2" style="width:140px">{{ __('messages.Total') }}</th>
                  </tr>
                </thead>
                <tbody id="o-items" class="divide-y divide-line-subtle">
                  <tr><td colspan="4" class="text-center text-fg-muted py-6">—</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="space-y-4">
        <div class="card">
          <div class="card-body">
            <h6 class="font-semibold mb-3">{{ __('messages.Summary') }}</h6>
            <ul class="list-none p-0 m-0 space-y-2">
              <li class="flex justify-between text-sm text-fg-muted">
                <span>{{ __('messages.Subtotal') }}</span>
                <strong class="text-fg-primary" id="o-subtotal">{{ $currency }}0.00</strong>
              </li>
              <li class="flex justify-between text-sm text-fg-muted">
                <span>{{ __('messages.Shipping') }}</span>
                <strong class="text-fg-primary" id="o-shipping">{{ $currency }}0.00</strong>
              </li>
              <li class="flex justify-between text-sm text-fg-muted">
                <span>{{ __('messages.Discount') }}</span>
                <strong class="text-fg-primary" id="o-discount">-{{ $currency }}0.00</strong>
              </li>
              <li class="flex justify-between text-lg font-bold border-t border-line-subtle pt-3 mt-2">
                <span>{{ __('messages.Total') }}</span>
                <strong class="text-accent-500" id="o-total">{{ $currency }}0.00</strong>
              </li>
            </ul>
          </div>
        </div>

        {{-- Payment: status, how to pay, proof of payment --}}
        <div class="card hidden" id="o-payment-card">
          <div class="card-body" id="payment-proof">
            <div class="flex items-center justify-between gap-2 mb-3">
              <h6 class="font-semibold m-0">{{ __('messages.Payment') }}</h6>
              <span class="chip" id="o-payment-status">—</span>
            </div>
            <div class="text-sm text-fg-muted mb-3" id="o-payment-method">—</div>

            {{-- Account details to pay to (offline methods) --}}
            <div id="o-payment-instructions" class="hidden mb-3"></div>

            {{-- What the customer already submitted --}}
            <div id="o-proof-list" class="space-y-2 mb-3"></div>

            {{-- Upload form --}}
            <div id="o-proof-form" class="hidden rounded-xl border border-line-subtle p-3">
              <div class="text-xs font-semibold text-fg-secondary mb-2">{{ __('messages.UploadProofOfPayment') }}</div>
              <div class="space-y-2">
                <div>
                  <label class="form-label text-xs" for="pf-ref">{{ __('messages.ReferenceNumber') }} *</label>
                  <input type="text" id="pf-ref" class="input" autocomplete="off"
                         placeholder="{{ __('messages.ReferenceNumberPlaceholder') }}">
                </div>
                <div>
                  <label class="form-label text-xs" for="pf-amount">{{ __('messages.AmountSent') }} *</label>
                  <input type="number" id="pf-amount" class="input" step="0.01" min="0" placeholder="0.00">
                </div>
                <div>
                  <label class="form-label text-xs" for="pf-date">{{ __('messages.PaymentDate') }}</label>
                  <input type="date" id="pf-date" class="input">
                </div>
                <div>
                  <label class="form-label text-xs" for="pf-file">{{ __('messages.Screenshot') }} *</label>
                  <input type="file" id="pf-file" class="input"
                         accept="image/jpeg,image/png,image/webp,application/pdf">
                  <p class="text-[11px] text-fg-muted mt-1">{{ __('messages.ProofFileHint') }}</p>
                </div>
                <div>
                  <label class="form-label text-xs" for="pf-note">{{ __('messages.Note') }}</label>
                  <textarea id="pf-note" class="input" rows="2"></textarea>
                </div>
              </div>
              <div id="pf-error" class="text-danger text-xs mt-2 hidden"></div>
              <button type="button" id="pf-submit" class="btn btn-primary btn-sm mt-3">
                <x-store.icon name="send" class="w-4 h-4" />{{ __('messages.Submit') }}
              </button>
            </div>
          </div>
        </div>

        {{-- Pickup branch --}}
        <div class="card hidden" id="o-pickup-card">
          <div class="card-body">
            <h6 class="font-semibold mb-2 flex items-center gap-2">
              <x-store.icon name="map-pin" class="w-4 h-4 text-accent-500" />{{ __('messages.CollectAtBranch') }}
            </h6>
            <div class="text-sm font-semibold" id="o-pickup-name">—</div>
            <div class="text-sm text-fg-muted" id="o-pickup-address"></div>
            <div class="text-sm text-fg-muted" id="o-pickup-hours"></div>
            <div class="text-sm text-fg-muted" id="o-pickup-contact"></div>
            <div class="text-xs text-fg-muted mt-2" id="o-pickup-notes"></div>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <h6 class="font-semibold mb-2">{{ __('messages.StatusHelp') }}</h6>
            <ul class="text-sm text-fg-muted m-0 p-0 list-none space-y-1.5">
              <li class="flex items-start gap-2"><span class="chip chip-warning shrink-0">pending</span> <span>{{ __('messages.StatusPendingHelp') }}</span></li>
              <li class="flex items-start gap-2"><span class="chip chip-success shrink-0">confirmed</span> <span>{{ __('messages.StatusConfirmedHelp') }}</span></li>
              <li class="flex items-start gap-2"><span class="chip chip-danger shrink-0">cancelled</span> <span>{{ __('messages.StatusCancelledHelp') }}</span></li>
            </ul>
          </div>
        </div>

        {{-- Returns & cancellations --}}
        <div class="card hidden" id="o-actions">
          <div class="card-body">
            <h6 class="font-semibold mb-2">{{ __('messages.ReturnsAndCancellations') }}</h6>
            <div id="o-open-requests" class="text-sm mb-2"></div>
            <button id="btnCancelOrder" class="btn btn-outline btn-sm hidden">
              <x-store.icon name="x" class="w-4 h-4" />{{ __('messages.CancelOrder') }}
            </button>

            <div id="o-return-block" class="hidden mt-2">
              <div class="text-sm text-fg-muted mb-2">{{ __('messages.SelectItemsToReturn') }}</div>
              <div id="o-return-items" class="space-y-2"></div>
              <textarea id="o-return-reason" class="input mt-2" rows="2" placeholder="{{ __('messages.ReturnReasonPlaceholder') }}"></textarea>
              <button id="btnRequestReturn" class="btn btn-primary btn-sm mt-2">
                <x-store.icon name="rotate-ccw" class="w-4 h-4" />{{ __('messages.RequestReturn') }}
              </button>
            </div>
          </div>
        </div>

        {{-- Product reviews (verified purchase) --}}
        <div class="card hidden" id="o-reviews">
          <div class="card-body">
            <h6 class="font-semibold mb-3">{{ __('messages.RateYourPurchase') }}</h6>
            <div id="o-review-items" class="space-y-4"></div>
          </div>
        </div>
      </div>
    </div>

    {{-- Empty state --}}
    <div id="o-empty" class="empty-state py-12 hidden">
      <div class="empty-icon"><x-store.icon name="receipt" class="w-10 h-10" /></div>
      <h3>{{ __('messages.OrderNotFound') }}</h3>
      <a href="{{ route('account.orders') }}" class="btn btn-outline mt-4">{{ __('messages.BackToOrders') }}</a>
    </div>

  </div>
</div>

<script>
(function(){
  const wrap  = document.getElementById('order-app');
  const id    = wrap?.dataset.orderId;
  const cur   = @json($orderCurrency['symbol']);
  const RATE  = {{ (float) $orderCurrency['rate'] }};
  const PRICE_DECIMALS = parseInt(document.querySelector('meta[name="price-decimals"]')?.content, 10) || 2;

  const el = {
    code:      document.getElementById('o-code'),
    date:      document.getElementById('o-date'),
    time:      document.getElementById('o-time'),
    badge:     document.getElementById('o-status-badge'),
    wh:        document.getElementById('o-warehouse'),
    items:     document.getElementById('o-items'),
    subtotal:  document.getElementById('o-subtotal'),
    shipping:  document.getElementById('o-shipping'),
    discount:  document.getElementById('o-discount'),
    total:     document.getElementById('o-total'),
    empty:     document.getElementById('o-empty'),
  };

  function money(n){ return cur + (Number(n||0) * RATE).toLocaleString('en-US', { minimumFractionDigits: PRICE_DECIMALS, maximumFractionDigits: PRICE_DECIMALS }); }
  function badgeClass(status){
    status = String(status||'').toLowerCase();
    return status === 'pending'   ? 'chip chip-warning'
         : status === 'confirmed' ? 'chip chip-success'
         : status === 'cancelled' ? 'chip chip-danger'
         : 'chip';
  }
  function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  // ---- Payment / proof of payment ----------------------------------------
  const PROOF_URL = `{{ url('/'.store_path_to('my/orders')) }}/${id}/payment-proofs`;

  const METHOD_LABELS = {
    credit_card:    @json(__('messages.CreditCard')),
    mobile_money:   @json(__('messages.MobileMoney')),
    cod:            @json(__('messages.CashOnDelivery')),
    wallet:         @json(__('messages.PayWithWallet')),
    gcash:          @json(__('messages.GCash')),
    bank_transfer:  @json(__('messages.BankTransfer')),
    cash_on_pickup: @json(__('messages.CashOnPickup')),
    bkash:          'bKash',
    sslcommerz:     'SSLCommerz'
  };
  const PROOF_STATUS = {
    pending:  { text: @json(__('messages.AwaitingVerification')), cls: 'chip chip-warning' },
    approved: { text: @json(__('messages.Verified')),             cls: 'chip chip-success' },
    rejected: { text: @json(__('messages.Rejected')),             cls: 'chip chip-danger' }
  };

  function renderPayment(o){
    const card = document.getElementById('o-payment-card');
    if (!card) return;
    card.classList.remove('hidden');

    document.getElementById('o-payment-method').textContent =
      METHOD_LABELS[o.payment_method] || o.payment_method || '—';

    const paid = o.payment_status === 'paid';
    const badge = document.getElementById('o-payment-status');
    badge.className   = paid ? 'chip chip-success' : 'chip chip-warning';
    badge.textContent = paid ? @json(__('messages.Paid')) : @json(__('messages.PaymentPending'));

    // Account details to pay to — only while something is still owed.
    const insWrap = document.getElementById('o-payment-instructions');
    const ins = o.payment_instructions;
    if (ins && !paid) {
      let html = '';
      if (ins.instructions) html += `<div class="text-xs text-fg-muted mb-2">${escapeHtml(ins.instructions)}</div>`;
      (ins.details || []).forEach(d => {
        html += `<div class="flex justify-between gap-2 text-sm py-1 border-b border-line-subtle last:border-0">
                   <span class="text-fg-muted">${escapeHtml(d.label)}</span>
                   <strong class="text-end break-all">${escapeHtml(d.value)}</strong>
                 </div>`;
      });
      if (ins.qr_url) html += `<img src="${escapeHtml(ins.qr_url)}" alt="QR" class="mt-2 rounded-lg border border-line-subtle" style="max-width:160px">`;
      insWrap.innerHTML = html;
      insWrap.classList.toggle('hidden', html === '');
    } else {
      insWrap.classList.add('hidden');
      insWrap.innerHTML = '';
    }

    // Submission history.
    const listEl = document.getElementById('o-proof-list');
    const proofs = Array.isArray(o.payment_proofs) ? o.payment_proofs : [];
    listEl.innerHTML = proofs.map(p => {
      const st = PROOF_STATUS[p.status] || PROOF_STATUS.pending;
      const reason = p.status === 'rejected' && p.reject_reason
        ? `<div class="text-xs text-danger mt-1">${escapeHtml(p.reject_reason)}</div>` : '';
      const file = p.file_url
        ? `<a href="${escapeHtml(p.file_url)}" target="_blank" rel="noopener" class="text-xs text-accent-500">${@json(__('messages.ViewFile'))}</a>` : '';
      return `<div class="rounded-lg border border-line-subtle p-2.5">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-sm font-semibold break-all">${escapeHtml(p.reference_number||'—')}</span>
                  <span class="${st.cls}">${st.text}</span>
                </div>
                <div class="text-xs text-fg-muted mt-1">${money(p.amount)} · ${escapeHtml(p.paid_at||p.submitted_at||'')}</div>
                ${file}${reason}
              </div>`;
    }).join('');

    // Upload form: only for offline methods still awaiting payment, and only
    // when nothing is already queued for review.
    const form = document.getElementById('o-proof-form');
    form.classList.toggle('hidden', !o.can_submit_proof);
    if (o.can_submit_proof) {
      const amt = document.getElementById('pf-amount');
      if (amt && !amt.value) amt.value = Number(o.total || 0).toFixed(PRICE_DECIMALS);
    }
  }

  function renderPickup(o){
    const card = document.getElementById('o-pickup-card');
    const b = o.pickup_branch;
    if (!card) return;
    if (o.delivery_method !== 'pickup' || !b) { card.classList.add('hidden'); return; }

    card.classList.remove('hidden');
    document.getElementById('o-pickup-name').textContent    = b.name || '—';
    document.getElementById('o-pickup-address').textContent = b.address || '';
    document.getElementById('o-pickup-hours').textContent   = b.hours ? (@json(__('messages.PickupHours')) + ': ' + b.hours) : '';
    document.getElementById('o-pickup-contact').textContent = b.contact ? (@json(__('messages.Phone')) + ': ' + b.contact) : '';
    document.getElementById('o-pickup-notes').textContent   = b.notes || '';
  }

  document.getElementById('pf-submit')?.addEventListener('click', async function(){
    const btn = this;
    const err = document.getElementById('pf-error');
    const ref = document.getElementById('pf-ref').value.trim();
    const amount = Number(document.getElementById('pf-amount').value || 0);
    const date = document.getElementById('pf-date').value;
    const note = document.getElementById('pf-note').value.trim();
    const file = document.getElementById('pf-file').files[0];

    err.classList.add('hidden');
    if (!ref || !(amount > 0) || !file) {
      err.textContent = @json(__('messages.ProofIncomplete'));
      err.classList.remove('hidden');
      return;
    }

    const fd = new FormData();
    fd.append('reference_number', ref);
    fd.append('amount', amount);
    if (date) fd.append('paid_at', date);
    if (note) fd.append('note', note);
    fd.append('file', file);

    btn.disabled = true;
    try {
      const res = await fetch(PROOF_URL, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: fd
      });
      const data = await res.json();
      if (!res.ok) throw data;
      await load();
    } catch (e) {
      err.textContent = (e && (e.error || e.message)) || @json(__('messages.CouldNotSubmit'));
      err.classList.remove('hidden');
    } finally {
      btn.disabled = false;
    }
  });

  async function load(){
    if (!id) return showEmpty();
    try{
      const res = await fetch(`{{ url('/'.store_path_to('my/orders')) }}/${id}`, { headers:{'Accept':'application/json'} });
      if (!res.ok) throw new Error('not ok');
      const o = await res.json();

      el.code.textContent = o.code || ('#'+o.id);
      el.date.textContent = o.date || '—';
      el.time.textContent = o.time || '—';
      el.wh.textContent   = o.warehouse_name || '—';

      el.badge.className  = badgeClass(o.status);
      el.badge.textContent= o.status || '—';

      el.items.innerHTML = '';
      if (Array.isArray(o.items) && o.items.length){
        o.items.forEach(it=>{
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td class="px-4 py-3">${escapeHtml(it.name||('#'+it.product_id))}</td>
            <td class="text-center px-4 py-3">${Number(it.qty||0)}</td>
            <td class="text-end px-4 py-3">${money(it.price)}</td>
            <td class="text-end px-4 py-3 font-semibold">${money((it.price||0)*(it.qty||0))}</td>
          `;
          el.items.appendChild(tr);
        });
      } else {
        el.items.innerHTML = `<tr><td colspan="4" class="text-center text-fg-muted py-6">—</td></tr>`;
      }

      el.subtotal.textContent = money(o.subtotal);
      el.shipping.textContent = money(o.shipping||0);
      el.discount.textContent = '-' + money(o.discount||0);
      el.total.textContent    = money(o.total);

      renderPayment(o);
      renderPickup(o);

    } catch(e){
      showEmpty();
    }
  }

  function showEmpty(){
    el.empty?.classList.remove('hidden');
  }

  document.getElementById('btnPrint')?.addEventListener('click', ()=> window.print());

  // ---- Returns & cancellations ----
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const actionsCard = document.getElementById('o-actions');
  const openReqEl   = document.getElementById('o-open-requests');
  const btnCancel   = document.getElementById('btnCancelOrder');
  const returnBlock = document.getElementById('o-return-block');
  const returnItems = document.getElementById('o-return-items');

  async function loadReturns(){
    if (!id) return;
    try {
      const res = await fetch(`{{ url('/'.store_path_to('account/orders')) }}/${id}/return-eligibility`, { headers:{'Accept':'application/json'} });
      if (!res.ok) return;
      const d = await res.json();
      let anything = false;

      if (Array.isArray(d.open_requests) && d.open_requests.length){
        anything = true;
        openReqEl.innerHTML = d.open_requests.map(r =>
          `<div class="chip chip-warning">${escapeHtml(r.type)} · ${escapeHtml(r.status)}</div>`).join(' ');
      }

      if (d.can_cancel && d.can_cancel.ok){
        anything = true;
        btnCancel.classList.remove('hidden');
      }

      if (d.can_return && d.can_return.ok && Array.isArray(d.returnable_items) && d.returnable_items.length){
        anything = true;
        returnBlock.classList.remove('hidden');
        returnItems.innerHTML = d.returnable_items.map(it => `
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" class="ret-check" data-id="${it.online_order_item_id}" data-max="${it.qty}">
            <span class="flex-1">${escapeHtml(it.name)}</span>
            <input type="number" class="input ret-qty" style="width:80px" min="1" max="${it.qty}" value="${it.qty}">
          </label>`).join('');
      }

      if (anything) actionsCard.classList.remove('hidden');
    } catch(e){}
  }

  btnCancel?.addEventListener('click', async ()=>{
    if (!confirm('{{ __("messages.ConfirmCancelOrder") }}')) return;
    btnCancel.disabled = true;
    try {
      const res = await fetch(`{{ url('/'.store_path_to('account/orders')) }}/${id}/cancel`, {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify({})
      });
      const d = await res.json();
      if (!res.ok) throw d;
      alert(d.message || 'OK');
      window.location.reload();
    } catch(err){
      alert((err && err.error) || '{{ __("messages.Failed") }}');
      btnCancel.disabled = false;
    }
  });

  document.getElementById('btnRequestReturn')?.addEventListener('click', async ()=>{
    const items = [];
    returnItems.querySelectorAll('.ret-check:checked').forEach(chk=>{
      const row = chk.closest('label');
      const qty = Math.max(1, parseInt(row.querySelector('.ret-qty').value||'1',10));
      items.push({ online_order_item_id: Number(chk.dataset.id), qty: qty });
    });
    if (!items.length){ alert('{{ __("messages.SelectItemsToReturn") }}'); return; }
    const reason = document.getElementById('o-return-reason').value;
    try {
      const res = await fetch(`{{ url('/'.store_path_to('account/orders')) }}/${id}/return`, {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF},
        body: JSON.stringify({ items: items, reason: reason })
      });
      const d = await res.json();
      if (!res.ok) throw d;
      alert(d.message || 'OK');
      window.location.reload();
    } catch(err){
      alert((err && err.error) || '{{ __("messages.Failed") }}');
    }
  });

  // ---- Product reviews (verified purchase) ----
  const reviewsCard = document.getElementById('o-reviews');
  const reviewItemsEl = document.getElementById('o-review-items');
  const T_RATE = @json(__('messages.YourRating'));
  const T_SUBMIT = @json(__('messages.SubmitReview'));
  const T_COMMENT = @json(__('messages.ReviewCommentPlaceholder'));
  const T_STATUS = { pending: @json(__('messages.ReviewPending')), approved: @json(__('messages.ReviewApproved')), rejected: @json(__('messages.ReviewRejected')) };

  function starPicker(pid, current){
    var html = '<span class="review-stars" data-pid="'+pid+'">';
    for (var i=1;i<=5;i++){
      html += '<button type="button" class="star-btn" data-v="'+i+'" style="font-size:20px;line-height:1;background:none;border:0;cursor:pointer;color:'+(i<=current?'#f5a623':'#ccc')+'">★</button>';
    }
    return html + '</span>';
  }

  async function loadReviews(){
    if (!id) return;
    try {
      const res = await fetch(`{{ url('/'.store_path_to('account/orders')) }}/${id}/reviewable`, { headers:{'Accept':'application/json'} });
      if (!res.ok) return;
      const d = await res.json();
      if (!d.eligible || !Array.isArray(d.items) || !d.items.length) return;

      reviewItemsEl.innerHTML = d.items.map(function(it){
        var mine = it.my_review;
        var rating = mine ? mine.rating : 0;
        var statusBadge = mine ? '<span class="chip chip-'+(mine.status==='approved'?'success':mine.status==='rejected'?'danger':'warning')+' text-xs ml-2">'+(T_STATUS[mine.status]||mine.status)+'</span>' : '';
        return '<div class="review-item border-b border-line-subtle pb-3" data-pid="'+it.product_id+'">'
          + '<div class="text-sm font-medium mb-1">'+escapeHtml(it.name)+statusBadge+'</div>'
          + '<div class="text-xs text-fg-muted mb-1">'+T_RATE+'</div>'
          + starPicker(it.product_id, rating)
          + '<textarea class="input mt-2 review-comment" rows="2" placeholder="'+T_COMMENT+'">'+escapeHtml(mine ? (mine.comment||'') : '')+'</textarea>'
          + '<button class="btn btn-primary btn-sm mt-2 btn-submit-review">'+T_SUBMIT+'</button>'
          + '<span class="review-msg text-xs ml-2"></span>'
          + '</div>';
      }).join('');
      reviewsCard.classList.remove('hidden');
    } catch(e){}
  }

  reviewItemsEl && reviewItemsEl.addEventListener('click', async function(e){
    var starBtn = e.target.closest('.star-btn');
    if (starBtn){
      var wrap = starBtn.closest('.review-stars');
      var v = Number(starBtn.dataset.v);
      wrap.dataset.rating = v;
      wrap.querySelectorAll('.star-btn').forEach(function(b){ b.style.color = Number(b.dataset.v) <= v ? '#f5a623' : '#ccc'; });
      return;
    }
    var submitBtn = e.target.closest('.btn-submit-review');
    if (submitBtn){
      var item = submitBtn.closest('.review-item');
      var pid = Number(item.dataset.pid);
      var stars = item.querySelector('.review-stars');
      var rating = Number(stars.dataset.rating || 0);
      var comment = item.querySelector('.review-comment').value;
      var msg = item.querySelector('.review-msg');
      if (!rating){ msg.textContent = @json(__('messages.PleaseSelectRating')); msg.className='review-msg text-xs ml-2 text-danger'; return; }
      submitBtn.disabled = true;
      try {
        var res = await fetch(`{{ url('/'.store_path_to('account/orders')) }}/${id}/review`, {
          method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF},
          body: JSON.stringify({ product_id: pid, rating: rating, comment: comment })
        });
        var d = await res.json();
        if (!res.ok) throw d;
        msg.textContent = d.message || 'OK';
        msg.className = 'review-msg text-xs ml-2 text-success';
      } catch(err){
        msg.textContent = (err && err.error) || '{{ __("messages.Failed") }}';
        msg.className = 'review-msg text-xs ml-2 text-danger';
      } finally { submitBtn.disabled = false; }
    }
  });

  load();
  loadReturns();
  loadReviews();
})();
</script>
@endsection
