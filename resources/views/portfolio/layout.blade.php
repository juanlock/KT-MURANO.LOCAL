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
      #hero-text img { max-height: 160px; max-width: 80%; }
      #hero-text h1.pf-name { font-family: 'Playfair Display', serif; margin: 0; }
      .pf-nav { display: flex; justify-content: center; flex-wrap: wrap; gap: 6px 28px; list-style: none; padding: 0; margin: 18px 0 0; }
      .pf-nav a { font-family: 'Raleway', sans-serif; letter-spacing: 2px; font-size: 13px; text-transform: uppercase; opacity: .7; text-decoration: none; }
      .pf-nav a:hover, .pf-nav a.active { opacity: 1; }
      .pf-preview { position: fixed; top: 0; left: 0; right: 0; z-index: 99; background: #faad14; color: #000; text-align: center; font: 600 13px/28px sans-serif; }
      .item-img img { width: 100%; height: 100%; object-fit: cover; }
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

    <header class="container-fluid">
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
            <ul class="pf-nav">
              <li><a href="{{ route('portfolio.home', $site->slug) }}" class="{{ $page === 'home' ? 'active' : '' }}">Home</a></li>
              @if ($site->pages['about'] ?? true)
                <li><a href="{{ route('portfolio.about', $site->slug) }}" class="{{ $page === 'about' ? 'active' : '' }}">About</a></li>
              @endif
              <li><a href="{{ route('portfolio.work', $site->slug) }}" class="{{ $page === 'work' ? 'active' : '' }}">Portfolio</a></li>
              @if ($site->pages['contact'] ?? true)
                <li><a href="{{ route('portfolio.contact', $site->slug) }}" class="{{ $page === 'contact' ? 'active' : '' }}">Contact</a></li>
              @endif
            </ul>
          </div>
        </div>
      </div>
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
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.0/js/bootstrap.min.js" integrity="sha384-uefMccjFJAIv6A+rW+L4AHf99KvxDjWSu1z9VI8SKNVmz4sk7buKt/6v9KI65qnm" crossorigin="anonymous"></script>
    @stack('scripts')
  </body>
</html>
