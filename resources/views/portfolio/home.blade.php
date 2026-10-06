{{-- Portfolio module: Home -- the company's sections, top to bottom (Portfolio -> Home sections). --}}
@extends($site->layout_view)

@section('title', 'Home')

@push('head')
  <style>
    .pf-sec { padding: 60px 0; }
    .pf-sec h2 { font-family: 'Playfair Display', serif; }
    .pf-sec .pf-sub { opacity: .75; margin-bottom: 18px; }
    .pf-sec-text { max-width: 820px; margin: 0 auto; text-align: center; }
    .pf-sec-image { position: relative; }
    .pf-sec-image img { width: 100%; max-height: 640px; object-fit: cover; display: block; }
    .pf-sec-image .pf-cap { position: absolute; inset: auto 0 0 0; padding: 40px 20px 30px; text-align: center; background: linear-gradient(transparent, rgba(0, 0, 0, .65)); color: #fff; }
    .pf-sec-image .pf-cap h2, .pf-sec-image .pf-cap p { color: #fff; }
    .pf-sec-it img { width: 100%; border-radius: 4px; }
    .pf-sec-video .project-video { width: 100%; aspect-ratio: 16 / 9; height: auto; }
    .pf-sec .btn-invert { margin-top: 18px; }
    .pf-cards .card { padding: 24px; text-align: center; height: 100%; }
    .pf-cards .card i { font-size: 26px; margin-bottom: 10px; }
  </style>
@endpush

@section('content')
  @forelse ($sections as $sec)
    @php
      $lines = fn ($t) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $t))));
      $paras = fn ($t) => array_values(array_filter(preg_split('/\R\s*\R/', trim((string) $t)) ?: [], fn ($p) => trim($p) !== ''));
      $bg = $sec->bg_color ? 'background-color: '.$sec->bg_color.';' : '';
    @endphp

    @if ($sec->type === 'gallery')
      <div style="{{ $bg }}">
        @if ($sec->title)
          <div class="section-title" style="padding-top: 30px"><h2>{{ $sec->title }}</h2>@if ($sec->subtitle)<p>{{ $sec->subtitle }}</p>@endif</div>
        @endif
        @include($site->gallery_view, ['uid' => 'home-'.$sec->id, 'projects' => $sec->projects, 'categories' => $sec->categories, 'showAll' => $sec->settings['show_all']])
      </div>

    @elseif ($sec->type === 'text')
      <section class="pf-sec" style="{{ $bg }}">
        <div class="container pf-sec-text">
          @if ($sec->title)<h2>{{ $sec->title }}</h2>@endif
          @if ($sec->subtitle)<p class="pf-sub">{{ $sec->subtitle }}</p>@endif
          @foreach ($paras($sec->body) as $p)<p>{!! nl2br(e(trim($p))) !!}</p>@endforeach
          @if ($sec->button_label && $sec->button_link)<a href="{{ $sec->button_link }}" class="btn btn-invert">{{ $sec->button_label }}</a>@endif
        </div>
      </section>

    @elseif ($sec->type === 'image' && $sec->image)
      <section class="pf-sec-image" style="{{ $bg }}">
        <img src="{{ asset($sec->image) }}" alt="{{ $sec->title }}">
        @if ($sec->title || $sec->subtitle)
          <div class="pf-cap">
            @if ($sec->title)<h2>{{ $sec->title }}</h2>@endif
            @if ($sec->subtitle)<p>{{ $sec->subtitle }}</p>@endif
            @if ($sec->button_label && $sec->button_link)<a href="{{ $sec->button_link }}" class="btn btn-invert">{{ $sec->button_label }}</a>@endif
          </div>
        @endif
      </section>

    @elseif ($sec->type === 'image_text')
      <section class="pf-sec pf-sec-it" style="{{ $bg }}">
        <div class="container">
          <div class="row align-items-center">
            @if ($sec->image)
              <div class="col-md-6 mb-4 mb-md-0"><img src="{{ asset($sec->image) }}" alt="{{ $sec->title }}"></div>
            @endif
            <div class="{{ $sec->image ? 'col-md-6' : 'col-md-8 offset-md-2' }}">
              @if ($sec->title)<h2>{{ $sec->title }}</h2>@endif
              @if ($sec->subtitle)<p class="pf-sub">{{ $sec->subtitle }}</p>@endif
              @foreach ($paras($sec->body) as $p)<p class="text-justify">{!! nl2br(e(trim($p))) !!}</p>@endforeach
              @if ($sec->button_label && $sec->button_link)<a href="{{ $sec->button_link }}" class="btn btn-invert">{{ $sec->button_label }}</a>@endif
            </div>
          </div>
        </div>
      </section>

    @elseif ($sec->type === 'video' && $sec->video_url)
      <section class="pf-sec pf-sec-video" style="{{ $bg }}">
        <div class="container">
          @if ($sec->title)<div class="section-title"><h2>{{ $sec->title }}</h2>@if ($sec->subtitle)<p>{{ $sec->subtitle }}</p>@endif</div>@endif
          <div class="project-holder">
            <iframe class="project-video" src="{{ $sec->video_url }}" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>
          </div>
        </div>
      </section>

    @elseif ($sec->type === 'contact')
      @php $phones = $lines($site->contact_phones); $emails = $lines($site->contact_emails); @endphp
      <section class="pf-sec contact" style="{{ $bg }}">
        <div class="container">
          <div class="section-title">
            <h2>{{ $sec->title ?: 'Contact' }}</h2>
            <p>{{ $sec->subtitle ?: ($site->contact_subtitle ?: 'Get in touch with us') }}</p>
          </div>
          <div class="contact-info pf-cards">
            <div class="row">
              @if ($site->contact_address)
                <div class="col-md-4 mb-3"><div class="card"><i class="fas fa-map"></i><p>{!! nl2br(e($site->contact_address)) !!}</p></div></div>
              @endif
              @if ($phones)
                <div class="col-md-4 mb-3"><div class="card"><i class="fas fa-mobile"></i>@foreach ($phones as $ph)<a href="tel:{{ preg_replace('/[^\d+]/', '', $ph) }}">{{ $ph }}</a>@endforeach</div></div>
              @endif
              @if ($emails)
                <div class="col-md-4 mb-3"><div class="card"><i class="fas fa-envelope-open"></i>@foreach ($emails as $em)<a href="mailto:{{ $em }}">{{ $em }}</a>@endforeach</div></div>
              @endif
            </div>
          </div>
          @if ($site->pages['contact'])
            <div class="text-center"><a href="{{ route('portfolio.contact', $site->slug) }}" class="btn btn-invert">{{ $sec->button_label ?: 'Send us a message' }}</a></div>
          @endif
        </div>
      </section>
    @endif
  @empty
    <div class="pf-empty">This website has no sections yet.</div>
  @endforelse
@endsection

@push('scripts')
  @include('portfolio.partials.mixitup')
@endpush
