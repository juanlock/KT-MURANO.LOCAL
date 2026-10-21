{{--
  One-page template website (Website Management, 2026-10-20) -- App\Services\OnePage.
  Five looks after the Bootstrap examples (MIT), on the bundled Bootstrap 5.3:
  services = Carousel, food = Cover, beauty = Album, cleaning = Pricing,
  corporate = Product. Every text is plain text from website_settings.onepage
  ($t() picks the visitor's language), escaped here.
--}}
@php
  $tpl = $page['template'] ?? 'services';
  $accent = $page['color'] ?? '#0d6efd';
  $showPrices = ! empty($page['show_prices']);
  $img = fn ($p) => $p ? asset($p) : null;
  $para = fn ($s) => nl2br(e($s));
  $darkNav = in_array($tpl, ['food', 'corporate'], true);
  $serif = in_array($tpl, ['food', 'beauty'], true);
  $hex = ltrim($accent, '#');
  $rgb = implode(',', array_map('hexdec', str_split(strlen($hex) === 6 ? $hex : '0d6efd', 2)));
  $menu = collect($sections)->filter(fn ($s) => $t($s['menu'] ?? '') !== '')->values();
  $mapUrl = $contact['address'] ? 'https://www.google.com/maps?q='.urlencode($contact['address']).'&output=embed' : null;
  $socialIcon = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'x' => 'X', 'twitter' => 'X', 'linkedin' => 'LinkedIn', 'whatsapp' => 'WhatsApp', 'pinterest' => 'Pinterest'];
@endphp
<!doctype html>
<html lang="{{ $loc }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $seoTitle }}</title>
  @if($seoDescription)<meta name="description" content="{{ $seoDescription }}">@endif
  @if($preview)<meta name="robots" content="noindex">@endif
  @if($favicon)<link rel="icon" href="{{ $img($favicon) }}">@endif
  <link rel="stylesheet" href="{{ asset('store_files/css/bootstrap.min.css') }}">
  <style>
    :root { --accent: {{ $accent }}; --accent-rgb: {{ $rgb }}; }
    html { scroll-behavior: smooth; scroll-padding-top: 72px; }
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #212529; }
    .op-serif h1, .op-serif h2, .op-serif h3, .op-serif .navbar-brand { font-family: Georgia, "Times New Roman", serif; }
    .text-accent { color: var(--accent) !important; }
    .bg-accent { background: var(--accent) !important; color: #fff; }
    .bg-accent-soft { background: rgba(var(--accent-rgb), .08); }
    .btn-accent { background: var(--accent); border-color: var(--accent); color: #fff; }
    .btn-accent:hover, .btn-accent:focus { filter: brightness(.9); color: #fff; background: var(--accent); }
    .btn-outline-accent { border-color: var(--accent); color: var(--accent); }
    .btn-outline-accent:hover { background: var(--accent); color: #fff; }
    .navbar .nav-link.active { color: var(--accent) !important; }
    .navbar-dark .nav-link.active { color: #fff !important; text-decoration: underline; text-underline-offset: 6px; }
    .navbar-brand img { max-height: 40px; width: auto; }
    section { padding: 80px 0; }
    .op-title { font-weight: 700; margin-bottom: .5rem; }
    .op-title::after { content: ""; display: block; width: 56px; height: 3px; background: var(--accent); margin-top: .75rem; }
    .text-center .op-title::after { margin-left: auto; margin-right: auto; }
    .op-lead { color: #6c757d; font-size: 1.1rem; }
    /* hero variants */
    .op-hero { position: relative; color: #fff; background: #222 center / cover no-repeat; }
    .op-hero::before { content: ""; position: absolute; inset: 0; background: linear-gradient(120deg, rgba(0,0,0,.65), rgba(var(--accent-rgb), .45)); }
    .op-hero > .container { position: relative; }
    .op-hero-carousel { min-height: 72vh; display: flex; align-items: center; padding-top: 96px; }
    .op-hero-cover { min-height: 100vh; display: flex; align-items: center; text-align: center; }
    .op-hero-cover::before { background: rgba(0,0,0,.6); }
    .op-hero-cover h1 { font-size: clamp(2.4rem, 6vw, 4.5rem); }
    .op-hero-plain { padding-top: 140px; padding-bottom: 72px; text-align: center; }
    .op-hero-split { padding-top: 120px; background: rgba(var(--accent-rgb), .06); }
    .op-hero-split .op-hero-media { border-radius: 18px; min-height: 340px; background: var(--accent) center / cover no-repeat; box-shadow: 0 20px 50px rgba(0,0,0,.15); }
    .op-hero-noimg { background-image: linear-gradient(135deg, var(--accent), #111) !important; }
    /* services variants */
    .op-icon { width: 64px; height: 64px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: rgba(var(--accent-rgb), .12); color: var(--accent); font-weight: 700; font-size: 1.4rem; }
    .op-menu-row { display: flex; align-items: baseline; gap: 10px; }
    .op-menu-row .op-dots { flex: 1; border-bottom: 2px dotted #ced4da; transform: translateY(-4px); }
    .op-card { border: 0; border-radius: 16px; overflow: hidden; box-shadow: 0 6px 24px rgba(0,0,0,.07); height: 100%; transition: transform .2s, box-shadow .2s; }
    .op-card:hover { transform: translateY(-3px); box-shadow: 0 14px 34px rgba(0,0,0,.12); }
    .op-card-media { aspect-ratio: 4 / 3; background: rgba(var(--accent-rgb), .12) center / cover no-repeat; }
    .op-price { font-size: 2.2rem; font-weight: 700; color: var(--accent); }
    .op-feature { border-left: 4px solid var(--accent); padding: 18px 22px; background: #fff; border-radius: 0 12px 12px 0; box-shadow: 0 4px 18px rgba(0,0,0,.05); height: 100%; }
    .op-featurette-img { border-radius: 18px; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; box-shadow: 0 16px 40px rgba(0,0,0,.12); }
    .op-gallery a { display: block; border-radius: 12px; overflow: hidden; }
    .op-gallery img { width: 100%; aspect-ratio: 1; object-fit: cover; transition: transform .3s; }
    .op-gallery a:hover img { transform: scale(1.05); }
    .op-quote { background: #fff; border-radius: 16px; padding: 26px; box-shadow: 0 6px 24px rgba(0,0,0,.06); height: 100%; }
    .op-quote p::before { content: "“"; color: var(--accent); font-size: 2.4rem; line-height: 0; vertical-align: -.6rem; margin-right: 4px; }
    .op-dark { background: #14171c; color: #e9ecef; }
    .op-dark .op-lead, .op-dark .text-body-secondary { color: #adb5bd !important; }
    .op-map { width: 100%; min-height: 320px; border: 0; border-radius: 16px; background: rgba(var(--accent-rgb), .08); }
    .op-contact a { color: inherit; }
    .op-contact a:hover { color: var(--accent); }
    .op-hero-split .op-hero-media.op-noimg { display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--accent), #0b1320); color: rgba(255,255,255,.18); font-size: 9rem; font-weight: 800; }
    .op-check { width: 64px; height: 64px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: rgba(var(--accent-rgb), .12); color: var(--accent); }
    .op-card-top { border-top: 4px solid var(--accent); }
    footer a { color: inherit; }
    .op-preview-bar { position: fixed; bottom: 12px; left: 50%; transform: translateX(-50%); z-index: 2000; background: #111; color: #fff; padding: 6px 14px; border-radius: 30px; font-size: .85rem; opacity: .85; }
  </style>
</head>
<body class="op-{{ $tpl }} {{ $serif ? 'op-serif' : '' }}" data-bs-spy="scroll" data-bs-target="#op-nav" data-bs-offset="80" tabindex="0">

<nav id="op-nav" class="navbar navbar-expand-lg fixed-top {{ $darkNav ? 'navbar-dark bg-dark' : 'navbar-light bg-white shadow-sm' }}">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="#{{ $sections[0]['id'] ?? '' }}">
      @if($logo)<img src="{{ $img($logo) }}" alt="{{ $siteName }}">@endif
      <span @if($logo) class="d-none d-sm-inline" @endif>{{ $siteName }}</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#op-menu" aria-controls="op-menu" aria-expanded="false" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="op-menu">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        @foreach($menu as $s)
          <li class="nav-item"><a class="nav-link" href="#{{ $s['id'] }}">{{ $t($s['menu']) }}</a></li>
        @endforeach
        @foreach($page['links'] ?? [] as $l)
          <li class="nav-item"><a class="nav-link" href="{{ $l['url'] }}" @if(str_starts_with($l['url'], 'http')) target="_blank" rel="noopener" @endif>{{ $t($l['label']) }}</a></li>
        @endforeach
        @if(count($locales) > 1)
          <li class="nav-item ms-lg-2 small">
            @foreach($locales as $l)
              <a class="nav-link d-inline px-1 {{ $l === $loc ? 'fw-bold' : 'opacity-75' }}" href="{{ request()->fullUrlWithQuery(['lang' => $l]) }}">{{ strtoupper($l) }}</a>
            @endforeach
          </li>
        @endif
      </ul>
    </div>
  </div>
</nav>

@foreach($sections as $i => $s)
  @php
    $title = $t($s['title']); $text = $t($s['text']); $btn = $t($s['button']);
    $alt = $i % 2 === 0;
  @endphp

  @switch($s['type'])

    @case('hero')
      @if($tpl === 'corporate')
        <section id="{{ $s['id'] }}" class="op-hero-split">
          <div class="container">
            <div class="row align-items-center g-5">
              <div class="col-lg-6">
                <h1 class="display-5 fw-bold">{{ $title }}</h1>
                @if($text)<p class="lead text-body-secondary my-4">{!! $para($text) !!}</p>@endif
                @if($btn && $s['link'])<a href="{{ $s['link'] }}" class="btn btn-accent btn-lg px-4">{{ $btn }}</a>@endif
              </div>
              <div class="col-lg-6">
                @if($s['image'])<div class="op-hero-media" style="background-image:url('{{ $img($s['image']) }}')"></div>
                @else<div class="op-hero-media op-noimg" aria-hidden="true">{{ mb_strtoupper(mb_substr($siteName, 0, 1)) }}</div>@endif
              </div>
            </div>
          </div>
        </section>
      @elseif(in_array($tpl, ['beauty', 'cleaning'], true))
        <section id="{{ $s['id'] }}" class="op-hero-plain {{ $tpl === 'cleaning' ? 'bg-accent-soft' : '' }}">
          <div class="container" style="max-width: 820px">
            <h1 class="display-4 fw-bold">{{ $title }}</h1>
            @if($text)<p class="lead text-body-secondary my-4">{!! $para($text) !!}</p>@endif
            @if($btn && $s['link'])<a href="{{ $s['link'] }}" class="btn btn-accent btn-lg px-4 my-2">{{ $btn }}</a>@endif
            @if($s['image'])<img src="{{ $img($s['image']) }}" alt="{{ $title }}" class="op-featurette-img mt-5" style="aspect-ratio: 16 / 7">@endif
          </div>
        </section>
      @else
        <section id="{{ $s['id'] }}" class="op-hero {{ $tpl === 'food' ? 'op-hero-cover' : 'op-hero-carousel' }} {{ $s['image'] ? '' : 'op-hero-noimg' }}" @if($s['image']) style="background-image:url('{{ $img($s['image']) }}')" @endif>
          <div class="container" @if($tpl !== 'food') style="max-width: 1140px" @endif>
            <div @if($tpl !== 'food') class="col-lg-8" @endif>
              <h1 class="display-4 fw-bold">{{ $title }}</h1>
              @if($text)<p class="lead my-4">{!! $para($text) !!}</p>@endif
              @if($btn && $s['link'])<a href="{{ $s['link'] }}" class="btn btn-accent btn-lg px-4">{{ $btn }}</a>@endif
            </div>
          </div>
        </section>
      @endif
      @break

    @case('about')
      <section id="{{ $s['id'] }}" class="{{ $tpl === 'corporate' ? 'op-dark' : ($alt ? '' : 'bg-light') }}">
        <div class="container">
          @if($s['image'])
            <div class="row align-items-center g-5">
              <div class="col-md-6 {{ $tpl === 'services' ? 'order-md-2' : '' }}"><img src="{{ $img($s['image']) }}" alt="{{ $title }}" class="op-featurette-img"></div>
              <div class="col-md-6">
                <h2 class="op-title">{{ $title }}</h2>
                <p class="op-lead mt-3">{!! $para($text) !!}</p>
                @if($btn && $s['link'])<a href="{{ $s['link'] }}" class="btn btn-outline-accent mt-2">{{ $btn }}</a>@endif
              </div>
            </div>
          @else
            <div class="text-center mx-auto" style="max-width: 760px">
              <h2 class="op-title">{{ $title }}</h2>
              <p class="op-lead mt-3">{!! $para($text) !!}</p>
              @if($btn && $s['link'])<a href="{{ $s['link'] }}" class="btn btn-outline-accent mt-2">{{ $btn }}</a>@endif
            </div>
          @endif
        </div>
      </section>
      @break

    @case('services')
      <section id="{{ $s['id'] }}" class="{{ $tpl === 'food' ? 'op-dark' : ($alt ? '' : 'bg-light') }}">
        <div class="container">
          <div class="text-center mb-5 mx-auto" style="max-width: 720px">
            <h2 class="op-title">{{ $title }}</h2>
            @if($text)<p class="op-lead mt-3">{!! $para($text) !!}</p>@endif
          </div>
          @if($tpl === 'food')
            <div class="row g-4 mx-auto" style="max-width: 980px">
              @foreach($s['items'] as $it)
                <div class="col-md-6">
                  <div class="op-menu-row"><strong class="fs-5">{{ $t($it['title']) }}</strong><span class="op-dots"></span>@if($showPrices && $it['price'])<strong class="text-accent">{{ $it['price'] }}</strong>@endif</div>
                  @if($t($it['text']))<div class="text-body-secondary small mt-1">{{ $t($it['text']) }}</div>@endif
                </div>
              @endforeach
            </div>
          @elseif($tpl === 'cleaning')
            <div class="row row-cols-1 row-cols-md-3 g-4 text-center">
              @foreach($s['items'] as $it)
                <div class="col">
                  <div class="card op-card">
                    <div class="card-header py-3 bg-transparent border-0"><h4 class="my-0 fw-semibold">{{ $t($it['title']) }}</h4></div>
                    <div class="card-body d-flex flex-column">
                      @if($showPrices && $it['price'])<div class="op-price mb-3">{{ $it['price'] }}</div>@endif
                      <p class="text-body-secondary flex-grow-1">{{ $t($it['text']) }}</p>
                      <a href="#contact" class="btn btn-lg btn-outline-accent w-100">{{ $loc === 'es' ? 'Solicitar' : 'Request' }}</a>
                    </div>
                  </div>
                </div>
              @endforeach
            </div>
          @elseif($tpl === 'beauty')
            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-4">
              @foreach($s['items'] as $it)
                <div class="col">
                  <div class="card op-card {{ $it['image'] ? '' : 'op-card-top' }}">
                    @if($it['image'])<div class="op-card-media" style="background-image:url('{{ $img($it['image']) }}')"></div>@endif
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-start gap-2">
                        <h5 class="card-title mb-1">{{ $t($it['title']) }}</h5>
                        @if($showPrices && $it['price'])<span class="badge rounded-pill bg-accent">{{ $it['price'] }}</span>@endif
                      </div>
                      <p class="card-text text-body-secondary small mt-2">{{ $t($it['text']) }}</p>
                    </div>
                  </div>
                </div>
              @endforeach
            </div>
          @elseif($tpl === 'corporate')
            <div class="row row-cols-1 row-cols-md-2 g-4">
              @foreach($s['items'] as $it)
                <div class="col"><div class="op-feature">
                  <h5 class="fw-semibold mb-1">{{ $t($it['title']) }} @if($showPrices && $it['price'])<small class="text-accent ms-1">{{ $it['price'] }}</small>@endif</h5>
                  <p class="text-body-secondary mb-0">{{ $t($it['text']) }}</p>
                </div></div>
              @endforeach
            </div>
          @else
            <div class="row g-5 text-center">
              @foreach($s['items'] as $n => $it)
                <div class="col-md-6 col-lg-4">
                  @if($it['image'])<img src="{{ $img($it['image']) }}" alt="" class="rounded-circle mb-3" width="96" height="96" style="object-fit: cover">
                  @else<div class="op-check mb-3"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></div>@endif
                  <h4 class="fw-semibold">{{ $t($it['title']) }}</h4>
                  @if($showPrices && $it['price'])<div class="text-accent fw-bold mb-1">{{ $it['price'] }}</div>@endif
                  <p class="text-body-secondary">{{ $t($it['text']) }}</p>
                </div>
              @endforeach
            </div>
          @endif
        </div>
      </section>
      @break

    @case('gallery')
      @if($gallery->count())
        <section id="{{ $s['id'] }}" class="{{ $alt ? '' : 'bg-light' }}">
          <div class="container">
            <div class="text-center mb-5"><h2 class="op-title">{{ $title }}</h2>@if($text)<p class="op-lead mt-3">{!! $para($text) !!}</p>@endif</div>
            <div class="row g-3 op-gallery">
              @foreach($gallery as $g)
                <div class="col-6 col-md-4 col-lg-3"><a href="{{ asset($g->image) }}" target="_blank" rel="noopener"><img src="{{ asset($g->image) }}" alt="{{ $g->title }}" loading="lazy"></a></div>
              @endforeach
            </div>
          </div>
        </section>
      @endif
      @break

    @case('portfolio')
      @if($portfolio->count())
        <section id="{{ $s['id'] }}" class="{{ $alt ? '' : 'bg-light' }}">
          <div class="container">
            <div class="text-center mb-5"><h2 class="op-title">{{ $title }}</h2>@if($text)<p class="op-lead mt-3">{!! $para($text) !!}</p>@endif</div>
            <div class="row row-cols-1 row-cols-md-3 g-4">
              @foreach($portfolio as $p)
                <div class="col"><div class="card op-card">
                  <div class="op-card-media" @if($p->cover) style="background-image:url('{{ asset($p->cover) }}')" @endif></div>
                  <div class="card-body"><h5 class="card-title">{{ $p->title }}</h5><p class="card-text text-body-secondary small">{{ \Illuminate\Support\Str::limit(strip_tags((string) $p->description), 140) }}</p></div>
                </div></div>
              @endforeach
            </div>
          </div>
        </section>
      @endif
      @break

    @case('testimonials')
      @if(count($s['items']))
        <section id="{{ $s['id'] }}" class="bg-accent-soft">
          <div class="container">
            <div class="text-center mb-5"><h2 class="op-title">{{ $title }}</h2>@if($text)<p class="op-lead mt-3">{!! $para($text) !!}</p>@endif</div>
            <div class="row row-cols-1 row-cols-md-3 g-4">
              @foreach($s['items'] as $it)
                <div class="col"><div class="op-quote">
                  <p class="mb-3">{{ $t($it['text']) }}</p>
                  <strong>{{ $t($it['title']) }}</strong>
                </div></div>
              @endforeach
            </div>
          </div>
        </section>
      @endif
      @break

    @case('cta')
      <section id="{{ $s['id'] }}" class="bg-accent text-center" style="padding: 64px 0">
        <div class="container" style="max-width: 760px">
          <h2 class="fw-bold">{{ $title }}</h2>
          @if($text)<p class="lead opacity-75 mt-3">{!! $para($text) !!}</p>@endif
          @if($btn && $s['link'])<a href="{{ $s['link'] }}" class="btn btn-light btn-lg px-4 mt-2 fw-semibold" style="color: var(--accent)">{{ $btn }}</a>@endif
        </div>
      </section>
      @break

    @case('contact')
      <section id="{{ $s['id'] }}" class="{{ $tpl === 'corporate' || $tpl === 'food' ? 'op-dark' : '' }}">
        <div class="container">
          <div class="row g-5 align-items-center">
            <div class="col-lg-5">
              <h2 class="op-title">{{ $title }}</h2>
              @if($text)<p class="op-lead mt-3">{!! $para($text) !!}</p>@endif
              <ul class="list-unstyled fs-5 mt-4 op-contact">
                @if($contact['phone'])<li class="mb-3"><span class="text-accent me-2">&#9742;</span><a class="text-decoration-none" href="tel:{{ preg_replace('/[^\d+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></li>@endif
                @if($contact['email'])<li class="mb-3"><span class="text-accent me-2">&#9993;</span><a class="text-decoration-none" href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></li>@endif
                @if($contact['address'])<li class="mb-3"><span class="text-accent me-2">&#9906;</span>{{ $contact['address'] }}</li>@endif
              </ul>
              @if($btn && $s['link'])<a href="{{ $s['link'] }}" class="btn btn-accent btn-lg mt-2">{{ $btn }}</a>@endif
            </div>
            <div class="col-lg-7">
              @if($mapUrl)<iframe class="op-map" src="{{ $mapUrl }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map"></iframe>
              @elseif($s['image'])<img src="{{ $img($s['image']) }}" alt="" class="op-featurette-img">@endif
            </div>
          </div>
        </div>
      </section>
      @break

  @endswitch
@endforeach

<footer class="py-4 {{ $darkNav ? 'bg-dark text-white-50' : 'bg-light text-body-secondary' }}">
  <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 small">
    <span>{{ $footer }}</span>
    @if(count($social))
      <span class="d-flex gap-3">
        @foreach($social as $l)
          @php $u = \App\Support\OnePageTemplates::link($l['url']); @endphp
          @if($u)<a href="{{ $u }}" target="_blank" rel="noopener">{{ $socialIcon[strtolower($l['platform'] ?? '')] ?? ucfirst((string) ($l['platform'] ?? 'Link')) }}</a>@endif
        @endforeach
      </span>
    @endif
  </div>
</footer>

@if($preview)<div class="op-preview-bar">Preview</div>@endif
<script src="{{ asset('store_files/js/bootstrap.bundle.min.js') }}"></script>
<script>
  // Close the phone menu after picking a section.
  document.querySelectorAll('#op-menu .nav-link[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function () {
      var m = document.getElementById('op-menu');
      if (m.classList.contains('show')) bootstrap.Collapse.getOrCreateInstance(m).hide();
    });
  });
</script>
</body>
</html>
