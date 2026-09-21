<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Customer Display</title>
    <link rel="icon" href="{{ asset('images/' . (($app_settings->favicon ?? null) ?: 'favicon.ico')) }}">

    {{-- PWA --}}
    @include('partials.pwa-head', [
        'manifest' => '/pwa/customer-display.webmanifest',
        'themeColor' => '#0b0c10',
        'statusBar' => 'black-translucent',
        'appTitle' => 'Customer Display',
    ])

    <style>
      /* Base reset + dark background so there's no white flash before the Vue
         app mounts. All real styling ships inside the component (scoped). */
      html, body { margin:0; padding:0; height:100%; background:#0b0c10; color:#fff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Helvetica Neue', Arial, sans-serif; }
      #customer-display { min-height:100%; }
    </style>
</head>
<body>
  <div id="customer-display" class="cd-root">
    <!-- Vue app mounts here -->
  </div>
  <script>
    window.__APP_LOGO__ = '{{ asset('images/' . (($app_settings->logo ?? null) ?: 'logo.png')) }}';
  </script>
  {{-- Vue 3 Customer Display bundle (built by `npm run vue3:build`). --}}
  @php
    $cdJs  = public_path('js/customer-display/app.js');
    $cdCss = public_path('js/customer-display/app.css');
  @endphp
  @if (file_exists($cdCss))
    <link rel="stylesheet" href="/js/customer-display/app.css?v={{ filemtime($cdCss) }}">
  @endif
  @if (file_exists($cdJs))
    <script type="module" src="/js/customer-display/app.js?v={{ filemtime($cdJs) }}"></script>
  @else
    <p style="color:#fff;padding:24px;font-family:sans-serif">Customer Display assets are not built yet. Run <code>npm run vue3:build</code>.</p>
  @endif

  @include('partials.pwa-sw')
</body>
</html>


