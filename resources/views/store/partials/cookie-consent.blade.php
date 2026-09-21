{{-- Cookie consent popup with granular preferences. Persists choice in the
     `cookie_consent` cookie (1 year) and exposes window.cookieConsent for other
     scripts to gate analytics/marketing before they load. --}}
<div id="cookie-consent" class="cc-root hidden" role="dialog" aria-modal="false" aria-label="{{ __('messages.CookieConsentTitle') }}">
  <div class="cc-card">
    <div class="cc-main">
      <h4 class="cc-title">{{ __('messages.CookieConsentTitle') }}</h4>
      <p class="cc-text">{{ __('messages.CookieConsentText') }}</p>

      {{-- Preferences (hidden until "Customize") --}}
      <div id="cc-prefs" class="cc-prefs hidden">
        <label class="cc-pref">
          <span>
            <strong>{{ __('messages.CookieNecessary') }}</strong>
            <small>{{ __('messages.CookieNecessaryHelp') }}</small>
          </span>
          <input type="checkbox" checked disabled>
        </label>
        <label class="cc-pref">
          <span>
            <strong>{{ __('messages.CookieAnalytics') }}</strong>
            <small>{{ __('messages.CookieAnalyticsHelp') }}</small>
          </span>
          <input type="checkbox" id="cc-analytics">
        </label>
        <label class="cc-pref">
          <span>
            <strong>{{ __('messages.CookieMarketing') }}</strong>
            <small>{{ __('messages.CookieMarketingHelp') }}</small>
          </span>
          <input type="checkbox" id="cc-marketing">
        </label>
      </div>
    </div>

    <div class="cc-actions">
      <button type="button" class="btn btn-outline btn-sm" id="cc-reject">{{ __('messages.CookieRejectAll') }}</button>
      <button type="button" class="btn btn-outline btn-sm" id="cc-customize">{{ __('messages.CookieCustomize') }}</button>
      <button type="button" class="btn btn-outline btn-sm hidden" id="cc-save">{{ __('messages.CookieSave') }}</button>
      <button type="button" class="btn btn-primary btn-sm" id="cc-accept">{{ __('messages.CookieAcceptAll') }}</button>
    </div>
  </div>
</div>

<style>
  .cc-root { position: fixed; inset-inline: 0; bottom: 0; z-index: 10050; padding: 1rem; display: flex; justify-content: center; }
  .cc-root.hidden { display: none; }
  .cc-card {
    width: 100%; max-width: 720px; background: rgb(var(--color-bg-surface));
    border: 1px solid rgb(var(--color-border-subtle)); border-radius: 14px;
    box-shadow: 0 12px 40px rgb(0 0 0 / .18); padding: 1.1rem 1.25rem;
  }
  .cc-title { font-weight: 700; font-size: 1rem; margin: 0 0 .35rem; }
  .cc-text { font-size: .85rem; color: rgb(var(--color-fg-secondary)); margin: 0; }
  .cc-prefs { margin-top: .9rem; display: flex; flex-direction: column; gap: .5rem; }
  .cc-prefs.hidden { display: none; }
  .cc-pref {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    border: 1px solid rgb(var(--color-border-subtle)); border-radius: 10px; padding: .6rem .75rem;
  }
  .cc-pref span { display: flex; flex-direction: column; }
  .cc-pref small { color: rgb(var(--color-fg-muted)); font-size: .72rem; }
  .cc-pref input { width: 18px; height: 18px; }
  .cc-actions { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
  .cc-actions .hidden { display: none; }
  @media (max-width: 560px) { .cc-actions { justify-content: stretch; } .cc-actions .btn { flex: 1 1 45%; } }
</style>

<script>
(function(){
  var KEY = 'cookie_consent';
  var root = document.getElementById('cookie-consent');
  if (!root) return;

  var prefs = document.getElementById('cc-prefs');
  var analyticsEl = document.getElementById('cc-analytics');
  var marketingEl = document.getElementById('cc-marketing');
  var saveBtn = document.getElementById('cc-save');

  function readCookie(name){
    var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/\+^])/g,'\\$1') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : null;
  }
  function writeCookie(name, value, days){
    var d = new Date(); d.setTime(d.getTime() + days*24*60*60*1000);
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + secure;
  }

  function getConsent(){
    try { var raw = readCookie(KEY); return raw ? JSON.parse(raw) : null; } catch(e){ return null; }
  }
  function save(consent){
    consent.necessary = true;
    consent.ts = Date.now();
    writeCookie(KEY, JSON.stringify(consent), 365);
    window.cookieConsent = consent;
    document.dispatchEvent(new CustomEvent('cookie-consent:updated', { detail: consent }));
    hide();
  }
  function show(){ root.classList.remove('hidden'); }
  function hide(){ root.classList.add('hidden'); }

  function open(existing){
    if (existing){
      analyticsEl.checked = !!existing.analytics;
      marketingEl.checked = !!existing.marketing;
      prefs.classList.remove('hidden');
      saveBtn.classList.remove('hidden');
    }
    show();
  }

  // Expose current consent + a way to reopen (e.g. from a footer link).
  window.cookieConsent = getConsent();
  window.openCookieSettings = function(){ open(getConsent() || {}); };

  document.getElementById('cc-accept').addEventListener('click', function(){ save({ analytics: true, marketing: true }); });
  document.getElementById('cc-reject').addEventListener('click', function(){ save({ analytics: false, marketing: false }); });
  document.getElementById('cc-customize').addEventListener('click', function(){
    prefs.classList.remove('hidden');
    saveBtn.classList.remove('hidden');
  });
  saveBtn.addEventListener('click', function(){
    save({ analytics: analyticsEl.checked, marketing: marketingEl.checked });
  });

  // First visit (no cookie yet) → show the banner.
  if (!getConsent()) { show(); }
})();
</script>
