@extends('store.realestate.layout')

@section('title', ($s->seo_meta_title ?? $s->store_name ?? 'Real Estate'))

@section('content')
@php
  use Illuminate\Support\Str;
  $reImg = fn ($p) => $p ? (Str::startsWith($p, ['http://', 'https://', '/']) ? $p : asset($p)) : null;
  $hero = $reImg($s->hero_image_path) ?: 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=1800&q=80&auto=format&fit=crop';
  $ctaImg = 'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=1600&q=80&auto=format&fit=crop';
  $catIcons = ['apartment' => 'grid', 'house' => 'home', 'villa' => 'sun-icon', 'office' => 'monitor', 'commercial-property' => 'bag', 'land' => 'trees'];
  $purpose = request('purpose', '');
@endphp

{{-- HERO --}}
<section style="position:relative;color:#fff;overflow:hidden;background:var(--re-navy);min-height:600px;display:flex;align-items:center">
  <img src="{{ $hero }}" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.55" fetchpriority="high">
  <div style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(11,26,43,.92) 0%,rgba(11,26,43,.55) 55%,rgba(11,26,43,.25) 100%)"></div>
  <div class="re-container" style="position:relative;z-index:1;padding-top:70px;padding-bottom:90px;width:100%">
    <span class="re-badge" style="background:rgba(255,255,255,.12);color:#fff;backdrop-filter:blur(6px);padding:8px 14px;font-size:12px"><x-store.icon name="star-fill" class="w-3 h-3" style="color:var(--re-gold)" />{{ __('messages.FindYourDreamHome') }}</span>
    <h1 style="font-size:clamp(36px,5vw,62px);line-height:1.06;font-weight:600;margin:18px 0 14px;max-width:760px">
      {{ $s->localizedText('hero_title') ?: __('messages.RealEstateHeroTitle') }}
    </h1>
    <p style="font-size:17px;color:rgba(255,255,255,.88);max-width:560px;margin:0 0 34px;line-height:1.6">
      {{ $s->localizedText('hero_subtitle') ?: __('messages.RealEstateHeroSubtitle') }}
    </p>

    {{-- Search card --}}
    <form method="GET" action="{{ route('store.realestate.listings') }}" id="reHeroSearch"
          style="background:#fff;border-radius:18px;padding:10px;max-width:960px;box-shadow:var(--re-shadow-lg);color:var(--re-ink)">
      <div style="display:flex;gap:4px;padding:2px 6px 8px">
        @foreach(['' => __('messages.All'), 'sale' => __('messages.Buy'), 'rent' => __('messages.Rent')] as $val => $label)
          <button type="button" class="re-tab {{ $purpose === $val ? 'is-active' : '' }}" data-val="{{ $val }}"
                  style="border:0;background:transparent;font:inherit;font-weight:700;font-size:13px;padding:8px 14px;border-radius:10px;cursor:pointer;color:var(--re-muted)">{{ $label }}</button>
        @endforeach
        <input type="hidden" name="purpose" id="reHeroPurpose" value="{{ $purpose }}">
      </div>
      <div style="display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr auto;gap:8px" class="re-hero-grid">
        <div style="position:relative">
          <x-store.icon name="map-pin" style="position:absolute;inset-inline-start:13px;top:14px;width:16px;height:16px;color:var(--re-gold)" />
          <input class="re-input" style="padding-inline-start:36px;border-color:transparent;background:var(--re-bg)" type="text" name="location" placeholder="{{ __('messages.CityOrRegion') }}">
        </div>
        <select class="re-select" name="category" style="border-color:transparent;background-color:var(--re-bg)">
          <option value="">{{ __('messages.AllTypes') }}</option>
          @foreach($categories as $c)
            <option value="{{ $c->slug }}">{{ $c->name }}</option>
          @endforeach
        </select>
        <select class="re-select" name="bedrooms" style="border-color:transparent;background-color:var(--re-bg)">
          <option value="">{{ __('messages.Bedrooms') }}</option>
          @foreach([1,2,3,4,5] as $n)<option value="{{ $n }}">{{ $n }}+ {{ __('messages.Beds') }}</option>@endforeach
        </select>
        <select class="re-select" name="max_price" style="border-color:transparent;background-color:var(--re-bg)">
          <option value="">{{ __('messages.MaxPrice') }}</option>
          @foreach([1500, 3000, 5000, 150000, 300000, 500000, 750000, 1000000, 2000000, 5000000] as $mp)
            <option value="{{ $mp }}">{{ $currency }}{{ number_format($mp) }}</option>
          @endforeach
        </select>
        <button class="re-btn re-btn-primary" type="submit" style="padding-inline:22px"><x-store.icon name="search" />{{ __('messages.Search') }}</button>
      </div>
    </form>
    <style>
      .re-tab.is-active{background:rgba(var(--re-accent-rgb),.1);color:var(--re-accent) !important}
      @media(max-width:860px){.re-hero-grid{grid-template-columns:1fr 1fr !important}.re-hero-grid > div:first-child{grid-column:1/-1}.re-hero-grid .re-btn{grid-column:1/-1}}
    </style>
    <script>
      (function(){var tabs=document.querySelectorAll('#reHeroSearch .re-tab'),h=document.getElementById('reHeroPurpose');tabs.forEach(function(t){t.addEventListener('click',function(){tabs.forEach(function(x){x.classList.remove('is-active')});t.classList.add('is-active');h.value=t.getAttribute('data-val');});});})();
    </script>
  </div>
</section>

{{-- STATS --}}
@if(!empty($stats))
<section style="margin-top:-38px;position:relative;z-index:2">
  <div class="re-container">
    <div class="re-stats">
      <div class="re-stat"><span class="ic"><x-store.icon name="home" /></span><div><b>{{ number_format($stats['properties']) }}+</b><span>{{ __('messages.PropertiesListed') }}</span></div></div>
      <div class="re-stat"><span class="ic"><x-store.icon name="map-pin" /></span><div><b>{{ number_format($stats['cities']) }}</b><span>{{ __('messages.CitiesCovered') }}</span></div></div>
      <div class="re-stat"><span class="ic"><x-store.icon name="user" /></span><div><b>{{ number_format($stats['clients']) }}+</b><span>{{ __('messages.HappyClients') }}</span></div></div>
      <div class="re-stat"><span class="ic"><x-store.icon name="award" /></span><div><b>{{ $stats['years'] }}+</b><span>{{ __('messages.YearsExperience') }}</span></div></div>
    </div>
  </div>
</section>
@endif

{{-- CATEGORIES --}}
@if($categories->count())
<section class="re-section">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.Categories') }}</span><h2 class="re-title">{{ __('messages.PropertyCategories') }}</h2></div>
      <a class="re-btn re-btn-ghost" href="{{ route('store.realestate.listings') }}">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
    </div>
    <div class="re-cat-grid">
      @foreach($categories as $c)
        <a class="re-cat" href="{{ route('store.realestate.listings', ['category' => $c->slug]) }}">
          @if(!empty($c->cover_image))<img src="{{ $reImg($c->cover_image) }}" alt="{{ $c->name }}" loading="lazy">@endif
          <span class="ic"><x-store.icon :name="$catIcons[$c->slug] ?? 'home'" /></span>
          <div class="nm">{{ $c->name }}</div>
          <div class="ct">{{ $c->properties_count }} {{ __('messages.Listings') }}</div>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- FEATURED --}}
@if($featured->count())
<section class="re-section" style="padding-top:0">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.Handpicked') }}</span><h2 class="re-title">{{ __('messages.FeaturedProperties') }}</h2><p>{{ __('messages.FeaturedPropertiesIntro') }}</p></div>
      <a class="re-btn re-btn-ghost" href="{{ route('store.realestate.listings') }}">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
    </div>
    <div class="re-grid">
      @foreach($featured as $property)
        @include('store.realestate.partials.card', ['property' => $property, 'currency' => $currency])
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- LOCATIONS --}}
@if($locations->count())
<section class="re-section" style="padding-top:0">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.Explore') }}</span><h2 class="re-title">{{ __('messages.FeaturedLocations') }}</h2></div>
    </div>
    <div class="re-loc-grid">
      @foreach($locations as $loc)
        <a class="re-loc" href="{{ route('store.realestate.listings', ['location' => $loc->city]) }}">
          @if(!empty($loc->image))<img src="{{ $reImg($loc->image) }}" alt="{{ $loc->city }}" loading="lazy">@endif
          <span class="city">{{ $loc->city }}</span>
          <span class="ct"><x-store.icon name="home" />{{ $loc->total }} {{ __('messages.Properties') }}</span>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- WHY US --}}
<section class="re-section" style="padding-top:0">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.WhyChooseUs') }}</span><h2 class="re-title">{{ __('messages.WhyChooseUsTitle') }}</h2></div>
    </div>
    <div class="re-why">
      <div class="re-why-item"><span class="ic"><x-store.icon name="shield-check" /></span><h3>{{ __('messages.VerifiedListings') }}</h3><p>{{ __('messages.VerifiedListingsText') }}</p></div>
      <div class="re-why-item"><span class="ic"><x-store.icon name="user" /></span><h3>{{ __('messages.TrustedAgents') }}</h3><p>{{ __('messages.TrustedAgentsText') }}</p></div>
      <div class="re-why-item"><span class="ic"><x-store.icon name="tag" /></span><h3>{{ __('messages.BestPrices') }}</h3><p>{{ __('messages.BestPricesText') }}</p></div>
    </div>
  </div>
</section>

{{-- LATEST --}}
@if($latest->count())
<section class="re-section" style="padding-top:0">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.JustListed') }}</span><h2 class="re-title">{{ __('messages.LatestProperties') }}</h2></div>
      <a class="re-btn re-btn-ghost" href="{{ route('store.realestate.listings', ['sort' => 'latest']) }}">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
    </div>
    <div class="re-grid cols-4">
      @foreach($latest as $property)
        @include('store.realestate.partials.card', ['property' => $property, 'currency' => $currency])
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- CTA --}}
<section class="re-section" style="padding-top:0">
  <div class="re-container">
    <div class="re-cta">
      <img src="{{ $ctaImg }}" alt="" loading="lazy">
      <div>
        <span class="re-kicker" style="color:var(--re-gold)">{{ __('messages.Sellers') }}</span>
        <h2>{{ __('messages.ListYourPropertyTitle') }}</h2>
        <p>{{ __('messages.ListYourPropertyText') }}</p>
      </div>
      <div class="re-cta-actions">
        <a class="re-btn re-btn-gold" href="{{ route('store.contact') }}"><x-store.icon name="send" />{{ __('messages.ListYourProperty') }}</a>
        <a class="re-btn re-btn-light" href="{{ route('store.realestate.listings') }}">{{ __('messages.BrowseProperties') }}</a>
      </div>
    </div>
  </div>
</section>
@endsection
