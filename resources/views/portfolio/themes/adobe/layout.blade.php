{{-- Portfolio themes 2 (grid) and 3 (showcase): the Adobe Portfolio photography look
     (portfolio.adobe.com/examples/photography -- themes "Thomas" and "Marta").
     The page contents are the shared portfolio views; adobe.css styles their classes. --}}
@php
  $variant = $site->template === 'showcase' ? 'showcase' : 'grid';
  $icons = ['instagram' => 'fab fa-instagram', 'facebook' => 'fab fa-facebook-f', 'tiktok' => 'fab fa-tiktok', 'youtube' => 'fab fa-youtube',
            'twitter' => 'fab fa-twitter', 'whatsapp' => 'fab fa-whatsapp', 'website' => 'fas fa-globe'];
  $socialHref = fn ($key, $link) => $key === 'whatsapp' && ! str_starts_with($link, 'http') ? 'https://wa.me/'.preg_replace('/\D/', '', $link) : $link;
  $label = fn ($l) => $variant === 'grid' ? mb_strtoupper($l) : mb_strtolower($l);
@endphp
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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@200;300;400;500;600&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.0/css/bootstrap.min.css" integrity="sha384-9gVQ4dYFwwWSjIDZnLEWnxCjeSWFphJiwGPXr1jddIhOegiu1FwO5qRGvFXOdJZ4" crossorigin="anonymous">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.0.10/css/all.css" integrity="sha384-+d0P83n9kaQMCwj8F4RJB66tzIwOKmrdb46+porD/OvrJ+37WqIM7UoBtwHO6Nlg" crossorigin="anonymous">
    @stack('head')
    <link rel="stylesheet" href="{{ asset('portfolio-theme/adobe/adobe.css') }}">
    @if ($site->bg_color || $site->bg_image)
      <style>
        body {
          @if ($site->bg_color) background-color: {{ $site->bg_color }} !important; @endif
          @if ($site->bg_image) background-image: url('{{ asset($site->bg_image) }}'); background-size: cover; background-position: center; background-attachment: fixed; @endif
        }
        @if ($site->bg_color) .ap-header { background-color: {{ $site->bg_color }}; } @endif
      </style>
    @endif
  </head>
  <body class="ap t-{{ $variant }} look-{{ $site->theme === 'dark' ? 'dark' : 'light' }}">
    @if (! empty($site->preview))
      <div class="pf-preview">Preview — this website is not published yet (only your team can see it).</div>
    @endif

    <header class="ap-header" id="top">
      <a class="ap-brand" href="{{ route('portfolio.home', $site->slug) }}">
        @if ($site->logo)
          <img src="{{ asset($site->logo) }}" alt="{{ $site->name }}">
        @else
          {{ $site->name }}
        @endif
      </a>

      <button class="ap-toggle" type="button" aria-label="Menu" onclick="document.body.classList.toggle('ap-open')"><span></span><span></span><span></span></button>

      <nav class="ap-nav">
        @foreach ($site->menu_items as $mi)
          @if ($mi['children'])
            <div class="ap-group">
              <button type="button" class="ap-link" onclick="this.parentNode.classList.toggle('open')">{{ $label($mi['label']) }} <i class="fas fa-angle-down"></i></button>
              <div class="ap-sub">
                @foreach ($mi['children'] as $ch)
                  <a href="{{ $ch['href'] }}" @if ($ch['new_tab']) target="_blank" rel="noopener" @endif>{{ $ch['label'] }}</a>
                @endforeach
              </div>
            </div>
          @else
            <a class="ap-link {{ $mi['page'] === $page ? 'active' : '' }}" href="{{ $mi['href'] }}" @if ($mi['new_tab']) target="_blank" rel="noopener" @endif>{{ $label($mi['label']) }}</a>
          @endif
        @endforeach
      </nav>

      @if ($site->social)
        <div class="ap-social">
          @foreach ($site->social as $key => $link)
            <a href="{{ $socialHref($key, $link) }}" target="_blank" rel="noopener" title="{{ ucfirst($key) }}"><i class="{{ $icons[$key] ?? 'fas fa-link' }}"></i></a>
          @endforeach
        </div>
      @endif
    </header>

    <main class="ap-main">
      @yield('content')
    </main>

    <footer class="ap-footer">
      <a href="#top" class="ap-top"><span>↑</span> Back to Top</a>
      <p>{{ $site->footer_text ?: '© '.$site->name.', '.date('Y') }}</p>
    </footer>

    <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
    <script>
      // Close an open menu group when clicking elsewhere.
      document.addEventListener('click', function (e) {
        document.querySelectorAll('.ap-group.open').forEach(function (g) { if (!g.contains(e.target)) g.classList.remove('open'); });
      });
    </script>
    @stack('scripts')
  </body>
</html>
