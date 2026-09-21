{{-- Default theme: automatic homepage sections (rendered once, right before
     the newsletter block or at the end). Data comes from
     StoreFrontController@index as $auto. --}}
@php
  $auto = $auto ?? [];
  $currency = $currency ?? store_currency()['symbol'];
  $grids = [
    'best_sellers' => ['title' => __('messages.BestSellers'), 'kicker' => __('messages.Handpicked'), 'url' => route('store.shop')],
    'new_arrivals' => ['title' => __('messages.NewArrivals'), 'kicker' => __('messages.JustListed'), 'url' => route('store.shop', ['sort' => 'latest'])],
    'on_sale' => ['title' => __('messages.OnSale'), 'kicker' => __('messages.Deals'), 'url' => route('store.shop', ['deals' => 1])],
  ];
@endphp

@foreach($grids as $key => $meta)
  @php $prods = $auto[$key] ?? collect(); @endphp
  @if($prods->count())
    <section class="df-section" style="padding-top:0">
      <div class="container">
        <div class="df-head">
          <div>
            <span class="section-kicker">{{ $meta['kicker'] }}</span>
            <h2 class="section-title mt-1">{{ $meta['title'] }}</h2>
          </div>
          <a class="df-link" href="{{ $meta['url'] }}">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
        </div>
        <div class="df-grid">
          @foreach($prods as $p)
            @include('store.partials.product-card', ['p' => $p, 'currency' => $currency])
          @endforeach
        </div>
      </div>
    </section>
  @endif

  {{-- Promo tiles between the first and second product grid --}}
  @if($key === 'best_sellers' || ($key === 'new_arrivals' && ($auto['best_sellers'] ?? collect())->isEmpty()))
    @if(($auto['promos'] ?? collect())->count())
      <section class="df-section" style="padding-top:0">
        <div class="container">
          <div class="df-promo">
            @foreach($auto['promos'] as $promo)
              <a href="{{ $promo['url'] }}" style="background-image:url('{{ $promo['image'] }}')">
                <div class="df-promo-copy">
                  <small>{{ $promo['kicker'] }}</small>
                  <h3>{{ $promo['title'] }}</h3>
                  <p>{{ $promo['subtitle'] }}</p>
                  <span class="btn btn-primary btn-sm">{{ __('messages.ShopNow') }}<x-store.icon name="arrow-right" class="w-4 h-4" /></span>
                </div>
              </a>
            @endforeach
          </div>
        </div>
      </section>
    @endif
  @endif
@endforeach

@if(($auto['testimonials'] ?? collect())->count())
  <section class="df-section" style="padding-top:0">
    <div class="container">
      <div class="df-head">
        <div>
          <span class="section-kicker">{{ __('messages.Reviews') }}</span>
          <h2 class="section-title mt-1">{{ __('messages.WhatCustomersSay') }}</h2>
        </div>
      </div>
      <div class="df-reviews">
        @foreach($auto['testimonials'] as $r)
          @php $who = trim((string) ($r->reviewer_name ?: __('messages.Customer'))); @endphp
          <div class="df-review">
            <div class="df-stars"><span class="df-stars-icons">@for($k = 1; $k <= 5; $k++)<x-store.icon name="star-fill" class="{{ $k <= (int) $r->rating ? '' : 'is-empty' }}" />@endfor</span></div>
            <p>“{{ \Illuminate\Support\Str::limit($r->comment, 200) }}”</p>
            <div class="df-review-who">
              <span class="df-avatar">{{ mb_strtoupper(mb_substr($who, 0, 1)) }}</span>
              <div>
                <strong>{{ $who }}</strong>
                <small>{{ __('messages.VerifiedBuyer') }}@if($r->product) · <a href="{{ route('store.product.show', $r->product->id) }}" class="text-accent-500">{{ \Illuminate\Support\Str::limit($r->product->name, 34) }}</a>@endif</small>
              </div>
            </div>
          </div>
        @endforeach
      </div>
    </div>
  </section>
@endif

@if(($auto['brands'] ?? collect())->count())
  <section class="df-section" style="padding-top:0">
    <div class="container">
      <div class="df-head">
        <div>
          <span class="section-kicker">{{ __('messages.Brands') }}</span>
          <h2 class="section-title mt-1">{{ __('messages.ShopByBrand') }}</h2>
        </div>
      </div>
      <div class="df-brands">
        @foreach($auto['brands'] as $b)
          <a href="{{ route('store.shop', ['brand' => $b->id]) }}" class="df-brand" title="{{ $b->name }}">
            @if($b->image && $b->image !== 'no-image.png')
              <img src="{{ asset('images/brands/'.$b->image) }}" alt="{{ $b->name }}" loading="lazy" onerror="this.remove()">
            @endif
            <span>{{ $b->name }}</span>
          </a>
        @endforeach
      </div>
    </div>
  </section>
@endif
