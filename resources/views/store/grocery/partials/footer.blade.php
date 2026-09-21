{{-- Grocery & Supermarket theme footer. --}}
@php
  $grOpts = $electronicsOpts ?? \App\Support\GroceryTheme::options($s);
  $storeNameGr = $s->store_name ?: __('messages.Store');
  $nameParts = preg_split('/\s+/', trim($storeNameGr), 2);
  $logoLead = $nameParts[0] ?? $storeNameGr; $logoTail = $nameParts[1] ?? '';
  if ($logoTail === '' && preg_match('/^([A-Z][a-z]+)([A-Z].*)$/u', $logoLead, $mm)) { $logoLead = $mm[1]; $logoTail = $mm[2]; }
  $shopLinks = $footerShopMenu ?: collect($categories->take(6))->map(fn ($c) => ['label' => $c->name, 'url' => route('store.shop', ['category' => $c->id])])->all();
  $careLinks = $footerSupportMenu ?: [
      ['label' => __('messages.TrackOrder'), 'url' => $client ? route('account.orders') : $loginUrl],
      ['label' => __('messages.ReturnsRefunds'), 'url' => $client ? route('account.returns') : $loginUrl],
      ['label' => __('messages.ContactUs'), 'url' => route('store.contact')],
      ['label' => __('messages.MyWishlist'), 'url' => $client ? route('store.wishlist') : $loginUrl],
      ['label' => __('messages.MyRewards'), 'url' => $client ? route('account.rewards') : $loginUrl],
  ];
  $pages = \Illuminate\Support\Facades\Schema::hasTable('store_pages') ? \App\Models\StorePage::query()->where('published', true)->orderBy('title')->take(6)->get(['id', 'title', 'slug']) : collect();
@endphp
<footer class="gr-footer store-footer">
  <div class="gr-container">
    <div class="gr-footer-grid">
      <div class="gr-footer-brand">
        <a href="{{ route('store.index') }}" class="gr-logo">
          @if(!empty($s->logo_path))<img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameGr }}">@else<span class="gr-logo-mark"><x-store.icon name="basket" /></span><span>{{ $logoLead }}@if($logoTail !== '') <em>{{ $logoTail }}</em>@endif</span>@endif
        </a>
        <p>{{ $s->localizedText('footer_text') ?? __('messages.FooterAbout') }}</p>
        @if(!empty($social))
          <div class="gr-social">
            @foreach($social as $item)
              @php $platform = strtolower(trim(is_array($item) ? ($item['platform'] ?? '') : '')); $url = is_array($item) ? ($item['url'] ?? '') : ''; if ($platform === 'x') $platform = 'twitter-x'; @endphp
              @if($platform && $url)<a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ $platform }}"><x-store.icon :name="$platform" /></a>@endif
            @endforeach
          </div>
        @endif
        <div class="gr-hours"><x-store.icon name="clock" />{{ $grOpts['delivery_text'] ?: __('messages.TopbarLeft') }}</div>
      </div>
      <div>
        <h6>{{ __('messages.Shop') }}</h6>
        <ul>
          <li><a href="{{ route('store.shop') }}">{{ __('messages.AllProducts') }}</a></li>
          @foreach($shopLinks as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach
          <li><a href="{{ route('store.shop', ['deals' => 1]) }}">{{ __('messages.WeeklyDeals') }}</a></li>
        </ul>
      </div>
      <div>
        <h6>{{ __('messages.CustomerCare') }}</h6>
        <ul>@foreach($careLinks as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach</ul>
      </div>
      <div>
        <h6>{{ __('messages.Company') }}</h6>
        <ul>
          <li><a href="{{ route('store.contact') }}">{{ __('messages.AboutUs') }}</a></li>
          @foreach($pages as $pg)<li><a href="{{ route('store.page', $pg->slug) }}">{{ $pg->title }}</a></li>@endforeach
          <li><a href="{{ route('store.flash_sales') }}">{{ __('messages.FlashSale') }}</a></li>
          @if($s->cookie_consent_enabled ?? true)<li><a href="#" onclick="window.openCookieSettings && window.openCookieSettings(); return false;">{{ __('messages.CookieSettings') }}</a></li>@endif
        </ul>
      </div>
      <div>
        <h6>{{ __('messages.GetInTouch') }}</h6>
        <ul class="gr-footer-contact">
          @if($s->contact_phone)<li><x-store.icon name="phone" /><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
          @if($s->contact_email)<li><x-store.icon name="mail" /><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
          @if($s->contact_address)<li><x-store.icon name="map-pin" /><span>{{ $s->contact_address }}</span></li>@endif
        </ul>
      </div>
    </div>
    <div class="gr-footer-bottom">
      <span>© {{ date('Y') }} {{ $storeNameGr }}. {{ __('messages.AllRightsReserved') }}</span>
      @if(!empty($grOpts['payment_icons']))
        <div class="gr-pay" aria-label="{{ __('messages.SecurePayment') }}">
          <span>VISA</span><span class="gr-pay-mc"><i></i><i></i></span><span class="gr-pay-pp">PayPal</span><span class="gr-pay-cod">{{ __('messages.CashOnDelivery') }}</span>
        </div>
      @endif
    </div>
  </div>
</footer>
