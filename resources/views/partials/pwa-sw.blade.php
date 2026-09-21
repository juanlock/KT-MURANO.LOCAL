{{-- PWA service worker registration. Silently no-ops on unsupported browsers,
     http (non-localhost), or if registration fails. Does not block app boot.

     When the PWA is switched off in System Settings → PWA, this instead
     unregisters whatever a previous visit installed, so the app stops being
     served from the service worker cache on devices that already have it. --}}
@php $pwaOn = (bool) ($app_settings->pwa_enabled ?? true); @endphp
<script>
  (function () {
    try {
      if (!('serviceWorker' in navigator)) return;
@if ($pwaOn)
      var isSecure = window.isSecureContext === true
        || location.protocol === 'https:'
        || location.hostname === 'localhost'
        || location.hostname === '127.0.0.1';
      if (!isSecure) return;
      window.addEventListener('load', function () {
        {{-- Same URL as the storefront registration (store_sw_url) so the two
             surfaces don't fight over the registration at scope '/'. --}}
        navigator.serviceWorker.register(@json(store_sw_url()), { scope: '/' }).catch(function () {});
      });
@else
      if (!navigator.serviceWorker.getRegistrations) return;
      navigator.serviceWorker.getRegistrations().then(function (regs) {
        regs.forEach(function (reg) { reg.unregister(); });
      }).catch(function () {});
@endif
    } catch (e) { /* never break the app because of PWA */ }
  })();
</script>
