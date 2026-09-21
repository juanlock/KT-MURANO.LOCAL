@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  $nlBtn = __('messages.Subscribe');
  $trustCols = max(1, min(6, count($opts['trust'])));
  $statCols = max(1, min(6, count($stats)));
  $catCols = max(4, min(8, $categoryTiles->count() ?: 8));
  $featuredTabs = collect([
      'top_picks' => __('messages.TopPicks'),
      'best_rated' => __('messages.BestRated'),
      'on_sale' => __('messages.OnSale'),
  ])->filter(fn ($label, $key) => $featured[$key]->isNotEmpty());
  $firstTab = $featuredTabs->keys()->first();
@endphp

<div class="el-container">

  {{-- ===== Hero slider ===== --}}
  @if($slides->isNotEmpty())
    <section class="el-hero"
             x-data="{ i: 0, n: {{ $slides->count() }}, t: null,
                       go(k) { this.i = (k + this.n) % this.n; this.restart(); },
                       restart() { clearInterval(this.t); if (this.n > 1) this.t = setInterval(() => { this.i = (this.i + 1) % this.n; }, 6500); } }"
             x-init="restart()" @mouseenter="clearInterval(t)" @mouseleave="restart()">
      @foreach($slides as $k => $slide)
        <div class="el-slide {{ $k === 0 ? 'is-active' : '' }}" :class="{ 'is-active': i === {{ $k }} }" aria-hidden="{{ $k === 0 ? 'false' : 'true' }}">
          <div class="el-slide-copy">
            @if(!empty($slide['kicker']))<div class="el-slide-kicker">{{ $slide['kicker'] }}</div>@endif
            <h1 class="el-slide-title">{{ $slide['title'] ?? $s->localizedText('hero_title') }}</h1>
            @if(!empty($slide['subtitle']))<p class="el-slide-sub">{{ $slide['subtitle'] }}</p>@endif
            <div class="el-slide-actions">
              @if(!empty($slide['primary_text']))
                <a href="{{ $slide['primary_href'] }}" class="el-btn el-btn-primary">{{ $slide['primary_text'] }}<x-store.icon name="arrow-right" class="w-4 h-4" /></a>
              @endif
              @if(!empty($slide['secondary_text']))
                <a href="{{ $slide['secondary_href'] }}" class="el-btn el-btn-outline">{{ $slide['secondary_text'] }}</a>
              @endif
            </div>
          </div>
          <div class="el-slide-media">
            @if(!empty($slide['image_url']))
              <img src="{{ $slide['image_url'] }}" alt="{{ $slide['title'] ?? '' }}" loading="{{ $k === 0 ? 'eager' : 'lazy' }}" fetchpriority="{{ $k === 0 ? 'high' : 'auto' }}">
            @endif
          </div>
        </div>
      @endforeach
      @if($slides->count() > 1)
        <div class="el-hero-dots" role="tablist">
          @foreach($slides as $k => $slide)
            <button type="button" :class="{ 'is-active': i === {{ $k }} }" class="{{ $k === 0 ? 'is-active' : '' }}" @click="go({{ $k }})" aria-label="Slide {{ $k + 1 }}"></button>
          @endforeach
        </div>
        <div class="el-hero-arrows">
          <button type="button" @click="go(i - 1)" aria-label="{{ __('messages.Previous') }}"><x-store.icon name="chevron-left" /></button>
          <button type="button" @click="go(i + 1)" aria-label="{{ __('messages.Next') }}"><x-store.icon name="chevron-right" /></button>
        </div>
      @endif
    </section>
  @endif

  {{-- ===== Trust strip ===== --}}
  @if($sections['trust'] && count($opts['trust']))
    <div class="el-trust" style="--el-trust-cols: {{ $trustCols }}">
      @foreach($opts['trust'] as $item)
        <div class="el-trust-item">
          <span class="el-trust-icon"><x-store.icon :name="$item['icon'] ?: 'check-circle'" /></span>
          <div>
            <strong>{{ $item['title'] }}</strong>
            @if(!empty($item['subtitle']))<span>{{ $item['subtitle'] }}</span>@endif
          </div>
        </div>
      @endforeach
    </div>
  @endif

  {{-- ===== Shop by category ===== --}}
  @if($sections['categories'] && $categoryTiles->isNotEmpty())
    <section class="el-section">
      <div class="el-section-head">
        <h2 class="el-h2">{{ __('messages.ShopByCategory') }}</h2>
        <a href="{{ route('store.shop') }}" class="el-link-all">{{ __('messages.ViewAllCategories') }}<x-store.icon name="arrow-right" class="w-3.5 h-3.5" /></a>
      </div>
      <div class="el-cats" style="--el-cat-cols: {{ $catCols }}">
        @foreach($categoryTiles as $tile)
          <a href="{{ $tile['url'] }}" class="el-cat">
            <span class="el-cat-media">
              @if($tile['image_url'])
                <img src="{{ $tile['image_url'] }}" alt="{{ $tile['name'] }}" loading="lazy">
              @else
                <x-store.icon :name="$tile['icon'] ?: 'grid'" />
              @endif
            </span>
            <strong>{{ $tile['name'] }}</strong>
            <small>{{ trans_choice('messages.products', $tile['count'], ['count' => $tile['count']]) }}</small>
          </a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Featured products (tabs) ===== --}}
  @if($sections['featured'] && $featuredTabs->isNotEmpty())
    <section class="el-section" x-data="{ tab: '{{ $firstTab }}' }">
      <div class="el-section-head">
        <div class="flex items-center gap-4 flex-wrap">
          <h2 class="el-h2">{{ __('messages.FeaturedProducts') }}</h2>
          <div class="el-tabs" role="tablist">
            @foreach($featuredTabs as $key => $label)
              <button type="button" class="el-tab {{ $key === $firstTab ? 'is-active' : '' }}" :class="{ 'is-active': tab === '{{ $key }}' }" @click="tab = '{{ $key }}'" role="tab">{{ $label }}</button>
            @endforeach
          </div>
        </div>
        <a href="{{ route('store.shop') }}" class="el-link-all">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" class="w-3.5 h-3.5" /></a>
      </div>
      @foreach($featuredTabs as $key => $label)
        <div class="el-grid" style="--el-cols: {{ max(3, min(6, $opts['limits']['featured'])) }}" x-show="tab === '{{ $key }}'" {{ $key === $firstTab ? '' : 'x-cloak' }} role="tabpanel">
          @foreach($featured[$key] as $p)
            @include('store.electronics.partials.product-card', ['p' => $p, 'currency' => $currency])
          @endforeach
        </div>
      @endforeach
    </section>
  @endif

  {{-- ===== Promo banners ===== --}}
  @if($sections['banners'] && $banners->isNotEmpty())
    <section class="el-section" style="padding-top: 6px">
      <div class="el-banners">
        @foreach($banners as $b)
          <a href="{{ $b['href'] }}" class="el-banner {{ ($b['tone'] ?? 'dark') === 'light' ? 'is-light' : '' }}" @if($b['image_url']) style="background-image: url('{{ $b['image_url'] }}')" @endif>
            <div class="el-banner-copy">
              @if(!empty($b['kicker']))<div class="el-banner-kicker">{{ $b['kicker'] }}</div>@endif
              <h3 class="el-banner-title">{{ $b['title'] }}</h3>
              @if(!empty($b['subtitle']))<p class="el-banner-sub">{{ $b['subtitle'] }}</p>@endif
              @if(!empty($b['button_text']))<span class="el-btn el-btn-primary">{{ $b['button_text'] }}<x-store.icon name="arrow-right" class="w-3.5 h-3.5" /></span>@endif
            </div>
          </a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Best sellers + New arrivals ===== --}}
  @if(($sections['best_sellers'] && $bestSellers->isNotEmpty()) || ($sections['new_arrivals'] && $newArrivals->isNotEmpty()))
    <section class="el-section">
      <div class="el-split" @if(!($sections['best_sellers'] && $bestSellers->isNotEmpty()) || !($sections['new_arrivals'] && $newArrivals->isNotEmpty())) style="grid-template-columns: 1fr" @endif>
        @if($sections['best_sellers'] && $bestSellers->isNotEmpty())
          <div>
            <div class="el-section-head">
              <h2 class="el-h2">{{ __('messages.BestSellers') }}</h2>
              <a href="{{ route('store.shop') }}" class="el-link-all">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" class="w-3.5 h-3.5" /></a>
            </div>
            <div class="el-panel el-rank">
              @foreach($bestSellers as $i => $p)
                @php
                  $bsPrice = (float) ($p->display_price ?? $p->price ?? 0);
                  $bsCompare = isset($p->compare_at_price) && (float) $p->compare_at_price > $bsPrice + 0.001 ? (float) $p->compare_at_price : null;
                @endphp
                <div class="el-rank-row">
                  <span class="el-rank-n">{{ $i + 1 }}</span>
                  <a href="{{ route('store.product.show', $p->id) }}"><img class="el-rank-img" src="{{ product_image_url($p->primaryProductImageFilename()) }}" alt="{{ $p->name }}" loading="lazy"></a>
                  <div class="el-rank-body">
                    <a href="{{ route('store.product.show', $p->id) }}" class="el-rank-name">{{ $p->name }}</a>
                    @if(!empty($p->rating_count))
                      <div class="el-stars"><span class="el-stars-icons">@for($k = 1; $k <= 5; $k++)<x-store.icon name="star-fill" class="{{ $k <= round($p->rating_avg) ? '' : 'is-empty' }}" />@endfor</span><b>{{ number_format($p->rating_avg, 1) }}</b><span>({{ \App\Support\ElectronicsTheme::compact((int) $p->rating_count) }})</span></div>
                    @endif
                    @unless(!Auth::guard('store')->check() && ($s->hide_prices_for_guests ?? false))
                      <div class="el-rank-price">{{ store_money($bsPrice) }}@if($bsCompare)<s>{{ store_money($bsCompare) }}</s>@endif</div>
                    @endunless
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        @endif

        @if($sections['new_arrivals'] && $newArrivals->isNotEmpty())
          <div>
            <div class="el-section-head">
              <h2 class="el-h2">{{ __('messages.NewArrivals') }}</h2>
              <a href="{{ route('store.shop', ['sort' => 'latest']) }}" class="el-link-all">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" class="w-3.5 h-3.5" /></a>
            </div>
            <div class="el-grid" style="--el-cols: {{ max(2, min(4, $opts['limits']['new_arrivals'])) }}">
              @foreach($newArrivals as $p)
                @include('store.electronics.partials.product-card', ['p' => $p, 'currency' => $currency])
              @endforeach
            </div>
          </div>
        @endif
      </div>
    </section>
  @endif

  {{-- ===== Stats ===== --}}
  @if($sections['stats'] && $stats->isNotEmpty())
    <section class="el-section" style="padding-top: 4px">
      <div class="el-stats" style="--el-stat-cols: {{ $statCols }}">
        @foreach($stats as $st)
          <div class="el-stat">
            <span class="el-stat-icon"><x-store.icon :name="$st['icon'] ?: 'check-circle'" /></span>
            <div><strong>{{ $st['value'] }}</strong><span>{{ $st['label'] }}</span></div>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Testimonials ===== --}}
  @if($sections['testimonials'] && $testimonials->isNotEmpty())
    <section class="el-section">
      <div class="el-section-head">
        <h2 class="el-h2">{{ $opts['testimonials_title'] }}</h2>
      </div>
      <div class="el-reviews">
        @foreach($testimonials as $r)
          @php $who = trim((string) ($r->reviewer_name ?: __('messages.Customer'))); @endphp
          <div class="el-review">
            <div class="el-stars"><span class="el-stars-icons">@for($k = 1; $k <= 5; $k++)<x-store.icon name="star-fill" class="{{ $k <= (int) $r->rating ? '' : 'is-empty' }}" />@endfor</span></div>
            <p>“{{ \Illuminate\Support\Str::limit($r->comment, 220) }}”</p>
            <div class="el-review-who">
              <span class="el-avatar">{{ mb_strtoupper(mb_substr($who, 0, 1)) }}</span>
              <div>
                <strong>{{ $who }}</strong>
                <small>{{ __('messages.VerifiedBuyer') }}@if($r->product) · <a class="el-review-product" href="{{ route('store.product.show', $r->product->id) }}">{{ \Illuminate\Support\Str::limit($r->product->name, 34) }}</a>@endif</small>
              </div>
            </div>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Newsletter ===== --}}
  @if($sections['newsletter'])
    <section class="el-section">
      <div class="el-newsletter">
        <span class="el-newsletter-icon"><x-store.icon name="mail" /></span>
        <div>
          <h3>{{ $opts['newsletter_title'] }}</h3>
          <p>{{ $opts['newsletter_subtitle'] }}</p>
        </div>
        <form id="newsletterForm">
          @csrf
          <input name="email" type="email" id="newsletterEmail" placeholder="{{ __('messages.NewsletterEmailPlaceholder') }}" required>
          <button id="newsletterBtn" class="el-btn el-btn-primary" type="submit">{{ $nlBtn }}</button>
        </form>
        <div id="newsletterMsg" class="el-newsletter-note">{{ __('messages.NoSpam') }}</div>
      </div>
    </section>
  @endif

  {{-- ===== Brands ===== --}}
  @if($sections['brands'] && $brands->isNotEmpty())
    <section class="el-section" style="padding-top: 0">
      <div class="el-section-head">
        <h2 class="el-h2">{{ __('messages.ShopByBrand') }}</h2>
      </div>
      <div class="el-brands">
        @foreach($brands as $b)
          <a href="{{ route('store.shop', ['brand' => $b->id]) }}" class="el-brand" title="{{ $b->name }}">
            @if($b->image && $b->image !== 'no-image.png')
              <img src="{{ asset('images/brands/'.$b->image) }}" alt="{{ $b->name }}" loading="lazy" onerror="this.remove()">
            @endif
            <span>{{ $b->name }}</span>
          </a>
        @endforeach
      </div>
    </section>
  @endif

</div>

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])
@endsection
