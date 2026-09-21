{{-- Toys & Baby theme footer: wave edge, brand column, Shop / Customer Care /
     About columns (admin menus with defaults), payment badges. --}}
@php
  $tyOpts = $electronicsOpts ?? \App\Support\ToysTheme::options($s);
  $storeNameTy = $s->store_name ?: __('messages.Store');
  $letters = preg_split('//u', preg_replace('/\s+/', '', $storeNameTy), -1, PREG_SPLIT_NO_EMPTY);
  $shopLinks = $footerShopMenu ?: array_merge(
      [['label' => __('messages.AllCategories'), 'url' => route('store.shop')], ['label' => __('messages.NewArrivals'), 'url' => route('store.shop', ['sort' => 'latest'])]],
      collect($categories->take(4))->map(fn ($c) => ['label' => $c->name, 'url' => route('store.shop', ['category' => $c->id])])->all(),
      [['label' => __('messages.Deals'), 'url' => route('store.shop', ['deals' => 1])]]
  );
  $careLinks = $footerSupportMenu ?: [
      ['label' => __('messages.TrackOrder'), 'url' => $client ? route('account.orders') : $loginUrl],
      ['label' => __('messages.ReturnsRefunds'), 'url' => $client ? route('account.returns') : $loginUrl],
      ['label' => __('messages.ContactUs'), 'url' => route('store.contact')],
      ['label' => __('messages.MyWishlist'), 'url' => $client ? route('store.wishlist') : $loginUrl],
      ['label' => __('messages.MyRewards'), 'url' => $client ? route('account.rewards') : $loginUrl],
  ];
  $pages = \Illuminate\Support\Facades\Schema::hasTable('store_pages')
      ? \App\Models\StorePage::query()->where('published', true)->orderBy('title')->take(6)->get(['id', 'title', 'slug'])
      : collect();
@endphp
<footer class="ty-footer store-footer">
  <div class="ty-footer-wave" aria-hidden="true">
    <svg viewBox="0 0 1440 36" preserveAspectRatio="none"><path d="M0,20 C180,40 360,0 540,14 C720,28 900,36 1080,18 C1260,0 1350,10 1440,22 L1440,36 L0,36 Z" fill="currentColor" style="color: var(--ty-accent)"/></svg>
  </div>
  <div class="ty-container">
    <div class="ty-footer-grid">
      <div class="ty-footer-brand">
        <a href="{{ route('store.index') }}" class="ty-logo">
          @if(!empty($s->logo_path))
            <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameTy }}">
          @else
            <span class="ty-logo-word">@foreach($letters as $ch)<span>{{ $ch }}</span>@endforeach</span>
            @if(!empty($tyOpts['tagline']))<span class="ty-logo-tag">{{ $tyOpts['tagline'] }}</span>@endif
          @endif
        </a>
        <p>{{ $s->localizedText('footer_text') ?? __('messages.FooterAbout') }}</p>
        @if(!empty($social))
          <div class="ty-social">
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
          @foreach($shopLinks as $mi)
            <li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>
          @endforeach
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
        <h6>{{ __('messages.AboutUs') }}</h6>
        <ul>
          <li><a href="{{ route('store.contact') }}">{{ __('messages.OurStory') }}</a></li>
          @foreach($pages as $pg)
            <li><a href="{{ route('store.page', $pg->slug) }}">{{ $pg->title }}</a></li>
          @endforeach
          <li><a href="{{ route('store.flash_sales') }}">{{ __('messages.FlashSale') }}</a></li>
          @if($s->cookie_consent_enabled ?? true)
            <li><a href="#" onclick="window.openCookieSettings && window.openCookieSettings(); return false;">{{ __('messages.CookieSettings') }}</a></li>
          @endif
        </ul>
      </div>

      <div>
        <h6>{{ __('messages.PaymentMethods') }}</h6>
        @if(!empty($tyOpts['payment_icons']))
          <div class="ty-pay" aria-label="{{ __('messages.SecurePayment') }}">
            <span>VISA</span>
            <span class="ty-pay-mc"><i></i><i></i></span>
            <span class="ty-pay-amex">AMEX</span>
            <span class="ty-pay-pp">PayPal</span>
            <span class="ty-pay-ap"> Pay</span>
            <span class="ty-pay-gp"><b>G</b>&nbsp;Pay</span>
            <span class="ty-pay-shop">shop Pay</span>
          </div>
        @endif
        <ul style="margin-top: 14px">
          @if($s->contact_email)<li><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
          @if($s->contact_phone)<li><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
        </ul>
      </div>
    </div>

    <div class="ty-footer-bottom">
      <span>© {{ date('Y') }} {{ $storeNameTy }}. {{ __('messages.AllRightsReserved') }}</span>
      <span>{{ __('messages.DesignedWith') }} <x-store.icon name="heart" /> {{ __('messages.ForLittleOnes') }}</span>
    </div>
  </div>
</footer>
