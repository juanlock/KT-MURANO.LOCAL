@php
    /**
     * Vue 3 + Ant Design app ("Stocky Next"), built via Vite into public/js/.
     *
     * Asset URLs come from Vite's manifest and are content-hashed — do NOT add a
     * `?v=` cache-busting query to the module script. Lazy chunks import the
     * entry as "../app.js" (no query), and browsers key ES modules by full URL,
     * so a query would load a second copy of the app + Vue for chunk code and
     * break rendering ("Cannot read properties of null (reading 'ce')").
     */
    $manifestPath = public_path('js/.vite/manifest.json');
    $entry = null;
    $cssFiles = [];

    // System version from version.txt (the same file the updater reads),
    // exposed as a meta tag so the app can show it without an API round-trip.
    $appVersion = is_file(base_path('version.txt'))
        ? trim(file_get_contents(base_path('version.txt')))
        : '';

    if (is_file($manifestPath)) {
        // Named $viteManifest, not $manifest: @include shares these variables
        // with partials.pwa-head, whose optional $manifest is a URL string.
        $viteManifest = json_decode(file_get_contents($manifestPath), true) ?: [];

        // The entry is keyed by its source path (e.g. "src/main.js").
        foreach ($viteManifest as $item) {
            if (!empty($item['isEntry'])) {
                $entry = $item;
                break;
            }
        }

        // Stylesheets: with cssCodeSplit disabled Vite emits one standalone
        // bundle under a "style.css" key instead of attaching it to the entry,
        // so handle both layouts.
        $cssFiles = $entry['css'] ?? [];
        if (!$cssFiles) {
            foreach ($viteManifest as $key => $item) {
                if (!empty($item['file']) && str_ends_with($item['file'], '.css')) {
                    $cssFiles[] = $item['file'];
                }
            }
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-version" content="{{ $appVersion }}">
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <link rel="icon" href="{{ asset('images/' . ($app_settings->favicon ?? 'favicon.ico')) }}">

    @include('partials.pwa-head')

    {{-- Self-hosted receipt fonts (Inter / Ubuntu / Roboto) offered by
         Settings → POS Receipt, so the on-screen invoice modal and the
         receipt designer preview render the chosen font — offline, no
         external request. --}}
    <link rel="stylesheet" href="/css/receipt-fonts.css">
    <title>{{ $app_settings->app_name ?? 'Stocky' }}</title>
    <script>window.__PAGE_TITLE_SUFFIX__ = @json($app_settings->page_title_suffix ?? '');</script>

    @foreach ($cssFiles as $css)
      <link rel="stylesheet" href="/js/{{ $css }}">
    @endforeach
  </head>
  <body>
    <noscript><strong>This page requires JavaScript.</strong></noscript>
    {{-- Boot loader: shown from first paint until the app mounts (Vue replaces
         the container's content, so it disappears on its own). main.js waits
         for translations + auth + the first route chunk before mounting, so
         this covers the whole refresh, not just the script download. --}}
    <div id="vue3-app">
      <style>
        .boot-loader {
          position: fixed;
          inset: 0;
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          gap: 16px;
          background: #f5f5f5;
          font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
          color: rgba(0, 0, 0, 0.45);
          font-size: 14px;
        }
        .boot-loader__spinner {
          width: 40px;
          height: 40px;
          border: 3px solid rgba(109, 40, 217, 0.15);
          border-top-color: #6d28d9;
          border-radius: 50%;
          animation: boot-spin 0.8s linear infinite;
        }
        @keyframes boot-spin { to { transform: rotate(360deg); } }
      </style>
      <div class="boot-loader">
        <div class="boot-loader__spinner"></div>
        <div>{{ $app_settings->app_name ?? 'Stocky' }}</div>
      </div>
    </div>

    {{-- html5-qrcode global (Html5QrcodeScanner) — same script legacy's
         master.blade.php loads; used by the POS and barcode-page scanners. --}}
    <script src="/assets_setup/js/qrcode.js"></script>

    @if ($entry)
      <script type="module" src="/js/{{ $entry['file'] }}"></script>
    @else
      <p style="font-family: sans-serif; padding: 24px">
        Stocky Next assets are not built yet. Run <code>npm run vue3:build</code>.
      </p>
    @endif

    @include('partials.pwa-sw')
  </body>
</html>
