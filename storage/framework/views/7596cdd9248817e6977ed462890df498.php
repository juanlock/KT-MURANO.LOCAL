
<?php $pwaOn = (bool) ($app_settings->pwa_enabled ?? true); ?>
<script>
  (function () {
    try {
      if (!('serviceWorker' in navigator)) return;
<?php if($pwaOn): ?>
      var isSecure = window.isSecureContext === true
        || location.protocol === 'https:'
        || location.hostname === 'localhost'
        || location.hostname === '127.0.0.1';
      if (!isSecure) return;
      window.addEventListener('load', function () {
        
        navigator.serviceWorker.register(<?php echo json_encode(store_sw_url(), 15, 512) ?>, { scope: '/' }).catch(function () {});
      });
<?php else: ?>
      if (!navigator.serviceWorker.getRegistrations) return;
      navigator.serviceWorker.getRegistrations().then(function (regs) {
        regs.forEach(function (reg) { reg.unregister(); });
      }).catch(function () {});
<?php endif; ?>
    } catch (e) { /* never break the app because of PWA */ }
  })();
</script>
<?php /**PATH D:\WEBSITES-LOCAL\KT-MURANO.LOCAL\resources\views/partials/pwa-sw.blade.php ENDPATH**/ ?>