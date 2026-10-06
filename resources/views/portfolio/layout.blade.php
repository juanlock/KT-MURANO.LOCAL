{{-- Portfolio module: public website layout (from KT-TOOLS/Porfolio). --}}
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>@yield('title', $site->name) — {{ $site->name }}</title>
    <meta name="description" content="{{ $site->tagline ?: $site->name }}">
    @if (! empty($site->preview))<meta name="robots" content="noindex">@endif
    @if ($site->logo)<link rel="icon" href="{{ asset($site->logo) }}">@endif

    <link href="https://fonts.googleapis.com/css?family=Playfair+Display:700,900|Raleway:100,300,400,600,700" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.0/css/bootstrap.min.css" integrity="sha384-9gVQ4dYFwwWSjIDZnLEWnxCjeSWFphJiwGPXr1jddIhOegiu1FwO5qRGvFXOdJZ4" crossorigin="anonymous">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.0.10/css/all.css" integrity="sha384-+d0P83n9kaQMCwj8F4RJB66tzIwOKmrdb46+porD/OvrJ+37WqIM7UoBtwHO6Nlg" crossorigin="anonymous">
    @stack('head')
    <link rel="stylesheet" href="{{ asset('portfolio-theme/css/style.css') }}">
    @if ($site->theme === 'dark')
      <link rel="stylesheet" href="{{ asset('portfolio-theme/css/dark-theme.css') }}">
    @endif
    <style>
      /* Only what the example doesn't cover -- its own look comes from style.css / dark-theme.css. */
      #hero-text img { max-width: 100%; }
      #hero-text h1.pf-name { font-family: 'Playfair Display', serif; margin: 0; }
      /* Bottom, so the header looks exactly like the published site. */
      .pf-preview { position: fixed; bottom: 0; left: 0; right: 0; z-index: 99; background: #faad14; color: #000; text-align: center; font: 600 13px/28px sans-serif; }
      .item-img img { width: 100%; height: 100%; object-fit: cover; }
      /* Menu groups (dropdowns) -- the example has no style for them. */
      .pf-dropdown { border-radius: 0; border: 1px solid rgba(0, 0, 0, .1); padding: 6px 0; }
      .pf-dropdown .dropdown-item { font-family: 'Playfair Display', serif; font-weight: 700; padding: 8px 20px; }
      @if ($site->theme === 'dark')
        .pf-dropdown { background: #0D0D0D; border-color: #262626; }
        .pf-dropdown .dropdown-item { color: #fff; opacity: .75; }
        .pf-dropdown .dropdown-item:hover { background: #1b1b1b; color: #fff; opacity: 1; }
      @endif
      .pf-empty { text-align: center; padding: 60px 20px; opacity: .7; }
      @if ($site->bg_color || $site->bg_image)
        {{-- The company's background (Portfolio -> Website -> Look). --}}
        body {
          @if ($site->bg_color) background-color: {{ $site->bg_color }} !important; @endif
          @if ($site->bg_image) background-image: url('{{ asset($site->bg_image) }}'); background-size: cover; background-position: center; background-attachment: fixed; @endif
        }
      @endif
    </style>
  </head>
  <body>
    @if (! empty($site->preview))
      <div class="pf-preview">Preview — this website is not published yet (only your team can see it).</div>
    @endif

    {{-- Header exactly like the example (KT-TOOLS/apps/Porfolio): Home, Portfolio and a
         work page show only the centered logo (#hero-text); About and Contact show
         the top navbar (name left, menu right, hamburger on phones) with the
         company's menu (Settings -> Menu). "Menu on every page" adds the navbar
         above the logo on the other pages too. --}}
    @php $withNav = in_array($page, ['about', 'contact'], true) || $site->menu_on_home; @endphp
    <header class="container-fluid">
      @if ($withNav)
        <nav class="navbar navbar-expand-lg navbar-light bg-light">
          <a class="navbar-brand" href="{{ route('portfolio.home', $site->slug) }}">{{ $site->name }}</a>
          <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarDefault" aria-controls="navbarDefault" aria-expanded="false" aria-label="Toggle navigation">
            <span class="icon-bar i1"></span>
            <span class="icon-bar i2"></span>
            <span class="icon-bar i3"></span>
          </button>
          <div class="collapse navbar-collapse" id="navbarDefault">
            <ul class="navbar-nav ml-auto">
              @foreach ($site->menu_items as $mi)
                @if ($mi['children'])
                  <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">{{ mb_strtoupper($mi['label']) }}</a>
                    <div class="dropdown-menu dropdown-menu-right pf-dropdown">
                      @foreach ($mi['children'] as $ch)
                        <a class="dropdown-item" href="{{ $ch['href'] }}" @if ($ch['new_tab']) target="_blank" rel="noopener" @endif>{{ $ch['label'] }}</a>
                      @endforeach
                    </div>
                  </li>
                @else
                  <li class="nav-item {{ $mi['page'] === $page ? 'active' : '' }}">
                    <a class="nav-link" href="{{ $mi['href'] }}" @if ($mi['new_tab']) target="_blank" rel="noopener" @endif>{{ mb_strtoupper($mi['label']) }}</a>
                  </li>
                @endif
              @endforeach
            </ul>
          </div>
        </nav>
      @endif
      @if (! in_array($page, ['about', 'contact'], true))
        <div id="hero-text">
          <div class="container">
            <div class="text-center">
              <a href="{{ route('portfolio.home', $site->slug) }}">
                @if ($site->logo)
                  <img src="{{ asset($site->logo) }}" class="rounded" alt="{{ $site->name }}">
                @else
                  <h1 class="pf-name">{{ $site->name }}</h1>
                @endif
              </a>
            </div>
          </div>
        </div>
      @endif
    </header>

    <!-- Corner Borders -->
    <section class="borders">
      <div class="brTR"></div>
      <div class="brTL"></div>
      <div class="brBL"></div>
      <div class="brBR"></div>
    </section>

    @yield('content')

    @php
      $icons = ['instagram' => 'fab fa-instagram', 'facebook' => 'fab fa-facebook', 'tiktok' => 'fab fa-tiktok', 'youtube' => 'fab fa-youtube',
                'twitter' => 'fab fa-twitter', 'whatsapp' => 'fab fa-whatsapp', 'website' => 'fas fa-globe'];
    @endphp
    <footer>
      <div class="container-fluid">
        <div class="footer-info">
          <div class="footer-title">
            <h2>{{ $site->name }}</h2>
            <p>{{ $site->footer_text ?: '© Copyrights '.date('Y').'. All Rights Reserved.' }}</p>
          </div>
          <div class="footer-social">
            @foreach ($site->social as $key => $link)
              @php $href = $key === 'whatsapp' && ! str_starts_with($link, 'http') ? 'https://wa.me/'.preg_replace('/\D/', '', $link) : $link; @endphp
              <a href="{{ $href }}" target="_blank" rel="noopener" title="{{ ucfirst($key) }}"><i class="{{ $icons[$key] ?? 'fas fa-link' }}"></i></a>
            @endforeach
          </div>
        </div>
      </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.0/umd/popper.min.js" integrity="sha384-cs/chFZiN24E4KMATLdqdvsezGxaGsi4hLGOzlXwp5UZB1LY//20VyM2taTB4QvJ" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.0/js/bootstrap.min.js" integrity="sha384-uefMccjFJAIv6A+rW+L4AHf99KvxDjWSu1z9VI8SKNVmz4sk7buKt/6v9KI65qnm" crossorigin="anonymous"></script>
    <script src="{{ asset('portfolio-theme/js/custom.js') }}"></script>
    @stack('scripts')
  </body>
</html>
