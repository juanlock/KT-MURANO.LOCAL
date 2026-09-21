@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  $nlBtn = __('messages.Subscribe');
  $catCols = max(5, min(10, $categoryTiles->count()));
  $sideTiles = $tiles->take(2);
  $promoTiles = $tiles->slice(2)->values();
@endphp

<div class="gr-container">

  {{-- ===== Hero: main slider + 2 side tiles ===== --}}
  @if($slides->isNotEmpty() || $sideTiles->isNotEmpty())
    <section class="gr-hero">
      @if($slides->isNotEmpty())
        <div class="gr-hero-main" x-data="{ i: 0, n: {{ $slides->count() }}, t: null, go(k) { this.i = (k + this.n) % this.n; this.restart(); }, restart() { clearInterval(this.t); if (this.n > 1) this.t = setInterval(() => { this.i = (this.i + 1) % this.n; }, 7000); } }" x-init="restart()">
          @foreach($slides as $k => $slide)
            <div class="gr-slide {{ $k === 0 ? 'is-active' : '' }}" :class="{ 'is-active': i === {{ $k }} }">
              @if(!empty($slide['image_url']))<img src="{{ $slide['image_url'] }}" alt="" loading="{{ $k === 0 ? 'eager' : 'lazy' }}" fetchpriority="{{ $k === 0 ? 'high' : 'auto' }}">@endif
              <div class="gr-hero-copy">
                @if(!empty($slide['badge']))<span class="gr-hero-badge"><x-store.icon name="percent" />{{ $slide['badge'] }}</span>@endif
                @if(!empty($slide['kicker']))<div class="gr-hero-kicker">{{ $slide['kicker'] }}</div>@endif
                <h1 class="gr-hero-title">{{ $slide['title'] ?? $s->localizedText('hero_title') }}</h1>
                @if(!empty($slide['subtitle']))<p class="gr-hero-sub">{{ $slide['subtitle'] }}</p>@endif
                <div class="gr-hero-actions">
                  @if(!empty($slide['primary_text']))<a href="{{ $slide['primary_href'] }}" class="gr-btn gr-btn-orange">{{ $slide['primary_text'] }}<x-store.icon name="arrow-right" /></a>@endif
                  @if(!empty($slide['secondary_text']))<a href="{{ $slide['secondary_href'] }}" class="gr-btn gr-btn-white">{{ $slide['secondary_text'] }}</a>@endif
                </div>
              </div>
            </div>
          @endforeach
          @if($slides->count() > 1)
            <div class="gr-hero-dots">@foreach($slides as $k => $slide)<button type="button" :class="{ 'is-active': i === {{ $k }} }" class="{{ $k === 0 ? 'is-active' : '' }}" @click="go({{ $k }})" aria-label="Slide {{ $k + 1 }}"></button>@endforeach</div>
          @endif
        </div>
      @endif
      @if($sideTiles->isNotEmpty())
        <div class="gr-hero-side">
          @foreach($sideTiles as $t)
            <a href="{{ $t['href'] }}" class="gr-tile" style="background: {{ $t['colors']['bg'] }}">
              @if($t['image_url'])<img src="{{ $t['image_url'] }}" alt="{{ $t['title'] }}" loading="lazy">@endif
              <small style="color: {{ $t['colors']['fg'] }}">{{ $t['subtitle'] }}</small>
              <h3>{{ $t['title'] }}</h3>
              <span class="gr-tile-cta" style="color: {{ $t['colors']['fg'] }}">{{ $t['button_text'] ?: __('messages.ShopNow') }}<x-store.icon name="arrow-right" /></span>
            </a>
          @endforeach
        </div>
      @endif
    </section>
  @endif

  {{-- ===== Category strip ===== --}}
  @if($sections['categories'] && $categoryTiles->isNotEmpty())
    <section class="gr-section">
      <div class="gr-head">
        <h2 class="gr-h2"><x-store.icon name="basket" />{{ __('messages.ShopByCategory') }}</h2>
        <a href="{{ route('store.shop') }}" class="gr-link">{{ __('messages.ViewAllCategories') }}<x-store.icon name="arrow-right" /></a>
      </div>
      <div class="gr-cats" style="--gr-cat-cols: {{ $catCols }}">
        @foreach($categoryTiles->take($opts['limits']['categories']) as $tile)
          <a href="{{ $tile['url'] }}" class="gr-cat">
            <span class="gr-cat-circle">@if($tile['image_url'])<img src="{{ $tile['image_url'] }}" alt="{{ $tile['name'] }}" loading="lazy">@else<x-store.icon :name="$tile['icon']" />@endif</span>
            <strong>{{ $tile['name'] }}</strong>
            <small>{{ trans_choice('messages.products', $tile['count'], ['count' => $tile['count']]) }}</small>
          </a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Weekly deals ===== --}}
  @if($sections['deals'] && $deals->isNotEmpty())
    <section class="gr-section" style="padding-top: 4px">
      <div class="gr-deals">
        <div class="gr-head">
          <h2 class="gr-h2"><x-store.icon name="percent" class="gr-h2-deal" />{{ $opts['deals_title'] }}</h2>
          <div class="flex items-center gap-3">
            <span class="gr-timer hidden sm:inline-flex"><x-store.icon name="timer" />{{ __('messages.EndsSunday') }}</span>
            <a href="{{ route('store.shop', ['deals' => 1]) }}" class="gr-link">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
          </div>
        </div>
        <div class="gr-grid" style="--gr-cols: {{ max(3, min(6, $opts['limits']['deals'])) }}">
          @foreach($deals as $p)@include('store.grocery.partials.product-card', ['p' => $p, 'currency' => $currency])@endforeach
        </div>
      </div>
    </section>
  @endif

  {{-- ===== Buy again (signed-in customers) ===== --}}
  @if($sections['buy_again'] && $buyAgain->isNotEmpty())
    <section class="gr-section" style="padding-top: 4px">
      <div class="gr-head">
        <h2 class="gr-h2"><x-store.icon name="refresh" />{{ __('messages.BuyAgain') }}</h2>
        <a href="{{ route('account.orders') }}" class="gr-link">{{ __('messages.Orders') }}<x-store.icon name="arrow-right" /></a>
      </div>
      <div class="gr-grid" style="--gr-cols: 6">
        @foreach($buyAgain as $p)@include('store.grocery.partials.product-card', ['p' => $p, 'currency' => $currency])@endforeach
      </div>
    </section>
  @endif

  {{-- ===== Popular ===== --}}
  @if($sections['popular'] && $popular->isNotEmpty())
    <section class="gr-section" style="padding-top: 4px">
      <div class="gr-head">
        <h2 class="gr-h2"><x-store.icon name="star-fill" />{{ $opts['popular_title'] }}</h2>
        <a href="{{ route('store.shop') }}" class="gr-link">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
      </div>
      <div class="gr-grid" style="--gr-cols: {{ max(3, min(6, $opts['limits']['popular'])) }}">
        @foreach($popular as $p)@include('store.grocery.partials.product-card', ['p' => $p, 'currency' => $currency])@endforeach
      </div>
    </section>
  @endif

  {{-- ===== Promo tiles ===== --}}
  @if($sections['tiles'] && $promoTiles->isNotEmpty())
    <section class="gr-section" style="padding-top: 4px">
      <div class="gr-promos">
        @foreach($promoTiles as $t)
          <a href="{{ $t['href'] }}" class="gr-promo" style="background: {{ $t['colors']['bg'] }}; color: {{ $t['colors']['fg'] }}">
            <div><small>{{ $t['subtitle'] }}</small><h3>{{ $t['title'] }}</h3><span class="gr-tile-cta">{{ $t['button_text'] ?: __('messages.ShopNow') }}<x-store.icon name="arrow-right" /></span></div>
            @if($t['image_url'])<img src="{{ $t['image_url'] }}" alt="{{ $t['title'] }}" loading="lazy">@endif
          </a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Aisles ===== --}}
  @if($sections['aisles'] && $aisles->isNotEmpty())
    <section class="gr-section" style="padding-top: 4px">
      @foreach($aisles as $aisle)
        <div class="gr-aisle">
          <div class="gr-aisle-head">
            <h3>@if($aisle['image_url'])<img src="{{ $aisle['image_url'] }}" alt="" loading="lazy">@else<x-store.icon :name="$aisle['icon']" />@endif{{ $aisle['name'] }}</h3>
            <a href="{{ $aisle['url'] }}" class="gr-link">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
          </div>
          <div class="gr-grid" style="--gr-cols: {{ max(3, min(6, $opts['limits']['aisle_products'])) }}">
            @foreach($aisle['products'] as $p)@include('store.grocery.partials.product-card', ['p' => $p, 'currency' => $currency])@endforeach
          </div>
        </div>
      @endforeach
    </section>
  @endif

  {{-- ===== Trust ===== --}}
  @if($sections['trust'] && count($opts['trust']))
    <section class="gr-section" style="padding-top: 4px">
      <div class="gr-trust" style="--gr-trust-cols: {{ max(1, min(6, count($opts['trust']))) }}">
        @foreach($opts['trust'] as $item)
          <div class="gr-trust-item"><span class="gr-trust-icon"><x-store.icon :name="$item['icon'] ?: 'check-circle'" /></span><div><strong>{{ $item['title'] }}</strong>@if(!empty($item['subtitle']))<span>{{ $item['subtitle'] }}</span>@endif</div></div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Newsletter ===== --}}
  @if($sections['newsletter'])
    <section class="gr-section" style="padding-top: 4px">
      <div class="gr-newsletter">
        <span class="gr-newsletter-icon"><x-store.icon name="mail" /></span>
        <div><h3>{{ $opts['newsletter_title'] }}</h3><p>{{ $opts['newsletter_subtitle'] }}</p></div>
        <form id="newsletterForm">@csrf<input name="email" type="email" id="newsletterEmail" placeholder="{{ __('messages.NewsletterEmailPlaceholder') }}" required><button id="newsletterBtn" class="gr-btn gr-btn-orange" type="submit">{{ $nlBtn }}</button></form>
        <div id="newsletterMsg" class="gr-newsletter-note">{{ __('messages.NoSpam') }}</div>
      </div>
    </section>
  @endif

  {{-- ===== Brands ===== --}}
  @if($sections['brands'] && $brands->isNotEmpty())
    <section class="gr-section" style="padding-top: 4px">
      <div class="gr-head"><h2 class="gr-h2">{{ __('messages.ShopByBrand') }}</h2></div>
      <div class="gr-brands">
        @foreach($brands as $b)
          <a href="{{ route('store.shop', ['brand' => $b->id]) }}" class="gr-brand" title="{{ $b->name }}">@if($b->image && $b->image !== 'no-image.png')<img src="{{ asset('images/brands/'.$b->image) }}" alt="{{ $b->name }}" loading="lazy" onerror="this.remove()">@endif<span>{{ $b->name }}</span></a>
        @endforeach
      </div>
    </section>
  @endif

</div>

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])
@endsection
