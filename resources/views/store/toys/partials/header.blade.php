{{-- Toys & Baby theme: coupon top bar + header (multicolour wordmark, pill
     search, account / wishlist / cart) + icon category nav. Expects the shared
     layout's variables ($s, $categories, $client, $assetPath, $loginUrl…). --}}
@php
  $tyOpts = $electronicsOpts ?? \App\Support\ToysTheme::options($s);
  $storeNameTy = $s->store_name ?: __('messages.Store');
  $letters = preg_split('//u', preg_replace('/\s+/', '', $storeNameTy), -1, PREG_SPLIT_NO_EMPTY);
  $letterColors = ['#7b6fd0', '#e8557a', '#2f9e6e', '#f5a623', '#2b7dc9', '#e2703a'];
  $navCats = $categories->take(7);
  $currentCat = (string) request('category', '');
  $isDeals = request()->routeIs('store.flash_sales') || request('deals');
  $localeNames = store_locales();
  $currencyOptionsTy = \App\Services\StoreCurrencyService::options();
  $activeCurrencyTy = store_currency();
  $couponText = $tyOpts['coupon_text'] ?? '';
  $couponCode = $tyOpts['coupon_code'] ?? '';
  $topLeft = $s->localizedText('topbar_text_left');
@endphp

{{-- Top bar --}}
<div class="ty-topbar">
  <div class="ty-container">
    <div class="ty-topbar-left">
      <x-store.icon name="gift" />
      <span class="truncate">{{ $couponText !== '' ? $couponText : ($topLeft ?: __('messages.TopbarLeft')) }}</span>
      @if($couponCode !== '')
        <span class="ty-coupon hidden sm:inline-flex">{{ __('messages.UseCode') }}: {{ $couponCode }}</span>
      @endif
    </div>
    <div class="ty-topbar-right">
      <a href="{{ $client ? route('account.orders') : $loginUrl }}">{{ __('messages.TrackOrder') }}</a>
      <a href="{{ route('store.contact') }}">{{ __('messages.HelpCenter') }}</a>
      @if($currencyOptionsTy->count() > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $activeCurrencyTy['code'] }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="ty-menu" style="min-width: 150px">
            @foreach($currencyOptionsTy as $co)
              <a href="{{ route('store.currency.switch', $co->id) }}">{{ $co->code }} — {{ $co->symbol }}</a>
            @endforeach
          </div>
        </div>
      @endif
      <div class="relative" x-data="dropdown()" @click.outside="close">
        <button type="button" @click="toggle"><x-store.icon name="globe" />{{ $localeNames[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}<x-store.icon name="chevron-down" /></button>
        <div x-show="open" x-cloak x-transition class="ty-menu" style="min-width: 150px">
          @foreach($localeNames as $code => $label)
            <a href="{{ route('lang.switch', $code) }}">{{ $label }}</a>
          @endforeach
        </div>
      </div>
    </div>
  </div>
</div>

<header class="ty-header">
  <div class="ty-container">
    <div class="ty-header-main">
      <button type="button" class="ty-icon-btn lg:hidden" @click="window.StoreUI.open('mobileCategorySidebar')" aria-label="{{ __('messages.Menu') }}">
        <x-store.icon name="menu" class="w-6 h-6" />
      </button>

      {{-- Logo --}}
      <a href="{{ route('store.index') }}" class="ty-logo" aria-label="{{ $storeNameTy }}">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameTy }}">
        @else
          <span class="ty-logo-word">
            @foreach($letters as $i => $ch)
              <span style="color: {{ $letterColors[$i % count($letterColors)] }}">{{ $ch }}</span>
            @endforeach
          </span>
          @if(!empty($tyOpts['tagline']))<span class="ty-logo-tag">{{ $tyOpts['tagline'] }}</span>@endif
        @endif
      </a>

      {{-- Search --}}
      <form class="ty-search" action="{{ route('store.shop') }}" method="GET"
            x-data="searchBox('{{ route('store.search.suggestions') }}')" @click.outside="results = []">
        <input type="text" name="q" value="{{ request('q') }}" autocomplete="off"
               placeholder="{{ __('messages.SearchToysPlaceholder') }}"
               x-model="q" @input.debounce.250ms="fetch">
        <button type="submit" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" /></button>
        <div x-show="results.length" x-cloak class="ty-search-results">
          <template x-for="p in results" :key="p.id">
            <a :href="p.url">
              <img :src="p.image_url" :alt="p.name">
              <div class="min-w-0 flex-1">
                <div class="text-sm font-bold truncate" x-text="p.name"></div>
                <div class="text-xs ty-muted" x-text="p.code || ''"></div>
              </div>
              <div class="text-sm font-black" style="color: var(--ty-accent)" x-text="window.__HIDE_PRICES__ ? '' : ('{{ $s->currency_code ?? '$' }}' + p.display_price)"></div>
            </a>
          </template>
        </div>
      </form>

      {{-- Actions --}}
      <div class="ty-actions">
        @if(!empty($tyOpts['show_theme_toggle']))
          <button type="button" class="ty-icon-btn" onclick="window.StoreTheme && window.StoreTheme.toggle()" aria-label="{{ __('messages.ToggleTheme') }}">
            <x-store.icon name="moon" class="w-5 h-5 inline dark:hidden" />
            <x-store.icon name="sun" class="w-5 h-5 hidden dark:inline" />
          </button>
        @endif

        @if($client)
          <div class="relative" x-data="dropdown()" @click.outside="close">
            <button type="button" class="ty-action" @click="toggle">
              <span class="ty-action-icon">
                @if($avatarSrc)<img src="{{ $avatarSrc }}" alt="" class="w-7 h-7 rounded-full object-cover">@else<x-store.icon name="user" />@endif
              </span>
              <span class="ty-action-text">{{ $displayName }}</span>
            </button>
            <div x-show="open" x-cloak x-transition class="ty-menu">
              <a href="{{ $accountUrl }}"><x-store.icon name="user" />{{ __('messages.Profile') }}</a>
              <a href="{{ $ordersUrl }}"><x-store.icon name="package" />{{ __('messages.Orders') }}</a>
              <a href="{{ route('store.wishlist') }}"><x-store.icon name="heart" />{{ __('messages.MyWishlist') }}</a>
              <a href="{{ route('account.returns') }}"><x-store.icon name="rotate-ccw" />{{ __('messages.MyReturns') }}</a>
              @if($s->wallet_enabled ?? false)<a href="{{ route('account.wallet') }}"><x-store.icon name="wallet" />{{ __('messages.MyWallet') }}</a>@endif
              <a href="{{ route('account.rewards') }}"><x-store.icon name="gift" />{{ __('messages.MyRewards') }}</a>
              <hr>
              <form method="POST" action="{{ $logoutUrl }}">@csrf<button type="submit" class="ty-danger"><x-store.icon name="log-out" />{{ __('messages.Logout') }}</button></form>
            </div>
          </div>
        @else
          <a href="{{ $loginUrl }}" class="ty-action">
            <span class="ty-action-icon"><x-store.icon name="user" /></span>
            <span class="ty-action-text">{{ __('messages.Account') }}</span>
          </a>
        @endif

        <a href="{{ $client ? route('store.wishlist') : $loginUrl }}" class="ty-action">
          <span class="ty-action-icon"><x-store.icon name="heart" /><span class="ty-badge wishlist-count">0</span></span>
          <span class="ty-action-text">{{ __('messages.Wishlist') }}</span>
        </a>

        <button type="button" class="ty-action" @click="window.StoreUI.open('miniCart')" aria-label="{{ __('messages.Cart') }}">
          <span class="ty-action-icon"><x-store.icon name="cart" /><span class="ty-badge cart-count">0</span></span>
          <span class="ty-action-text">{{ __('messages.Cart') }}</span>
        </button>
      </div>
    </div>

    <div class="ty-mobile-search">
      <form action="{{ route('store.shop') }}" method="GET">
        <input type="text" name="q" value="{{ request('q') }}" placeholder="{{ __('messages.SearchProducts') }}" autocomplete="off">
        <button type="submit" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" /></button>
      </form>
    </div>
  </div>

  {{-- Icon nav --}}
  <nav class="ty-nav" aria-label="{{ __('messages.Categories') }}">
    <div class="ty-container">
      <ul>
        <li><a href="{{ route('store.index') }}" class="ty-nav-link {{ request()->routeIs('store.index') ? 'is-active' : '' }}"><x-store.icon name="home" />{{ __('messages.Home') }}</a></li>
        @foreach($navCats as $category)
          @php $subs = $category->subcategories ?? collect(); $icon = $category->icon ?: \App\Support\ToysTheme::guessIcon($category->name); @endphp
          <li>
            <a href="{{ route('store.shop', ['category' => $category->id]) }}" class="ty-nav-link {{ $currentCat === (string) $category->id ? 'is-active' : '' }}">
              <x-store.icon :name="$icon" />{{ $category->name }}
            </a>
            @if($subs->count())
              <div class="ty-mega">
                @foreach($subs as $sub)
                  <a href="{{ route('store.shop', ['category' => $category->id, 'sub_category' => $sub->id]) }}">{{ $sub->name }}</a>
                @endforeach
              </div>
            @endif
          </li>
        @endforeach
        @if($categories->count() > $navCats->count())
          <li>
            <a href="{{ route('store.shop') }}" class="ty-nav-link"><x-store.icon name="grid" />{{ __('messages.More') }}</a>
            <div class="ty-mega">
              @foreach($categories->slice($navCats->count()) as $category)
                <a href="{{ route('store.shop', ['category' => $category->id]) }}">{{ $category->name }}</a>
              @endforeach
            </div>
          </li>
        @endif
        <li><a href="{{ route('store.shop', ['deals' => 1]) }}" class="ty-nav-link ty-deal {{ $isDeals ? 'is-active' : '' }}"><x-store.icon name="star-fill" />{{ __('messages.Deals') }}</a></li>
      </ul>
    </div>
  </nav>
</header>
