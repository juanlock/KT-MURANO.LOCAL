@extends('layouts.store')

@section('content')

@php
  /** @var \App\Models\StoreSetting $s */
  $currency = store_currency()['symbol'];
  $nlBtn    = __('messages.Subscribe');
  /** @var \Illuminate\Support\Collection $banners */
  $byPos = collect($banners ?? [])->groupBy('position');
  $printedCenter = false;

  $auto = $auto ?? [];
  $autoPrinted = false;
  $renderBanners = function($list, $wrapClass = 'block rounded-lg overflow-hidden shadow-sm hover:shadow-md transition-shadow') {
      foreach ($list ?? collect() as $b) {
          $src  = $b->image_url ?? ($b->image ? asset($b->image) : asset('images/brands/no-image.png'));
          $href = $b->link ?: route('store.shop');
          echo '<a href="'.e($href).'" class="'.e($wrapClass).'"><img src="'.e($src).'" class="w-full h-auto object-cover" alt="'.e($b->title ?? __('messages.Banner')).'"></a>';
      }
  };
@endphp

{{-- ===== TOP ===== --}}
@if(($byPos['top_left'] ?? collect())->count() || ($byPos['top_right'] ?? collect())->count())
  <section class="py-6">
    <div class="container">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div>{!! $renderBanners($byPos['top_left'] ?? collect()) !!}</div>
        <div>{!! $renderBanners($byPos['top_right'] ?? collect()) !!}</div>
      </div>
    </div>
  </section>
@endif

@forelse($blocks ?? [] as $block)
  @switch($block['type'])

    @case('hero')
      @php
        $heroImg = $block['image'] ?? $s->hero_image_path;
        $heroUrl = 'https://picsum.photos/seed/hero-store/960/520';
        if (!empty($heroImg) && is_string($heroImg) && !\Illuminate\Support\Str::startsWith($heroImg, ['http://', 'https://']) && file_exists(public_path($heroImg))) {
            $heroUrl = asset($heroImg);
        } elseif (file_exists(public_path('store_files/hero_image.jpg'))) {
            $heroUrl = asset('store_files/hero_image.jpg');
        }
      @endphp
      <section class="py-14 lg:py-20 relative overflow-hidden border-b border-line-subtle"
               style="background:
                 radial-gradient(640px 640px at 85% -20%, rgb(var(--color-accent-500) / .18) 0%, transparent 65%),
                 radial-gradient(900px 280px at 10% 60%, rgb(var(--color-accent-500) / .06) 0%, transparent 55%);">
        <div class="container relative">
          <div class="grid lg:grid-cols-2 gap-10 lg:gap-14 items-center">
            <div class="flex flex-col items-start gap-6">
              <span class="hero-chip">
                <span class="hero-chip-dot"></span>
                {{ __('messages.New') }}
              </span>
              <h1 class="hero-title">
                {{ $block['title'] ?? $s->localizedText('hero_title') }}
              </h1>
              <p class="text-base lg:text-lg text-fg-secondary leading-relaxed max-w-xl">
                {{ $block['subtitle'] ?? $s->localizedText('hero_subtitle') }}
              </p>
              <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('store.shop') }}" class="btn btn-primary btn-lg">
                  {{ __('messages.ShopNow') }}
                  <x-store.icon name="arrow-right" class="w-5 h-5" />
                </a>
                <a href="{{ route('store.shop', ['deals' => 1]) }}" class="btn btn-secondary btn-lg">
                  {{ __('messages.Deals') }}
                </a>
              </div>
              @if(!empty($auto['stats']['products']))
                <div class="df-hero-stats">
                  <div class="df-hero-stat"><b>{{ number_format($auto['stats']['products']) }}+</b><span>{{ __('messages.Products') }}</span></div>
                  @if(!empty($auto['stats']['reviews']))
                    <div class="df-hero-stat"><b>{{ number_format($auto['stats']['rating'], 1) }}/5</b><span>{{ number_format($auto['stats']['reviews']) }} {{ __('messages.Reviews') }}</span></div>
                  @endif
                  <div class="df-hero-stat"><b>{{ __('messages.FastShipping') }}</b><span>{{ __('messages.TrustShippingText') }}</span></div>
                </div>
              @endif
            </div>
            <div class="relative hidden md:block lg:px-6">
              <div class="hero-media">
                <img class="w-full h-auto object-cover max-h-[440px]" src="{{ $heroUrl }}" alt="Hero">
              </div>
              <div class="hero-float -bottom-4 -start-3 lg:-start-6">
                <span class="trust-icon !w-9 !h-9 !rounded-lg" style="background: rgb(var(--color-success) / .14); border-color: rgb(var(--color-success) / .3); color: rgb(var(--color-success));">
                  <x-store.icon name="truck" class="w-4 h-4" />
                </span>
                <span class="text-sm font-semibold text-fg-primary">{{ __('messages.FastShipping') }}</span>
              </div>
              <div class="hero-float top-5 -end-2 lg:-end-4">
                <span class="trust-icon !w-9 !h-9 !rounded-lg">
                  <x-store.icon name="shield-check" class="w-4 h-4" />
                </span>
                <span class="text-sm font-semibold text-fg-primary">{{ __('messages.SecurePayment') }}</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      {{-- ===== Categories (photo tiles) + trust strip ===== --}}
      @php $tiles = $auto['tiles'] ?? collect(); @endphp
      @if($tiles->count())
        <section class="df-section" style="padding-bottom:0">
          <div class="container">
            <div class="df-head">
              <div>
                <span class="section-kicker">{{ __('messages.Categories') }}</span>
                <h2 class="section-title mt-1">{{ __('messages.ShopByCategory') }}</h2>
              </div>
              <a class="df-link" href="{{ route('store.shop') }}">{{ __('messages.ViewAllCategories') }}<x-store.icon name="arrow-right" /></a>
            </div>
            <div class="df-cats" style="--df-cat-cols: {{ max(4, min(6, $tiles->count())) }}">
              @foreach($tiles->take(12) as $tile)
                <a href="{{ $tile['url'] }}" class="df-cat">
                  <span class="df-cat-media">
                    @if($tile['image_url'])<img src="{{ $tile['image_url'] }}" alt="{{ $tile['name'] }}" loading="lazy">@else{{ Str::upper(Str::substr($tile['name'], 0, 1)) }}@endif
                  </span>
                  <strong>{{ $tile['name'] }}</strong>
                  <small>{{ trans_choice('messages.products', $tile['count'], ['count' => $tile['count']]) }}</small>
                </a>
              @endforeach
            </div>
          </div>
        </section>
      @endif
      @if(!empty($auto['trust']))
        <section class="df-section" style="padding-bottom:0">
          <div class="container">
            <div class="df-trust">
              @foreach($auto['trust'] as $t)
                <div class="df-trust-item">
                  <span class="df-trust-icon"><x-store.icon :name="$t['icon']" /></span>
                  <div><strong>{{ $t['title'] }}</strong><span>{{ $t['subtitle'] }}</span></div>
                </div>
              @endforeach
            </div>
          </div>
        </section>
      @endif

      {{-- ===== CENTER ===== --}}
      @if(!$printedCenter && ( ($byPos['center_left'] ?? collect())->count() || ($byPos['center_right'] ?? collect())->count() ))
        <section class="py-6">
          <div class="container">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <div>{!! $renderBanners($byPos['center_left'] ?? collect()) !!}</div>
              <div>{!! $renderBanners($byPos['center_right'] ?? collect()) !!}</div>
            </div>
          </div>
        </section>
        @php $printedCenter = true; @endphp
      @endif
      @break

    @case('collection')
      @php
        $col   = $block['collection'];
        $prods = $block['products'] ?? collect();
        $title = $block['title'] ?? ($col->title ?? $col->name ?? __('messages.Collection'));
      @endphp

      @if($prods->count())
      <section class="py-12 lg:py-16">
        <div class="container">
          <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-8">
            <div>
              <span class="section-kicker">{{ __('messages.Collection') }}</span>
              <h2 class="section-title mt-1">{{ $title }}</h2>
            </div>
            <a class="text-sm font-medium text-accent-500 hover:underline inline-flex items-center gap-1"
               href="{{ route('store.shop', ['collection' => $col->slug]) }}">
              {{ __('messages.ViewAll') }}
              <x-store.icon name="arrow-right" class="w-4 h-4" />
            </a>
          </div>

          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 lg:gap-6">
            @foreach($prods as $p)
              @include('store.partials.product-card', ['p' => $p, 'currency' => $currency])
            @endforeach
          </div>
        </div>
      </section>
      @endif
      @break

    @case('best_sellers')
    @case('you_may_like')
      @php
        $prods = $block['products'] ?? collect();
        $secTitle = $block['title'] ?? '';
        $kicker = $block['type'] === 'best_sellers'
            ? __('messages.BestSellers')
            : __('messages.YouMayAlsoLike');
      @endphp
      @if($prods->count())
      <section class="py-12 lg:py-16">
        <div class="container">
          <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-8">
            <div>
              <span class="section-kicker">{{ $kicker }}</span>
              <h2 class="section-title mt-1">{{ $secTitle }}</h2>
            </div>
          </div>

          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 lg:gap-6">
            @foreach($prods as $p)
              @include('store.partials.product-card', ['p' => $p, 'currency' => $currency])
            @endforeach
          </div>
        </div>
      </section>
      @endif
      @break

    @case('flash_sale')
      @php
        $prods = $block['products'] ?? collect();
        $fsTitle = $block['title'] ?? __('messages.FlashSale');
        $endsAt = $block['ends_at'] ?? null;
      @endphp
      @if($prods->count())
      <section class="py-12 lg:py-16" style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .07), rgb(var(--color-bg-surface)));">
        <div class="container">
          <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-8">
            <div>
              <span class="section-kicker inline-flex items-center gap-1"><x-store.icon name="lightning" class="w-4 h-4" />{{ __('messages.FlashSale') }}</span>
              <h2 class="section-title mt-1">{{ $fsTitle }}</h2>
              @if($endsAt)
                <div class="text-sm text-fg-muted mt-1" data-flash-ends="{{ $endsAt }}">
                  {{ __('messages.EndsIn') }} <span class="flash-countdown font-semibold text-accent-500">—</span>
                </div>
              @endif
            </div>
            <a class="text-sm font-medium text-accent-500 hover:underline inline-flex items-center gap-1"
               href="{{ route('store.flash_sales') }}">
              {{ __('messages.ViewAll') }}
              <x-store.icon name="arrow-right" class="w-4 h-4" />
            </a>
          </div>

          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 lg:gap-6">
            @foreach($prods as $p)
              @include('store.partials.product-card', ['p' => $p, 'currency' => $currency])
            @endforeach
          </div>
        </div>
      </section>
      @endif
      @break

    @case('newsletter')
      @if(!$autoPrinted)
        @include('store.partials.home-auto-sections', ['auto' => $auto, 'currency' => $currency])
        @php $autoPrinted = true; @endphp
      @endif
      @php
        $nlTitle       = $s->newsletter_title       ?? __('messages.GetFreshDealsTitle');
        $nlSubtitle    = $s->newsletter_subtitle    ?? __('messages.GetFreshDealsSubtitle');
        $nlPlaceholder = $s->newsletter_placeholder ?? __('messages.NewsletterEmailPlaceholder');
      @endphp
      <section class="py-12 lg:py-16">
        <div class="container">
          <div class="newsletter-panel text-center">
            <div class="relative flex flex-col items-center gap-4">
              <span class="section-kicker">{{ __('messages.New') }}</span>
              <h3 class="section-title">{{ $nlTitle }}</h3>
              <p class="text-fg-secondary text-sm max-w-md">{{ $nlSubtitle }}</p>
              <form id="newsletterForm" class="flex flex-col sm:flex-row gap-2.5 w-full max-w-md mt-2">
                @csrf
                <input name="email" type="email" id="newsletterEmail" class="input h-12 rounded-lg flex-1"
                       placeholder="{{ $nlPlaceholder }}" required>
                <button id="newsletterBtn" class="btn btn-primary btn-lg shrink-0" type="submit">
                  {{ $nlBtn }}
                </button>
              </form>
              <div id="newsletterMsg" class="text-sm"></div>
            </div>
          </div>
        </div>
      </section>
      @break

  @endswitch
@empty

  @if(!$printedCenter && ( ($byPos['center_left'] ?? collect())->count() || ($byPos['center_right'] ?? collect())->count() ))
    <section class="py-6">
      <div class="container">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>{!! $renderBanners($byPos['center_left'] ?? collect()) !!}</div>
          <div>{!! $renderBanners($byPos['center_right'] ?? collect()) !!}</div>
        </div>
      </div>
    </section>
    @php $printedCenter = true; @endphp
  @endif
@endforelse

@if(!$autoPrinted)
  @include('store.partials.home-auto-sections', ['auto' => $auto, 'currency' => $currency])
  @php $autoPrinted = true; @endphp
@endif

@if(!$printedCenter && ( ($byPos['center_left'] ?? collect())->count() || ($byPos['center_right'] ?? collect())->count() ))
  <section class="py-6">
    <div class="container">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div>{!! $renderBanners($byPos['center_left'] ?? collect()) !!}</div>
        <div>{!! $renderBanners($byPos['center_right'] ?? collect()) !!}</div>
      </div>
    </div>
  </section>
@endif

@if(($byPos['footer_left'] ?? collect())->count() || ($byPos['footer_right'] ?? collect())->count())
  <section class="py-10">
    <div class="container">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div>{!! $renderBanners($byPos['footer_left'] ?? collect()) !!}</div>
        <div>{!! $renderBanners($byPos['footer_right'] ?? collect()) !!}</div>
      </div>
    </div>
  </section>
@endif

{{-- Quick-view + variant-picker + newsletter logic --}}
@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])

<script>
(function(){
  var DAY = @json(__('messages.DayShort'));
  function pad(n){ return String(n).padStart(2,'0'); }
  function tick(){
    document.querySelectorAll('[data-flash-ends]').forEach(function(el){
      var ends = new Date(el.getAttribute('data-flash-ends')).getTime();
      var out = el.querySelector('.flash-countdown');
      if (!out || isNaN(ends)) return;
      var diff = Math.max(0, Math.floor((ends - Date.now())/1000));
      var d = Math.floor(diff/86400), h = Math.floor((diff%86400)/3600), m = Math.floor((diff%3600)/60), s = diff%60;
      out.textContent = (d>0 ? d+DAY+' ' : '') + pad(h)+':'+pad(m)+':'+pad(s);
    });
  }
  tick(); setInterval(tick, 1000);
})();
</script>

@endsection
