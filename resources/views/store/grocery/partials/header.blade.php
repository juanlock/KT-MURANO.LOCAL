{{-- Grocery & Supermarket theme: delivery top bar + header (logo, delivery
     chip, category-scoped search, account / wishlist / cart) + category nav
     with an "All categories" mega menu. Uses the shared layout's variables. --}}
@php
  $grOpts = $electronicsOpts ?? \App\Support\GroceryTheme::options($s);
  $storeNameGr = $s->store_name ?: __('messages.Store');
  $nameParts = preg_split('/\s+/', trim($storeNameGr), 2);
  $logoLead = $nameParts[0] ?? $storeNameGr; $logoTail = $nameParts[1] ?? '';
  if ($logoTail === '' && preg_match('/^([A-Z][a-z]+)([A-Z].*)$/u', $logoLead, $mm)) { $logoLead = $mm[1]; $logoTail = $mm[2]; }
  $navCats = $categories->take(6);
  $currentCat = (string) request('category', '');
  $isDeals = request()->routeIs('store.flash_sales') || request('deals');
  $localeNames = store_locales();
  $currencyOptionsGr = \App\Services\StoreCurrencyService::options();
  $activeCurrencyGr = store_currency();
  $deliveryText = $grOpts['delivery_text'] ?: ($s->localizedText('topbar_text_left') ?: __('messages.TopbarLeft'));
@endphp

<div class="gr-topbar">
  <div class="gr-container">
    <div class="gr-topbar-left"><x-store.icon name="truck" /><span class="truncate">{{ $deliveryText }}</span></div>
    <div class="gr-topbar-right">
      <a href="{{ $client ? route('account.orders') : $loginUrl }}"><x-store.icon name="package" />{{ __('messages.TrackOrder') }}</a>
      <a href="{{ route('store.contact') }}"><x-store.icon name="help-circle" />{{ __('messages.HelpCenter') }}</a>
      @if($currencyOptionsGr->count() > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $activeCurrencyGr['code'] }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="gr-menu" style="min-width: 150px">
            @foreach($currencyOptionsGr as $co)<a href="{{ route('store.currency.switch', $co->id) }}">{{ $co->code }} — {{ $co->symbol }}</a>@endforeach
          </div>
        </div>
      @endif
      <div class="relative" x-data="dropdown()" @click.outside="close">
        <button type="button" @click="toggle"><x-store.icon name="globe" />{{ $localeNames[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}<x-store.icon name="chevron-down" /></button>
        <div x-show="open" x-cloak x-transition class="gr-menu" style="min-width: 150px">
          @foreach($localeNames as $code => $label)<a href="{{ route('lang.switch', $code) }}">{{ $label }}</a>@endforeach
        </div>
      </div>
    </div>
  </div>
</div>

<header class="gr-header">
  <div class="gr-container">
    <div class="gr-header-main">
      <button type="button" class="gr-icon-btn lg:hidden" @click="window.StoreUI.open('mobileCategorySidebar')" aria-label="{{ __('messages.Menu') }}"><x-store.icon name="menu" class="w-6 h-6" /></button>

      <a href="{{ route('store.index') }}" class="gr-logo" aria-label="{{ $storeNameGr }}">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameGr }}">
        @else
          <span class="gr-logo-mark"><x-store.icon name="basket" /></span>
          <span>{{ $logoLead }}@if($logoTail !== '') <em>{{ $logoTail }}</em>@endif</span>
        @endif
      </a>

      @if(!empty($grOpts['delivery_time']))
        <div class="gr-deliver">
          <span class="gr-deliver-ic"><x-store.icon name="timer" /></span>
          <span><small>{{ __('messages.DeliverTo') }}</small><strong>{{ $grOpts['delivery_time'] }}</strong></span>
        </div>
      @endif

      <form class="gr-search" action="{{ route('store.shop') }}" method="GET" x-data="searchBox('{{ route('store.search.suggestions') }}')" @click.outside="results = []">
        <select name="category" aria-label="{{ __('messages.Categories') }}">
          <option value="">{{ __('messages.AllCategories') }}</option>
          @foreach($categories as $c)<option value="{{ $c->id }}" @selected($currentCat === (string) $c->id)>{{ $c->name }}</option>@endforeach
        </select>
        <input type="text" name="q" value="{{ request('q') }}" autocomplete="off" placeholder="{{ __('messages.SearchGroceriesPlaceholder') }}" x-model="q" @input.debounce.250ms="fetch">
        <button type="submit" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" /></button>
        <div x-show="results.length" x-cloak class="gr-search-results">
          <template x-for="p in results" :key="p.id">
            <a :href="p.url">
              <img :src="p.image_url" :alt="p.name">
              <div class="min-w-0 flex-1"><div class="text-sm font-semibold truncate" x-text="p.name"></div><div class="text-xs" style="color:var(--gr-muted)" x-text="p.code || ''"></div></div>
              <div class="text-sm font-bold" style="color: var(--gr-green)" x-text="window.__HIDE_PRICES__ ? '' : ('{{ $s->currency_code ?? '$' }}' + p.display_price)"></div>
            </a>
          </template>
        </div>
      </form>

      <div class="gr-actions">
        @if(!empty($grOpts['show_theme_toggle']))
          <button type="button" class="gr-icon-btn" onclick="window.StoreTheme && window.StoreTheme.toggle()" aria-label="{{ __('messages.ToggleTheme') }}"><x-store.icon name="moon" class="w-5 h-5 inline dark:hidden" /><x-store.icon name="sun" class="w-5 h-5 hidden dark:inline" /></button>
        @endif
        @if($client)
          <div class="relative" x-data="dropdown()" @click.outside="close">
            <button type="button" class="gr-action" @click="toggle">
              <span class="gr-action-icon">@if($avatarSrc)<img src="{{ $avatarSrc }}" alt="" class="w-full h-full rounded-[11px] object-cover">@else<x-store.icon name="user" />@endif</span>
              <span class="gr-action-text"><small>{{ __('messages.Hello') }},</small><strong>{{ $displayName }}</strong></span>
            </button>
            <div x-show="open" x-cloak x-transition class="gr-menu">
              <a href="{{ $accountUrl }}"><x-store.icon name="user" />{{ __('messages.Profile') }}</a>
              <a href="{{ $ordersUrl }}"><x-store.icon name="package" />{{ __('messages.Orders') }}</a>
              <a href="{{ route('store.wishlist') }}"><x-store.icon name="heart" />{{ __('messages.MyWishlist') }}</a>
              <a href="{{ route('account.returns') }}"><x-store.icon name="rotate-ccw" />{{ __('messages.MyReturns') }}</a>
              @if($s->wallet_enabled ?? false)<a href="{{ route('account.wallet') }}"><x-store.icon name="wallet" />{{ __('messages.MyWallet') }}</a>@endif
              <a href="{{ route('account.rewards') }}"><x-store.icon name="gift" />{{ __('messages.MyRewards') }}</a>
              <hr>
              <form method="POST" action="{{ $logoutUrl }}">@csrf<button type="submit" class="gr-danger"><x-store.icon name="log-out" />{{ __('messages.Logout') }}</button></form>
            </div>
          </div>
        @else
          <a href="{{ $loginUrl }}" class="gr-action">
            <span class="gr-action-icon"><x-store.icon name="user" /></span>
            <span class="gr-action-text"><small>{{ __('messages.Account') }}</small><strong>{{ __('messages.SignIn') }}</strong></span>
          </a>
        @endif
        <a href="{{ $client ? route('store.wishlist') : $loginUrl }}" class="gr-action hidden sm:inline-flex">
          <span class="gr-action-icon"><x-store.icon name="heart" /><span class="gr-badge wishlist-count">0</span></span>
          <span class="gr-action-text"><small>{{ __('messages.Saved') }}</small><strong>{{ __('messages.Wishlist') }}</strong></span>
        </a>
        <button type="button" class="gr-action gr-action-cart" @click="window.StoreUI.open('miniCart')" aria-label="{{ __('messages.Cart') }}">
          <span class="gr-action-icon"><x-store.icon name="cart" /><span class="gr-badge cart-count">0</span></span>
          <span class="gr-action-text"><small>{{ __('messages.Cart') }}</small><strong class="gr-cart-total">{{ store_money(0) }}</strong></span>
        </button>
      </div>
    </div>

    <div class="gr-mobile-search">
      <form action="{{ route('store.shop') }}" method="GET">
        <input type="text" name="q" value="{{ request('q') }}" placeholder="{{ __('messages.SearchProducts') }}" autocomplete="off">
        <button type="submit" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" /></button>
      </form>
    </div>
  </div>

  <nav class="gr-nav" aria-label="{{ __('messages.Categories') }}">
    <div class="gr-container">
      <ul>
        <li>
          <a href="{{ route('store.shop') }}" class="gr-nav-all"><x-store.icon name="grid" />{{ __('messages.AllCategories') }}<x-store.icon name="chevron-down" /></a>
          <div class="gr-mega">
            <div class="gr-mega-grid">
              @foreach($categories as $category)
                <a href="{{ route('store.shop', ['category' => $category->id]) }}">{{ $category->name }}</a>
              @endforeach
            </div>
          </div>
        </li>
        <li><a href="{{ route('store.index') }}" class="gr-nav-link {{ request()->routeIs('store.index') ? 'is-active' : '' }}"><x-store.icon name="home" />{{ __('messages.Home') }}</a></li>
        @foreach($navCats as $category)
          @php $subs = $category->subcategories ?? collect(); $icon = $category->icon ?: \App\Support\GroceryTheme::guessIcon($category->name); @endphp
          <li>
            <a href="{{ route('store.shop', ['category' => $category->id]) }}" class="gr-nav-link {{ $currentCat === (string) $category->id ? 'is-active' : '' }}"><x-store.icon :name="$icon" />{{ $category->name }}</a>
            @if($subs->count())
              <div class="gr-mega">
                @foreach($subs as $sub)<a href="{{ route('store.shop', ['category' => $category->id, 'sub_category' => $sub->id]) }}">{{ $sub->name }}</a>@endforeach
              </div>
            @endif
          </li>
        @endforeach
        <li><a href="{{ route('store.shop', ['deals' => 1]) }}" class="gr-nav-link gr-deal {{ $isDeals ? 'is-active' : '' }}"><x-store.icon name="percent" />{{ __('messages.WeeklyDeals') }}</a></li>
        <li><a href="{{ route('store.shop', ['sort' => 'latest']) }}" class="gr-nav-link"><x-store.icon name="sparkles" />{{ __('messages.NewArrivals') }}</a></li>
      </ul>
    </div>
  </nav>
</header>

<script>
  (function () {
    function sync() { var src = document.getElementById('mc-subtotal'); var dst = document.querySelector('.gr-cart-total'); if (src && dst && src.textContent) dst.textContent = src.textContent; }
    document.addEventListener('DOMContentLoaded', function () { sync(); var src = document.getElementById('mc-subtotal'); if (src && window.MutationObserver) new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true }); setInterval(sync, 1500); });
  })();
</script>
