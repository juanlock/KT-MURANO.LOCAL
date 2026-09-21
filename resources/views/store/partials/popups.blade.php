{{-- Admin-managed marketing popups (announcement / subscription / sale).
     Renders every running popup as JSON; a small controller shows the first
     one the visitor hasn't dismissed (per its frequency) using its trigger. --}}
@php $storePopups = \App\Models\StorePopup::running()->get(); @endphp

@if($storePopups->isNotEmpty())
@php
  $popupData = $storePopups->map(fn ($p) => [
    'id' => $p->id,
    'title' => $p->localized('title'),
    'message' => $p->localized('message'),
    'type' => $p->type,
    'image_url' => $p->image ? asset($p->image) : null,
    'cta_label' => $p->localized('cta_label'),
    'cta_url' => $p->cta_url,
    'trigger' => $p->trigger,
    'delay_seconds' => (int) $p->delay_seconds,
    'frequency' => $p->frequency,
  ])->values();
@endphp

<div id="store-popup" class="sp-root hidden" role="dialog" aria-modal="true" aria-label="{{ __('messages.Message') }}">
  <div class="sp-overlay" data-sp-close></div>
  <div class="sp-card">
    <button type="button" class="sp-close" data-sp-close aria-label="{{ __('messages.Close') }}">&times;</button>
    <img id="sp-image" class="sp-image hidden" src="" alt="">
    <div class="sp-body">
      <h3 id="sp-title" class="sp-title"></h3>
      <p id="sp-message" class="sp-message"></p>

      {{-- Subscription form (subscription type) --}}
      <form id="sp-sub-form" class="sp-sub hidden">
        <input type="email" id="sp-sub-email" class="input" placeholder="{{ __('messages.EmailPlaceholder') }}" required>
        <button type="submit" class="btn btn-primary">{{ __('messages.Subscribe') }}</button>
        <div id="sp-sub-msg" class="text-xs mt-1"></div>
      </form>

      {{-- CTA button (announcement / sale) --}}
      <a id="sp-cta" class="btn btn-primary btn-block hidden" href="#"></a>
    </div>
  </div>
</div>

<style>
  .sp-root { position: fixed; inset: 0; z-index: 10040; display: flex; align-items: center; justify-content: center; padding: 1rem; }
  .sp-root.hidden { display: none; }
  .sp-overlay { position: absolute; inset: 0; background: rgb(0 0 0 / .5); }
  .sp-card {
    position: relative; z-index: 1; width: 100%; max-width: 420px; overflow: hidden;
    background: rgb(var(--color-bg-surface)); border-radius: 16px;
    box-shadow: 0 20px 60px rgb(0 0 0 / .3); animation: sp-pop .25s ease;
  }
  @keyframes sp-pop { from { transform: scale(.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
  .sp-close {
    position: absolute; top: 8px; inset-inline-end: 12px; z-index: 2; background: none; border: 0;
    font-size: 28px; line-height: 1; cursor: pointer; color: rgb(var(--color-fg-muted));
  }
  .sp-image { width: 100%; max-height: 200px; object-fit: cover; display: block; }
  .sp-image.hidden { display: none; }
  .sp-body { padding: 1.5rem; text-align: center; }
  .sp-title { font-size: 1.25rem; font-weight: 700; margin: 0 0 .5rem; }
  .sp-message { font-size: .9rem; color: rgb(var(--color-fg-secondary)); margin: 0 0 1rem; white-space: pre-line; }
  .sp-sub { display: flex; flex-direction: column; gap: .5rem; }
  .sp-sub.hidden, .btn.hidden { display: none; }
</style>

<script>
(function(){
  var POPUPS = @json($popupData);
  var root = document.getElementById('store-popup');
  if (!root || !POPUPS.length) return;

  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var SUBSCRIBE_URL = "{{ route('newsletter.subscribe') }}";
  var imgEl = document.getElementById('sp-image');
  var titleEl = document.getElementById('sp-title');
  var msgEl = document.getElementById('sp-message');
  var subForm = document.getElementById('sp-sub-form');
  var subEmail = document.getElementById('sp-sub-email');
  var subMsg = document.getElementById('sp-sub-msg');
  var ctaEl = document.getElementById('sp-cta');
  var current = null;

  function seenKey(id){ return 'store_popup_seen_' + id; }
  function wasSeen(p){
    if (p.frequency === 'always') return false;
    var store = p.frequency === 'session' ? sessionStorage : localStorage;
    try { return !!store.getItem(seenKey(p.id)); } catch(e){ return false; }
  }
  function markSeen(p){
    if (p.frequency === 'always') return;
    var store = p.frequency === 'session' ? sessionStorage : localStorage;
    try { store.setItem(seenKey(p.id), '1'); } catch(e){}
  }

  function render(p){
    current = p;
    titleEl.textContent = p.title || '';
    titleEl.style.display = p.title ? '' : 'none';
    msgEl.textContent = p.message || '';
    msgEl.style.display = p.message ? '' : 'none';
    if (p.image_url){ imgEl.src = p.image_url; imgEl.classList.remove('hidden'); }
    else { imgEl.classList.add('hidden'); }

    subForm.classList.add('hidden'); ctaEl.classList.add('hidden'); subMsg.textContent = '';
    if (p.type === 'subscription'){
      subForm.classList.remove('hidden');
    } else if (p.cta_label && p.cta_url){
      ctaEl.textContent = p.cta_label;
      ctaEl.setAttribute('href', p.cta_url);
      ctaEl.classList.remove('hidden');
    }
  }

  function open(p){ render(p); root.classList.remove('hidden'); }
  function close(){ root.classList.add('hidden'); if (current) markSeen(current); }

  root.querySelectorAll('[data-sp-close]').forEach(function(el){ el.addEventListener('click', close); });
  ctaEl.addEventListener('click', function(){ if (current) markSeen(current); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !root.classList.contains('hidden')) close(); });

  subForm.addEventListener('submit', function(e){
    e.preventDefault();
    var email = (subEmail.value || '').trim();
    if (!email) return;
    fetch(SUBSCRIBE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ email: email })
    })
    .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, d: d }; }); })
    .then(function(res){
      subMsg.textContent = res.ok ? @json(__('messages.SubscribedThankYou')) : (res.d.message || @json(__('messages.NetworkError')));
      subMsg.className = 'text-xs mt-1 ' + (res.ok ? 'text-success' : 'text-danger');
      if (res.ok){ markSeen(current); setTimeout(close, 1200); }
    })
    .catch(function(){ subMsg.textContent = @json(__('messages.NetworkError')); subMsg.className = 'text-xs mt-1 text-danger'; });
  });

  // Pick the first not-yet-dismissed popup and schedule it by its trigger.
  var candidate = POPUPS.filter(function(p){ return !wasSeen(p); })[0];
  if (!candidate) return;

  if (candidate.trigger === 'immediate'){
    open(candidate);
  } else if (candidate.trigger === 'exit'){
    var armed = true;
    document.addEventListener('mouseout', function(e){
      if (armed && !e.relatedTarget && e.clientY <= 0){ armed = false; open(candidate); }
    });
  } else { // delay (default)
    setTimeout(function(){ open(candidate); }, Math.max(0, (candidate.delay_seconds || 3) * 1000));
  }
})();
</script>
@endif
