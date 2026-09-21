@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  $nlBtn = __('messages.Subscribe');
  $trustCols = max(1, min(6, count($opts['trust'])));
  $catCols = max(4, min(9, $categoryTiles->count() + 1));
@endphp

<div class="ty-container">

  {{-- ===== Hero ===== --}}
  @if($slides->isNotEmpty())
    <section class="ty-hero"
             x-data="{ i: 0, n: {{ $slides->count() }}, t: null,
                       go(k) { this.i = (k + this.n) % this.n; this.restart(); },
                       restart() { clearInterval(this.t); if (this.n > 1) this.t = setInterval(() => { this.i = (this.i + 1) % this.n; }, 7000); } }"
             x-init="restart()" @mouseenter="clearInterval(t)" @mouseleave="restart()">
      {{-- playful decorations --}}
      <span class="ty-deco" style="top: 26px; inset-inline-start: 46%; width: 22px; height: 22px; color: var(--ty-yellow)"><x-store.icon name="star-fill" /></span>
      <span class="ty-deco" style="bottom: 60px; inset-inline-start: 44%; width: 16px; height: 16px; color: var(--ty-pink)"><x-store.icon name="heart" /></span>
      <span class="ty-deco" style="top: 40%; inset-inline-end: 14px; width: 18px; height: 18px; color: var(--ty-accent)"><x-store.icon name="sparkles" /></span>
      @foreach($slides as $k => $slide)
        <div class="ty-slide {{ $k === 0 ? 'is-active' : '' }}" :class="{ 'is-active': i === {{ $k }} }">
          <div>
            @if(!empty($slide['kicker']))<div class="ty-slide-kicker">{{ $slide['kicker'] }} <x-store.icon name="heart" /></div>@endif
            <h1 class="ty-slide-title">{{ $slide['title'] ?? $s->localizedText('hero_title') }}@if(!empty($slide['title_accent']))<em>{{ $slide['title_accent'] }}</em>@endif</h1>
            @if(!empty($slide['subtitle']))<p class="ty-slide-sub">{{ $slide['subtitle'] }}</p>@endif
            <div class="ty-slide-actions">
              @if(!empty($slide['primary_text']))
                <a href="{{ $slide['primary_href'] }}" class="ty-btn ty-btn-primary">{{ $slide['primary_text'] }}<x-store.icon name="arrow-right" /></a>
              @endif
              @if(!empty($slide['secondary_text']))
                <a href="{{ $slide['secondary_href'] }}" class="ty-btn ty-btn-outline">{{ $slide['secondary_text'] }}</a>
              @endif
            </div>
          </div>
          <div class="ty-slide-media">
            @if(!empty($slide['image_url']))
              <img src="{{ $slide['image_url'] }}" alt="{{ $slide['title'] ?? '' }}" loading="{{ $k === 0 ? 'eager' : 'lazy' }}" fetchpriority="{{ $k === 0 ? 'high' : 'auto' }}">
            @endif
            @if(!empty($slide['badge']))
              @php $badgeParts = preg_split('/\s*(\d+%)\s*/', $slide['badge'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY); @endphp
              <div class="ty-blob">
                @foreach($badgeParts as $part)
                  @if(preg_match('/^\d+%$/', $part))<b>{{ $part }}</b>@else<small>{{ $part }}</small>@endif
                @endforeach
              </div>
            @endif
          </div>
        </div>
      @endforeach
      @if($slides->count() > 1)
        <div class="ty-hero-dots" role="tablist">
          @foreach($slides as $k => $slide)
            <button type="button" :class="{ 'is-active': i === {{ $k }} }" class="{{ $k === 0 ? 'is-active' : '' }}" @click="go({{ $k }})" aria-label="Slide {{ $k + 1 }}"></button>
          @endforeach
        </div>
      @endif
    </section>
  @endif

  {{-- ===== Category circles ===== --}}
  @if($sections['categories'] && $categoryTiles->isNotEmpty())
    <section class="ty-section">
      <div class="ty-cats" style="--ty-cat-cols: {{ $catCols }}">
        @foreach($categoryTiles as $tile)
          <a href="{{ $tile['url'] }}" class="ty-cat">
            <span class="ty-cat-circle" style="background: {{ $tile['pastel']['bg'] }}; color: {{ $tile['pastel']['fg'] }}"><x-store.icon :name="$tile['icon']" /></span>
            <strong>{{ $tile['name'] }}</strong>
          </a>
        @endforeach
        <a href="{{ route('store.shop') }}" class="ty-cat">
          <span class="ty-cat-circle" style="background: rgb(var(--color-bg-muted)); color: var(--ty-ink)"><x-store.icon name="grid" /></span>
          <strong>{{ __('messages.AllCategories') }}</strong>
        </a>
      </div>
    </section>
  @endif

  {{-- ===== Promo tiles ===== --}}
  @if($sections['tiles'] && $tiles->isNotEmpty())
    <section class="ty-section" style="padding-top: 4px">
      <div class="ty-tiles">
        @foreach($tiles as $t)
          <a href="{{ $t['href'] }}" class="ty-tile" style="background: {{ $t['pastel']['bg'] }}">
            <div>
              <h3 style="color: {{ $t['pastel']['fg'] }}">{{ $t['title'] }}</h3>
              @if(!empty($t['subtitle']))<p>{{ $t['subtitle'] }}</p>@endif
              @if(!empty($t['button_text']))<span class="ty-tile-cta">{{ $t['button_text'] }}<x-store.icon name="arrow-right" /></span>@endif
            </div>
            @if($t['image_url'])<img src="{{ $t['image_url'] }}" alt="{{ $t['title'] }}" loading="lazy">@endif
          </a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Popular picks ===== --}}
  @if($sections['popular'] && $popular->isNotEmpty())
    <section class="ty-section">
      <div class="ty-section-head">
        <h2 class="ty-h2">{{ $opts['popular_title'] }} <x-store.icon name="heart" /></h2>
        <a href="{{ route('store.shop') }}" class="ty-link-all">{{ __('messages.ViewAllProducts') }}<x-store.icon name="arrow-right" /></a>
      </div>
      <div class="ty-grid" style="--ty-cols: {{ max(3, min(6, $opts['limits']['popular'])) }}">
        @foreach($popular as $p)
          @include('store.toys.partials.product-card', ['p' => $p, 'currency' => $currency])
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Trust band ===== --}}
  @if($sections['trust'] && count($opts['trust']))
    <section class="ty-section" style="padding-top: 6px">
      <div class="ty-trust" style="--ty-trust-cols: {{ $trustCols }}">
        @foreach($opts['trust'] as $item)
          <div class="ty-trust-item">
            <span class="ty-trust-icon"><x-store.icon :name="$item['icon'] ?: 'check-circle'" /></span>
            <div>
              <strong>{{ $item['title'] }}</strong>
              @if(!empty($item['subtitle']))<span>{{ $item['subtitle'] }}</span>@endif
            </div>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== New arrivals ===== --}}
  @if($sections['new_arrivals'] && $newArrivals->isNotEmpty())
    <section class="ty-section">
      <div class="ty-section-head">
        <h2 class="ty-h2">{{ __('messages.NewArrivals') }} <x-store.icon name="sparkles" /></h2>
        <a href="{{ route('store.shop', ['sort' => 'latest']) }}" class="ty-link-all">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
      </div>
      <div class="ty-grid" style="--ty-cols: {{ max(3, min(6, $opts['limits']['new_arrivals'])) }}">
        @foreach($newArrivals as $p)
          @include('store.toys.partials.product-card', ['p' => $p, 'currency' => $currency])
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Deals ===== --}}
  @if($sections['deals'] && $deals->isNotEmpty())
    <section class="ty-section">
      <div class="ty-section-head">
        <h2 class="ty-h2">{{ __('messages.Deals') }} <x-store.icon name="star-fill" /></h2>
        <a href="{{ route('store.shop', ['deals' => 1]) }}" class="ty-link-all">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
      </div>
      <div class="ty-grid" style="--ty-cols: {{ max(3, min(6, $opts['limits']['deals'])) }}">
        @foreach($deals as $p)
          @include('store.toys.partials.product-card', ['p' => $p, 'currency' => $currency])
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Testimonials (off by default) ===== --}}
  @if($sections['testimonials'] && $testimonials->isNotEmpty())
    <section class="ty-section">
      <div class="ty-section-head"><h2 class="ty-h2">{{ __('messages.WhatParentsSay') }} <x-store.icon name="smile" /></h2></div>
      <div class="ty-reviews">
        @foreach($testimonials as $r)
          @php $who = trim((string) ($r->reviewer_name ?: __('messages.Customer'))); @endphp
          <div class="ty-review">
            <div class="ty-stars"><span class="ty-stars-icons">@for($k = 1; $k <= 5; $k++)<x-store.icon name="star-fill" class="{{ $k <= (int) $r->rating ? '' : 'is-empty' }}" />@endfor</span></div>
            <p>“{{ \Illuminate\Support\Str::limit($r->comment, 200) }}”</p>
            <div class="ty-review-who">
              <span class="ty-avatar">{{ mb_strtoupper(mb_substr($who, 0, 1)) }}</span>
              <div><strong>{{ $who }}</strong><small>{{ __('messages.VerifiedBuyer') }}</small></div>
            </div>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Newsletter ===== --}}
  @if($sections['newsletter'])
    <section class="ty-section">
      <div class="ty-newsletter">
        <span class="ty-deco" style="top: 14px; inset-inline-start: 22px; width: 18px; height: 18px; color: var(--ty-pink)"><x-store.icon name="star-fill" /></span>
        <span class="ty-deco" style="bottom: 16px; inset-inline-end: 26px; width: 20px; height: 20px; color: var(--ty-accent)"><x-store.icon name="heart" /></span>
        <span class="ty-deco" style="top: 18px; inset-inline-end: 60px; width: 16px; height: 16px; color: var(--ty-yellow)"><x-store.icon name="sparkles" /></span>
        <span class="ty-newsletter-icon"><x-store.icon name="mail" /></span>
        <div>
          <h3>{{ $opts['newsletter_title'] }}</h3>
          <p>{{ $opts['newsletter_subtitle'] }}</p>
        </div>
        <form id="newsletterForm">
          @csrf
          <input name="email" type="email" id="newsletterEmail" placeholder="{{ __('messages.NewsletterEmailPlaceholder') }}" required>
          <button id="newsletterBtn" class="ty-btn ty-btn-primary" type="submit">{{ $nlBtn }}</button>
        </form>
        <div id="newsletterMsg" class="ty-newsletter-note">{{ __('messages.NoSpam') }}</div>
      </div>
    </section>
  @endif

</div>

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])
@endsection
