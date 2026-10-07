{{--
  Website Management front page (our website theme), instead of the shop's
  automatic sections: the company's pages as cards, its Portfolio, a Gallery
  strip and a contact band. Uses the parent view's $sitePages, $siteGallery,
  $sitePortfolio, $siteCompany and $s (the website settings); links via App\Support\WebsitePaths::url().
--}}
@php
  $sitePages = $sitePages ?? collect();
  $siteGallery = $siteGallery ?? collect();
  $firstImg = function ($html) {
      return preg_match('~<img[^>]+src="([^"]+)"~i', (string) $html, $m) ? $m[1] : null;
  };
  $excerpt = fn ($p) => \Illuminate\Support\Str::limit(trim($p->seo_description ?: preg_replace('/\s+/', ' ', strip_tags((string) $p->content))), 150);
  $phone = $s->contact_phone ?: ($siteCompany->phone ?? null);
  $email = $s->contact_email ?: ($siteCompany->email ?? null);
@endphp

@if($sitePages->count())
  <section class="py-12 lg:py-16">
    <div class="container">
      <div class="mb-8">
        <span class="section-kicker">{{ $siteCompany->name ?? $s->store_name }}</span>
        <h2 class="section-title mt-1">{{ __('messages.Discover') !== 'messages.Discover' ? __('messages.Discover') : 'Discover' }}</h2>
      </div>
      <div class="ws-cards">
        @foreach($sitePages as $p)
          @php $img = $firstImg($p->content); @endphp
          <a href="{{ \App\Support\WebsitePaths::url('pages/'.$p->slug, $siteCompany->id ?? null) }}" class="ws-card">
            @if($img)<span class="ws-card-media"><img src="{{ $img }}" alt="{{ $p->title }}" loading="lazy"></span>@endif
            <span class="ws-card-body">
              <strong>{{ $p->title }}</strong>
              <span>{{ $excerpt($p) }}</span>
              <em>{{ __('messages.ReadMore') !== 'messages.ReadMore' ? __('messages.ReadMore') : 'Read more' }} →</em>
            </span>
          </a>
        @endforeach
      </div>
    </div>
  </section>
@endif

{!! $sitePortfolio ?? '' !!}

@if($siteGallery->count())
  <section class="py-12 lg:py-16">
    <div class="container">
      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-8">
        <div>
          <span class="section-kicker">{{ __('messages.Gallery') }}</span>
          <h2 class="section-title mt-1">{{ __('messages.Gallery') }}</h2>
        </div>
        <a class="text-sm font-medium text-accent-500 hover:underline" href="{{ \App\Support\WebsitePaths::url('gallery', $siteCompany->id ?? null) }}">{{ __('messages.ViewAll') }} →</a>
      </div>
      <div class="ws-gallery">
        @foreach($siteGallery as $g)
          <a href="{{ \App\Support\WebsitePaths::url('gallery', $siteCompany->id ?? null) }}"><img src="{{ asset($g->image) }}" alt="{{ $g->title }}" loading="lazy"></a>
        @endforeach
      </div>
    </div>
  </section>
@endif

@if($phone || $email)
  <section class="py-12 lg:py-16">
    <div class="container">
      <div class="ws-contact">
        <div>
          <h3 class="section-title">{{ __('messages.ContactUs') }}</h3>
          <p class="text-fg-secondary mt-1">{{ $siteCompany->name ?? $s->store_name }}</p>
        </div>
        <div class="ws-contact-links">
          @if($phone)<a class="btn btn-secondary btn-lg" href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a>@endif
          @if($email)<a class="btn btn-primary btn-lg" href="mailto:{{ $email }}">{{ $email }}</a>@endif
        </div>
      </div>
    </div>
  </section>
@endif

<style>
  .ws-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px; }
  .ws-card { display: flex; flex-direction: column; border: 1px solid rgb(var(--color-line-subtle)); border-radius: 14px; overflow: hidden; background: rgb(var(--color-bg-surface)); transition: box-shadow .2s, transform .2s; color: inherit; text-decoration: none; }
  .ws-card:hover { box-shadow: 0 10px 28px rgb(0 0 0 / .08); transform: translateY(-2px); }
  .ws-card-media { aspect-ratio: 16 / 10; overflow: hidden; background: rgb(var(--color-bg-muted)); }
  .ws-card-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .ws-card-body { display: flex; flex-direction: column; gap: 6px; padding: 16px 18px 18px; }
  .ws-card-body strong { font-size: 1.1rem; color: rgb(var(--color-fg-primary)); }
  .ws-card-body span { font-size: .92rem; color: rgb(var(--color-fg-secondary)); line-height: 1.5; }
  .ws-card-body em { font-style: normal; font-size: .9rem; font-weight: 600; color: rgb(var(--color-accent-500)); margin-top: 4px; }
  .ws-gallery { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
  .ws-gallery img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 10px; display: block; }
  @media (max-width: 767px) { .ws-gallery { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  .ws-contact { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 18px; padding: 28px 30px; border-radius: 16px; background: linear-gradient(135deg, rgb(var(--color-accent-500) / .10), rgb(var(--color-bg-surface))); border: 1px solid rgb(var(--color-line-subtle)); }
  .ws-contact-links { display: flex; flex-wrap: wrap; gap: 10px; }
</style>
