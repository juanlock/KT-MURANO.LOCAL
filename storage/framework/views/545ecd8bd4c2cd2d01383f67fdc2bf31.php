
<?php
    $pwaSetting = $app_settings ?? null;
    // The column is absent on installs that have not run the PWA migration —
    // treat that as enabled so the app keeps behaving as it did before.
    $pwaOn = (bool) ($pwaSetting->pwa_enabled ?? true);
    $pwaName = trim((string) ($pwaSetting->pwa_name ?? '')) ?: ($pwaSetting->app_name ?? 'Stocky');
    $pwaTheme = trim((string) ($pwaSetting->pwa_theme_color ?? '')) ?: '#2f3640';
    // @include leaks every parent-view variable in here, so a layout defining
    // its own $manifest (e.g. a decoded asset manifest array) must not be
    // mistaken for our manifest URL.
    $pwaManifestUrl = isset($manifest) && is_string($manifest) ? $manifest : '/manifest.webmanifest';
?>

<script>window.__PWA_ENABLED__ = <?php echo json_encode($pwaOn, 15, 512) ?>;</script>
<?php if($pwaOn): ?>
    <link rel="manifest" href="<?php echo e($pwaManifestUrl); ?>">
    <meta name="theme-color" content="<?php echo e($themeColor ?? $pwaTheme); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="<?php echo e($statusBar ?? 'default'); ?>">
    <meta name="apple-mobile-web-app-title" content="<?php echo e($appTitle ?? $pwaName); ?>">
    <link rel="apple-touch-icon" href="/pwa_images/pwa-icon-192.png">
<?php endif; ?>
<?php /**PATH D:\WEBSITES-LOCAL\KT-MURANO.LOCAL\resources\views/partials/pwa-head.blade.php ENDPATH**/ ?>