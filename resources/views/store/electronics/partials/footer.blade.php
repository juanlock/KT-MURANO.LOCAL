{{-- Electronics theme footer: brand column, link columns (admin footer menus
     with sensible defaults), contact column, payment badges. --}}
@php
  $elOpts = $electronicsOpts ?? \App\Support\ElectronicsTheme::options($s);
  $storeNameEl = $s->store_name ?: __('messages.Store');
  $nameParts = preg_split('/\s+/', trim($storeNameEl), 2);
  $logoLead = $nameParts[0] ?? $storeNameEl; $logoTail = $nameParts[1] ?? '';
  if ($logoTail === '' && preg_match('/^([A-Z][a-z]+)([A-Z].*)$/u', $logoLead, $mm)) { $logoLead = $mm[1]; $logoTail = $mm[2]; }
  $shopLinks = $footerShopMenu ?: collect($categories->take(6))->map(fn ($c) => ['label' => $c->name, 'url' => route('store.shop', ['category' => $c->id])])->all();
  $careLinks = $footerSupportMenu ?: [
      ['label' => __('messages.ContactUs'), 'url' => route('store.contact')],
      ['label' => __('messages.TrackOrder'), 'url' => $client ? route('account.orders') : $loginUrl],
      ['label' => __('messages.ReturnsRefunds'), 'url' => $client ? route('account.returns') : $loginUrl],
      ['label' => __('messages.MyWishlist'), 'url' => $client ? route('store.wishlist') : $loginUrl],
      ['label' => __('messages.Compare'), 'url' => route('store.compare')],
  ];
  $pages = \Illuminate\Support\Facades\Schema::hasTable('store_pages')
      ? \App\Models\StorePage::query()->where('published', true)->orderBy('title')->take(6)->get(['id', 'title', 'slug'])
      : collect();
  $addressLines = function_exists('store_address_lines') ? store_address_lines([$s->contact_address]) : array_filter([$s->contact_address]);
@endphp
<footer class="el-footer store-footer">
  <div class="el-container">
    <div class="el-footer-grid">
      <div class="el-footer-brand">
        <a href="{{ route('store.index') }}" class="el-logo">
          @if(!empty($s->logo_path))
            <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameEl }}">
          @else
            <span>{{ $logoLead }}<span class="el-logo-accent">{{ $logoTail }}</span></span>
          @endif
        </a>
        <p>{{ $s->localizedText('footer_text') ?? __('messages.FooterAbout') }}</p>
        @if(!empty($social))
          <div class="el-social">
            @foreach($social as $item)
              @php
                $platform = is_array($item) ? ($item['platform'] ?? '') : '';
                $url = is_array($item) ? ($item['url'] ?? '') : '';
                $iconName = strtolower(trim($platform)); if ($iconName === 'x') $iconName = 'twitter-x';
              @endphp
              @if($platform && $url)
                <a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($platform) }}"><x-store.icon :name="$iconName" /></a>
              @endif
            @endforeach
          </div>
        @endif
      </div>

      <div>
        <h6>{{ __('messages.Shop') }}</h6>
        <ul>
          <li><a href="{{ route('store.shop') }}">{{ __('messages.AllProducts') }}</a></li>
          @foreach($shopLinks as $mi)
            <li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>
          @endforeach
          <li><a href="{{ route('store.shop', ['deals' => 1]) }}">{{ __('messages.Deals') }}</a></li>
        </ul>
      </div>

      <div>
        <h6>{{ __('messages.CustomerCare') }}</h6>
        <ul>
          @foreach($careLinks as $mi)
            <li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>
          @endforeach
        </ul>
      </div>

      <div>
        <h6>{{ __('messages.Company') }}</h6>
        <ul>
          <li><a href="{{ route('store.contact') }}">{{ __('messages.AboutUs') }}</a></li>
          @foreach($pages as $pg)
            <li><a href="{{ route('store.page', $pg->slug) }}">{{ $pg->title }}</a></li>
          @endforeach
          @if($client)<li><a href="{{ $ordersUrl }}">{{ __('messages.Orders') }}</a></li>@endif
        </ul>
      </div>

      <div>
        <h6>{{ __('messages.Information') }}</h6>
        <ul>
          <li><a href="{{ route('store.flash_sales') }}">{{ __('messages.FlashSale') }}</a></li>
          <li><a href="{{ $client ? route('account.rewards') : $loginUrl }}">{{ __('messages.MyRewards') }}</a></li>
          @if($s->cookie_consent_enabled ?? true)
            <li><a href="#" onclick="window.openCookieSettings && window.openCookieSettings(); return false;">{{ __('messages.CookieSettings') }}</a></li>
          @endif
          <li><a href="{{ url('sitemap.xml') }}">Sitemap</a></li>
        </ul>
      </div>

      <div>
        <h6>{{ __('messages.GetInTouch') }}</h6>
        <ul class="el-footer-contact">
          @if($s->contact_phone)<li><x-store.icon name="phone" /><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
          @if($s->contact_email)<li><x-store.icon name="mail" /><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
          @if(!empty($addressLines))<li><x-store.icon name="map-pin" /><span>{!! implode('<br>', array_map('e', (array) $addressLines)) !!}</span></li>@endif
        </ul>
      </div>
    </div>

    <div class="el-footer-bottom">
      <span>© {{ date('Y') }} {{ $storeNameEl }}. {{ __('messages.AllRightsReserved') }}</span>
      @if(!empty($elOpts['payment_icons']))
        <div class="el-pay" aria-label="{{ __('messages.SecurePayment') }}">
          <span>VISA</span>
          <span class="el-pay-mc"><i></i><i></i></span>
          <span class="el-pay-pp">PayPal</span>
          <span class="el-pay-ap"> Pay</span>
          <span class="el-pay-gp"><b>G</b>&nbsp;Pay</span>
        </div>
      @endif
    </div>
  </div>
</footer>
