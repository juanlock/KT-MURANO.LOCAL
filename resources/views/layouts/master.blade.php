<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <link rel="stylesheet" href="/css/master.css">
    {{-- Tailwind v4 pilot: utilities generated only from the dashboard component. --}}
    <link rel="stylesheet" href="/css/dashboard-tw.css">
    <link rel="icon" href="{{ asset('images/' . ($app_settings->favicon ?? 'favicon.ico')) }}">

    @include('partials.pwa-head')

    <title>{{ $app_settings->app_name ?? 'Stocky | Ultimate Inventory With POS' }}</title>

    {{-- Expose the configured app name so the SPA can use it as the default document title
         immediately on boot (before the authenticated user/settings are fetched). --}}
    <script>window.__APP_NAME__ = @json($app_settings->app_name ?? 'Stocky | Ultimate Inventory With POS');</script>

  </head>

  <body class="text-left">
    <noscript>
      <strong>
        We're sorry but Stocky doesn't work properly without JavaScript
        enabled. Please enable it to continue.</strong
      >
    </noscript>

    <script>
      (function () {
        try {
          var c = localStorage.getItem('primaryColor');
          if (!c || !/^#([0-9a-f]{3}){1,2}$/i.test(c)) return;
          var hex = c.replace('#', '');
          if (hex.length === 3) hex = hex.split('').map(function (x) { return x + x; }).join('');
          var r = parseInt(hex.substring(0, 2), 16);
          var g = parseInt(hex.substring(2, 4), 16);
          var b = parseInt(hex.substring(4, 6), 16);
          var rgba = 'rgba(' + r + ',' + g + ',' + b + ',0.45)';
          var style = document.createElement('style');
          style.textContent =
            '.loading span{background:' + c + ' !important;}';
          document.head.appendChild(style);
        } catch (e) {}
      })();
    </script>

    <!-- built files will be auto injected -->
    <div class="loading_wrap" id="loading_wrap">
      <div class="loader_logo">
      <img src="{{ asset('images/' . ($app_settings->logo ?? 'logo.png')) }}" class="" alt="logo" />

      </div>

      <div class="loading">
        <span></span><span></span><span></span>
      </div>
    </div>
    <div id="app">
      <script src="/assets_setup/js/qrcode.js"></script>

    </div>


    <script src="/js/main.min.js?v=5.7&v={{ time() }}"></script>

    @include('partials.pwa-sw')

  </body>
</html>
