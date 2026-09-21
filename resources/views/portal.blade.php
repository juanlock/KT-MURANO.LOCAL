<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="X-CSRF-TOKEN" content="{{ csrf_token() }}">
    <title>{{ __('portal.client_portal') }} — {{ optional($app_settings ?? null)->CompanyName ?: config('app.name') }}</title>
    <link rel="icon" href="{{ asset('images/' . (optional($app_settings ?? null)->favicon ?? 'favicon.ico')) }}">

    @include('partials.pwa-head', [
        'manifest' => '/pwa/portal.webmanifest',
        'themeColor' => '#f97316',
        'appTitle' => 'Portal',
    ])

    {{-- Pre-paint theme boot (same keys as lib/theme.js: rst-theme,
         rst-accent, rst-radius, rst-sidenav). A blocking script on purpose so a
         dark session never flashes white before the stylesheet lands. --}}
    <script>
    (function () {
        var root = document.documentElement;
        var stored = localStorage.getItem('rst-theme');
        var theme = stored === 'light' || stored === 'dark'
            ? stored
            : stored === 'system'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : 'dark';
        root.setAttribute('data-bs-theme', theme);
        root.style.colorScheme = theme;
        var accent = localStorage.getItem('rst-accent');
        if (accent && /^#[0-9a-f]{6}$/i.test(accent)) {
            var n = parseInt(accent.slice(1), 16);
            root.style.setProperty('--accent', accent);
            root.style.setProperty('--accent-rgb', ((n >> 16) & 255) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255));
        }
        if (localStorage.getItem('rst-sidenav') === 'collapsed') {
            root.classList.add('rst-sidenav-collapsed');
        }
        var radii = { sharp: ['0.25rem', '0.125rem'], round: ['1.25rem', '0.75rem'] };
        var radius = radii[localStorage.getItem('rst-radius')];
        if (radius) {
            root.style.setProperty('--rst-radius', radius[0]);
            root.style.setProperty('--rst-radius-sm', radius[1]);
        }
    })();
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap">
    @php
      $portalTabler = public_path('js/portal/' . (app()->getLocale() === 'ar' ? 'tabler.rtl.min.css' : 'tabler.min.css'));
      $portalJs  = public_path('js/portal/app.js');
      $portalCss = public_path('js/portal/app.css');
      $portalSettings = $app_settings ?? null;
      $portalLogo = $portalSettings && $portalSettings->logo ? asset('images/' . $portalSettings->logo) : null;
      $portalCurrency = $portalSettings && $portalSettings->currency ? $portalSettings->currency->symbol : null;
      $portalApp = [
          'name' => ($portalSettings && $portalSettings->CompanyName) ? $portalSettings->CompanyName : config('app.name'),
          'logo' => $portalLogo,
          'currency' => $portalCurrency,
      ];
    @endphp
    @if (file_exists($portalTabler))
      <link rel="stylesheet" href="/js/portal/{{ basename($portalTabler) }}?v={{ filemtime($portalTabler) }}">
    @endif
    @if (file_exists($portalCss))
      <link rel="stylesheet" href="/js/portal/app.css?v={{ filemtime($portalCss) }}">
    @endif
    <style>
        .portal-loading { display: flex; align-items: center; justify-content: center; min-height: 100vh; flex-direction: column; gap: 1rem; color: #64748b; font-family: 'Inter', system-ui, sans-serif; }
        .portal-loading .pc-loader-ring { width: 36px; height: 36px; border-radius: 50%; border: 3px solid rgba(249, 115, 22, 0.2); border-top-color: #f97316; animation: pc-spin 0.7s linear infinite; }
        @keyframes pc-spin { to { transform: rotate(360deg); } }
        .portal-loading .portal-fallback { display: none; font-size: 0.9rem; max-width: 360px; text-align: center; }
    </style>
</head>
<body class="rst-app layout-fluid">
  <div id="portal-app">
    <div class="portal-loading">
      <div class="pc-loader-ring"></div>
      <span>{{ __('portal.loading') }}</span>
      <p class="portal-fallback" id="portal-fallback">If the portal does not load, run <code>npm run build:portal</code> to build the portal assets.</p>
    </div>
  </div>
  <script>
    window.__PORTAL_CSRF__ = '{{ csrf_token() }}';
    // Locale resolved server-side (SetPortalLocale: session > saved preference > cookie > default)
    // and the supported set — read by resources/src/portal/i18n.js.
    window.__PORTAL_LOCALE__ = '{{ app()->getLocale() }}';
    window.__PORTAL_LOCALES__ = @json(\App\Http\Middleware\SetLocale::SUPPORTED);
    // Branding for the sidebar / topbar (company name + logo from Settings).
    window.__PORTAL_APP__ = @json($portalApp);
    setTimeout(function() {
      var app = document.getElementById('portal-app');
      if (app && app.querySelector('.portal-loading')) {
        var fallback = document.getElementById('portal-fallback');
        if (fallback) fallback.style.display = 'block';
      }
    }, 4000);
  </script>
  @if (file_exists($portalJs))
    <script type="module" src="/js/portal/app.js?v={{ filemtime($portalJs) }}"></script>
  @endif

  @include('partials.pwa-sw')
</body>
</html>
