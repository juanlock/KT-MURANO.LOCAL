{{-- "Request a Quotation" modal for service / classified-ad products.
     Opened via window.openQuoteRequest({id, name}) from product cards / quick-view. --}}
<div id="quote-request" class="qr-root hidden" role="dialog" aria-modal="true" aria-label="{{ __('messages.RequestQuotation') }}">
  <div class="qr-overlay" data-qr-close></div>
  <div class="qr-card">
    <button type="button" class="qr-close" data-qr-close aria-label="{{ __('messages.Close') }}">&times;</button>
    <div class="qr-body">
      <h3 class="qr-title">{{ __('messages.RequestQuotation') }}</h3>
      <div id="qr-product" class="text-sm text-fg-muted mb-3"></div>

      <form id="qr-form" class="space-y-3">
        <input type="hidden" id="qr-product-id">
        <div>
          <label class="form-label text-xs">{{ __('messages.FullName') }} *</label>
          <input type="text" id="qr-name" class="input" required
                 value="{{ optional(auth('store')->user()?->client)->name }}">
        </div>
        <div class="grid md:grid-cols-2 gap-3">
          <div>
            <label class="form-label text-xs">{{ __('messages.Email') }} *</label>
            <input type="email" id="qr-email" class="input" required
                   value="{{ optional(auth('store')->user())->email }}">
          </div>
          <div>
            <label class="form-label text-xs">{{ __('messages.Phone') }}</label>
            <input type="tel" id="qr-phone" class="input"
                   value="{{ optional(auth('store')->user()?->client)->phone }}">
          </div>
        </div>
        <div>
          <label class="form-label text-xs">{{ __('messages.Quantity') }}</label>
          <input type="number" id="qr-qty" class="input" min="0" step="any">
        </div>
        <div>
          <label class="form-label text-xs">{{ __('messages.Message') }}</label>
          <textarea id="qr-message" class="input" rows="3" placeholder="{{ __('messages.QuoteMessagePlaceholder') }}"></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-block">{{ __('messages.SendRequest') }}</button>
        <div id="qr-msg" class="text-xs text-center"></div>
      </form>
    </div>
  </div>
</div>

<style>
  .qr-root { position: fixed; inset: 0; z-index: 10045; display: flex; align-items: center; justify-content: center; padding: 1rem; }
  .qr-root.hidden { display: none; }
  .qr-overlay { position: absolute; inset: 0; background: rgb(0 0 0 / .5); }
  .qr-card { position: relative; z-index: 1; width: 100%; max-width: 440px; background: rgb(var(--color-bg-surface));
    border-radius: 16px; box-shadow: 0 20px 60px rgb(0 0 0 / .3); max-height: 90vh; overflow: auto; }
  .qr-close { position: absolute; top: 8px; inset-inline-end: 12px; background: none; border: 0; font-size: 28px; line-height: 1; cursor: pointer; color: rgb(var(--color-fg-muted)); }
  .qr-body { padding: 1.5rem; }
  .qr-title { font-size: 1.2rem; font-weight: 700; margin: 0 0 .25rem; }
</style>

<script>
(function(){
  var root = document.getElementById('quote-request');
  if (!root) return;
  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var URL_QR = "{{ route('store.quote.request') }}";
  var form = document.getElementById('qr-form');
  var pidEl = document.getElementById('qr-product-id');
  var prodEl = document.getElementById('qr-product');
  var msgEl = document.getElementById('qr-msg');

  function esc(s){ return String(s||'').replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]; }); }
  function open(){ root.classList.remove('hidden'); }
  function close(){ root.classList.add('hidden'); }

  window.openQuoteRequest = function(product){
    product = product || {};
    pidEl.value = product.id || '';
    prodEl.innerHTML = product.name ? ('{{ __('messages.Product') }}: <strong>' + esc(product.name) + '</strong>') : '';
    msgEl.textContent = '';
    open();
  };

  root.querySelectorAll('[data-qr-close]').forEach(function(el){ el.addEventListener('click', close); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !root.classList.contains('hidden')) close(); });

  // Any "Request a Quotation" button on the page (cards / quick-view) opens this modal.
  document.addEventListener('click', function(e){
    var b = e.target.closest('.js-request-quote');
    if (!b) return;
    e.preventDefault();
    window.openQuoteRequest({ id: b.dataset.id, name: b.dataset.name });
  });

  form.addEventListener('submit', function(e){
    e.preventDefault();
    var payload = {
      product_id: pidEl.value || null,
      name: document.getElementById('qr-name').value.trim(),
      email: document.getElementById('qr-email').value.trim(),
      phone: document.getElementById('qr-phone').value.trim(),
      quantity: document.getElementById('qr-qty').value || null,
      message: document.getElementById('qr-message').value.trim()
    };
    if (!payload.name || !payload.email){ return; }
    var btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    fetch(URL_QR, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload)
    })
    .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, d: d }; }); })
    .then(function(res){
      if (res.ok){
        msgEl.textContent = res.d.message || 'OK';
        msgEl.className = 'text-xs text-center text-success';
        form.reset();
        setTimeout(close, 1400);
      } else {
        msgEl.textContent = res.d.error || (res.d.message) || @json(__('messages.NetworkError'));
        msgEl.className = 'text-xs text-center text-danger';
      }
    })
    .catch(function(){ msgEl.textContent = @json(__('messages.NetworkError')); msgEl.className = 'text-xs text-center text-danger'; })
    .finally(function(){ btn.disabled = false; });
  });
})();
</script>
