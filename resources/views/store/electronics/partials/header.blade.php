{{-- Electronics theme: promo top bar + header (logo / category search /
     account, wishlist, compare, cart) + category mega-nav. Expects the shared
     layout's variables ($s, $categories, $client, $assetPath, $loginUrl…). --}}
@php
  $elOpts = $electronicsOpts ?? \App\Support\ElectronicsTheme::options($s);
  $storeNameEl = $s->store_name ?: __('messages.Store');
  // "TechNova" style wordmark: first word plain, the rest in the accent color.
  $nameParts = preg_split('/\s+/', trim($storeNameEl), 2);
  $logoLead = $nameParts[0] ?? $storeNameEl;
  $logoTail = $nameParts[1] ?? '';
  if ($logoTail === '' && mb_strlen($logoLead) > 5) {
      // Single word: split camel-case ("TechNova" → Tech + Nova) or at 40%.
      if (preg_match('/^([A-Z][a-z]+)([A-Z].*)$/u', $logoLead, $mm)) { $logoLead = $mm[1]; $logoTail = $mm[2]; }
  }
  $navCats = $categories->take(7);
  $currentCat = (string) request('category', '');
  $isDeals = request()->routeIs('store.flash_sales') || request('deals');
  $topLeft = $s->localizedText('topbar_text_left');
  $topRight = $s->localizedText('topbar_text_right');
  $localeNames = store_locales();
  $currencyOptionsEl = \App\Services\StoreCurrencyService::options();
  $activeCurrencyEl = store_currency();
@endphp

{{-- Promo top bar --}}
<div class="el-topbar">
  <div class="el-container">
    <div class="el-topbar-left">
      @if($topLeft)<b>{{ $topLeft }}</b>@endif
      @if($topLeft && $topRight)<span class="el-topbar-sep hidden md:block"></span>@endif
      @if($topRight)<span class="hidden sm:inline">{{ $topRight }}</span>@endif
      @if(!$topLeft && !$topRight)<b>{{ __('messages.TopbarLeft') }}</b>@endif
    </div>
    <div class="el-topbar-right">
      <a href="{{ $client ? route('account.orders') : $loginUrl }}"><x-store.icon name="package" class="w-3.5 h-3.5" />{{ __('messages.TrackOrder') }}</a>
      <span class="el-topbar-sep"></span>
      <a href="{{ route('store.contact') }}"><x-store.icon name="help-circle" class="w-3.5 h-3.5" />{{ __('messages.HelpCenter') }}</a>
      <span class="el-topbar-sep"></span>
      <div class="relative" x-data="dropdown()" @click.outside="close">
        <button type="button" @click="toggle"><x-store.icon name="globe" class="w-3.5 h-3.5" />{{ $localeNames[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}<x-store.icon name="chevron-down" class="w-3 h-3" /></button>
        <div x-show="open" x-cloak x-transition class="el-menu" style="min-width: 150px">
          @foreach($localeNames as $code => $label)
            <a href="{{ route('lang.switch', $code) }}" @class(['font-semibold' => app()->getLocale() === $code])>{{ $label }}</a>
          @endforeach
        </div>
      </div>
      @if($currencyOptionsEl->count() > 1)
        <span class="el-topbar-sep"></span>
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $activeCurrencyEl['code'] }}<x-store.icon name="chevron-down" class="w-3 h-3" /></button>
          <div x-show="open" x-cloak x-transition class="el-menu" style="min-width: 150px">
            @foreach($currencyOptionsEl as $co)
              <a href="{{ route('store.currency.switch', $co->id) }}" @class(['font-semibold' => (int) $co->id === (int) ($activeCurrencyEl['id'] ?? 0)])>{{ $co->code }} — {{ $co->symbol }}</a>
            @endforeach
          </div>
        </div>
      @endif
    </div>
  </div>
</div>

<header class="el-header">
  <div class="el-container">
    <div class="el-header-main">
      {{-- Mobile: menu --}}
      <button type="button" class="el-icon-btn lg:hidden" @click="window.StoreUI.open('mobileCategorySidebar')" aria-label="{{ __('messages.Menu') }}">
        <x-store.icon name="menu" class="w-6 h-6" />
      </button>

      {{-- Logo --}}
      <a href="{{ route('store.index') }}" class="el-logo" aria-label="{{ $storeNameEl }}">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameEl }}">
        @else
          <span>{{ $logoLead }}<span class="el-logo-accent">{{ $logoTail }}</span></span>
        @endif
      </a>

      {{-- Search with category scope --}}
      <form class="el-search" action="{{ route('store.shop') }}" method="GET"
            x-data="searchBox('{{ route('store.search.suggestions') }}')" @click.outside="results = []">
        <select name="category" aria-label="{{ __('messages.Categories') }}">
          <option value="">{{ __('messages.AllCategories') }}</option>
          @foreach($categories as $c)
            <option value="{{ $c->id }}" @selected($currentCat === (string) $c->id)>{{ $c->name }}</option>
          @endforeach
        </select>
        <input type="text" name="q" value="{{ request('q') }}" autocomplete="off"
               placeholder="{{ __('messages.SearchProductsBrands') }}"
               x-model="q" @input.debounce.250ms="fetch">
        <button type="submit" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" class="w-5 h-5" /></button>
        <div x-show="results.length" x-cloak class="el-search-results">
          <template x-for="p in results" :key="p.id">
            <a :href="p.url">
              <img :src="p.image_url" :alt="p.name">
              <div class="min-w-0 flex-1">
                <div class="text-sm font-medium truncate" x-text="p.name"></div>
                <div class="text-xs el-muted" x-text="p.code || ''"></div>
              </div>
              <div class="text-sm font-bold" style="color: var(--el-accent)" x-text="window.__HIDE_PRICES__ ? '' : ('{{ $s->currency_code ?? '$' }}' + p.display_price)"></div>
            </a>
          </template>
        </div>
      </form>

      {{-- Actions --}}
      <div class="el-actions">
        @if(!empty($elOpts['show_theme_toggle']))
          <button type="button" class="el-icon-btn" onclick="window.StoreTheme && window.StoreTheme.toggle()" aria-label="{{ __('messages.ToggleTheme') }}">
            <x-store.icon name="moon" class="w-5 h-5 inline dark:hidden" />
            <x-store.icon name="sun" class="w-5 h-5 hidden dark:inline" />
          </button>
        @endif

        {{-- Account --}}
        @if($client)
          <div class="relative" x-data="dropdown()" @click.outside="close">
            <button type="button" class="el-action" @click="toggle">
              <span class="el-action-icon">
                @if($avatarSrc)<img src="{{ $avatarSrc }}" alt="" class="w-full h-full rounded-[11px] object-cover">@else<x-store.icon name="user" />@endif
              </span>
              <span class="el-action-text"><small>{{ __('messages.Hello') }},</small><strong>{{ $displayName }}</strong></span>
            </button>
            <div x-show="open" x-cloak x-transition class="el-menu">
              <a href="{{ $accountUrl }}"><x-store.icon name="user" />{{ __('messages.Profile') }}</a>
              <a href="{{ $ordersUrl }}"><x-store.icon name="package" />{{ __('messages.Orders') }}</a>
              <a href="{{ route('store.wishlist') }}"><x-store.icon name="heart" />{{ __('messages.MyWishlist') }}</a>
              <a href="{{ route('account.returns') }}"><x-store.icon name="rotate-ccw" />{{ __('messages.MyReturns') }}</a>
              @if($s->wallet_enabled ?? false)<a href="{{ route('account.wallet') }}"><x-store.icon name="wallet" />{{ __('messages.MyWallet') }}</a>@endif
              <a href="{{ route('account.rewards') }}"><x-store.icon name="gift" />{{ __('messages.MyRewards') }}</a>
              <hr>
              <form method="POST" action="{{ $logoutUrl }}">@csrf<button type="submit" class="el-danger"><x-store.icon name="log-out" />{{ __('messages.Logout') }}</button></form>
            </div>
          </div>
        @else
          <a href="{{ $loginUrl }}" class="el-action">
            <span class="el-action-icon"><x-store.icon name="user" /></span>
            <span class="el-action-text"><small>{{ __('messages.Account') }}</small><strong>{{ __('messages.SignIn') }}</strong></span>
          </a>
        @endif

        {{-- Wishlist --}}
        <a href="{{ $client ? route('store.wishlist') : $loginUrl }}" class="el-action hidden sm:inline-flex">
          <span class="el-action-icon"><x-store.icon name="heart" /><span class="el-badge wishlist-count">0</span></span>
          <span class="el-action-text"><small>{{ __('messages.Saved') }}</small><strong>{{ __('messages.Wishlist') }}</strong></span>
        </a>

        {{-- Compare --}}
        <a href="{{ route('store.compare') }}" class="el-action hidden md:inline-flex" x-data="{ n: 0, read() { try { var raw = localStorage.getItem('shop.compare.v1') || '[]'; var v = JSON.parse(raw); this.n = Array.isArray(v) ? v.length : Object.keys(v || {}).length; } catch (e) { this.n = 0; } } }" x-init="read(); window.addEventListener('storage', () => read()); window.addEventListener('compare:changed', () => read())">
          <span class="el-action-icon"><x-store.icon name="shuffle" /><span class="el-badge" x-text="n" x-show="n > 0" x-cloak></span></span>
          <span class="el-action-text"><small>{{ __('messages.Products') }}</small><strong>{{ __('messages.Compare') }}</strong></span>
        </a>

        {{-- Cart --}}
        <button type="button" class="el-action el-action-cart" @click="window.StoreUI.open('miniCart')" aria-label="{{ __('messages.Cart') }}">
          <span class="el-action-icon"><x-store.icon name="cart" /><span class="el-badge cart-count">0</span></span>
          <span class="el-action-text"><small>{{ __('messages.Cart') }}</small><strong class="el-cart-total">{{ store_money(0) }}</strong></span>
        </button>
      </div>
    </div>

    {{-- Mobile search --}}
    <div class="el-mobile-search">
      <form action="{{ route('store.shop') }}" method="GET">
        <input type="text" name="q" value="{{ request('q') }}" placeholder="{{ __('messages.SearchProducts') }}" autocomplete="off">
        <button type="submit" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" class="w-4 h-4" /></button>
      </form>
    </div>
  </div>

  {{-- Category navigation --}}
  <nav class="el-nav" aria-label="{{ __('messages.Categories') }}">
    <div class="el-container">
      <ul>
        <li><a href="{{ route('store.index') }}" class="el-nav-link {{ request()->routeIs('store.index') ? 'is-active' : '' }}">{{ __('messages.Home') }}</a></li>
        @foreach($navCats as $category)
          @php $subs = $category->subcategories ?? collect(); @endphp
          <li>
            <a href="{{ route('store.shop', ['category' => $category->id]) }}" class="el-nav-link {{ $currentCat === (string) $category->id ? 'is-active' : '' }}">
              {{ $category->name }}
              @if($subs->count())<x-store.icon name="chevron-down" />@endif
            </a>
            @if($subs->count())
              <div class="el-mega">
                <div class="el-mega-title">{{ $category->name }}</div>
                @foreach($subs as $sub)
                  <a href="{{ route('store.shop', ['category' => $category->id, 'sub_category' => $sub->id]) }}">{{ $sub->name }}</a>
                @endforeach
                <a href="{{ route('store.shop', ['category' => $category->id]) }}" style="color: var(--el-accent); font-weight: 600">{{ __('messages.ViewAll') }} →</a>
              </div>
            @endif
          </li>
        @endforeach
        @if($categories->count() > $navCats->count())
          <li>
            <a href="{{ route('store.shop') }}" class="el-nav-link">{{ __('messages.More') }}<x-store.icon name="chevron-down" /></a>
            <div class="el-mega">
              @foreach($categories->slice($navCats->count()) as $category)
                <a href="{{ route('store.shop', ['category' => $category->id]) }}">{{ $category->name }}</a>
              @endforeach
            </div>
          </li>
        @endif
        <li><a href="{{ route('store.shop', ['deals' => 1]) }}" class="el-nav-link el-deal {{ $isDeals ? 'is-active' : '' }}"><x-store.icon name="zap" />{{ __('messages.Deals') }}</a></li>
        <li>
          <a href="{{ route('store.contact') }}" class="el-nav-link">{{ __('messages.Support') }}<x-store.icon name="chevron-down" /></a>
          <div class="el-mega">
            <a href="{{ route('store.contact') }}">{{ __('messages.ContactUs') }}</a>
            <a href="{{ $client ? route('account.orders') : $loginUrl }}">{{ __('messages.TrackOrder') }}</a>
            <a href="{{ $client ? route('account.returns') : $loginUrl }}">{{ __('messages.ReturnsRefunds') }}</a>
            @foreach($headerMenu as $mi)
              <a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a>
            @endforeach
          </div>
        </li>
      </ul>
    </div>
  </nav>
</header>

<script>
  // Header cart total: the drawer keeps #mc-subtotal in sync; mirror it here.
  (function () {
    function sync() {
      var src = document.getElementById('mc-subtotal');
      var dst = document.querySelector('.el-cart-total');
      if (src && dst && src.textContent) dst.textContent = src.textContent;
    }
    document.addEventListener('DOMContentLoaded', function () {
      sync();
      var src = document.getElementById('mc-subtotal');
      if (src && window.MutationObserver) new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true });
      window.addEventListener('cart:updated', sync);
      setInterval(sync, 1500);
    });
  })();
</script>
