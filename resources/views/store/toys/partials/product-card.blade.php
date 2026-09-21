{{-- Toys & Baby theme product card. Same data-* contract as
     store/partials/product-card.blade.php so storefront.js (add to cart,
     wishlist toggle, quick view, request quote) keeps working. --}}
@php
  /** @var \App\Models\Product $p */
  $productSlug = (string) $p->id;
  $galleryUrls = collect($p->productGalleryFilenames())->map(fn ($f) => product_image_url_or_null($f))->filter()->values()->all();
  $imgUrl = product_image_url($p->primaryProductImageFilename());
  $descShort = \Illuminate\Support\Str::limit(strip_tags($p->note ?? ''), 600);
  $minPrice = (float) ($p->display_price ?? ($p->price ?? 0));
  $variants = collect($p->relationLoaded('variants') ? $p->variants : ($p->variants ?? []));
  $variantPayload = $variants->map(function ($v) {
    $final = (float) ($v->display_price ?? ($v->price ?? 0));
    return [
      'id' => (int) ($v->id ?? 0), 'name' => (string) ($v->name ?? ''), 'price' => (float) ($v->price ?? 0),
      'display_price' => $final, 'display_price_formatted' => store_money($final),
      'image' => product_image_url_or_null($v->image ?? null), 'stock' => (int) max(0, $v->stock ?? $v->qty ?? 0),
    ];
  })->values();
  $productStock = $variants->isEmpty() ? (int) max(0, $p->stock ?? 0) : null;
  $isPreorder = (bool) ($p->is_preorder ?? false);
  $preorderAlways = (bool) ($p->preorder_always ?? false);
  $preorderDate = $p->preorder_available_date ? store_date($p->preorder_available_date) : null;
  $quoteOnly = (($p->type ?? '') === 'is_service') || (bool) ($p->is_classified ?? false);
  $allowOverselling = isset($s) ? (bool) ($s->allow_overselling ?? true) : true;
  $hidePrices = !Auth::guard('store')->check() && isset($s) && ($s->hide_prices_for_guests ?? false);
  $currency = $currency ?? store_currency()['symbol'];

  $isPreorderActive = false;
  if ($isPreorder) {
    $outOfStock = $variants->isEmpty() ? ($productStock !== null && $productStock <= 0) : !$variantPayload->contains(fn ($v) => ($v['stock'] ?? 0) > 0);
    if ($preorderAlways || $outOfStock) { $isPreorderActive = true; }
  }
  if ($isPreorderActive || $allowOverselling) {
    $isAvailable = true;
  } elseif ($variants->isEmpty()) {
    $isAvailable = $productStock !== null && $productStock > 0;
  } else {
    $isAvailable = $variantPayload->contains(fn ($v) => ($v['stock'] ?? 0) > 0);
  }

  $compareAt = isset($p->compare_at_price) ? (float) $p->compare_at_price : null;
  $onSale = $compareAt !== null && $compareAt > $minPrice + 0.001;
  $pct = $onSale ? (int) round(($compareAt - $minPrice) / $compareAt * 100) : 0;
  $rating = isset($p->rating_avg) && $p->rating_avg !== null ? (float) $p->rating_avg : null;
  $ratingCount = (int) ($p->rating_count ?? 0);
  $isNew = $p->created_at && $p->created_at->gt(now()->subDays(30));
  $labelBadges = \App\Services\ProductLabelService::badges($p->labels ?? null);
@endphp
<article class="product-card ty-card">
  <a href="{{ route('store.product.show', $p->id) }}" class="ty-card-media" aria-label="{{ $p->name }}">
    <img src="{{ $imgUrl }}" alt="{{ $p->name }}" loading="lazy" onerror="this.onerror=null;this.src='{{ asset('images/products/no-image.png') }}'">
    <div class="ty-card-badges">
      @if($onSale && $pct > 0)<span class="ty-pill">-{{ $pct }}%</span>@endif
      @if($isPreorderActive)<span class="ty-pill ty-pill-pre">{{ __('messages.PreOrder') }}</span>
      @elseif(!$isAvailable)<span class="ty-pill ty-pill-out">{{ __('messages.OutOfStock') }}</span>
      @elseif($isNew && !$onSale)<span class="ty-pill ty-pill-new">{{ __('messages.New') }}</span>@endif
      @foreach($labelBadges as $badge)
        <span class="ty-pill ty-pill-hot">{{ $badge['text'] }}</span>
      @endforeach
    </div>
  </a>
  <div class="ty-card-actions">
    <button type="button" class="ty-card-action js-wishlist-toggle" title="{{ __('messages.AddToWishlist') }}" aria-label="{{ __('messages.AddToWishlist') }}" data-product-id="{{ $p->id }}" aria-pressed="false">
      <x-store.icon name="heart" />
    </button>
    <button type="button" class="ty-card-action ty-qv js-quick-view" title="{{ __('messages.QuickView') }}" aria-label="{{ __('messages.QuickView') }}"
            data-id="{{ $p->id }}" data-slug="{{ $productSlug }}" data-name="{{ e($p->name) }}"
            data-price="{{ number_format($minPrice, \App\utils\helpers::price_decimals(), '.', '') }}"
            data-image="{{ $imgUrl }}" data-gallery='@json($galleryUrls)' data-currency="{{ $currency }}"
            data-description="{{ e($descShort) }}" data-stock="{{ $isPreorderActive ? '' : ($productStock !== null ? $productStock : '') }}"
            data-delivery-date="{{ $preorderDate ?? '' }}" data-quote-only="{{ $quoteOnly ? '1' : '0' }}" data-variants='@json($variantPayload)'>
      <x-store.icon name="eye" />
    </button>
  </div>

  <div class="ty-card-body">
    <h3 class="ty-card-title" title="{{ $p->name }}"><a href="{{ route('store.product.show', $p->id) }}">{{ $p->name }}</a></h3>

    @if(empty($hidePrices) && !$quoteOnly)
      <div class="ty-price">
        <b>{{ store_money($minPrice) }}</b>
        @if($onSale)<s>{{ store_money($compareAt) }}</s>@endif
      </div>
    @endif

    <div class="ty-card-foot">
      @if($rating !== null && $ratingCount > 0)
        <div class="ty-stars" aria-label="{{ $rating }}/5">
          <span class="ty-stars-icons">
            @for($i = 1; $i <= 5; $i++)
              <x-store.icon name="star-fill" class="{{ $i <= round($rating) ? '' : 'is-empty' }}" />
            @endfor
          </span>
          <span>({{ \App\Support\ElectronicsTheme::compact($ratingCount) }})</span>
        </div>
      @elseif(($s->show_stock ?? true) && !$quoteOnly && $productStock !== null && !$allowOverselling)
        <span class="ty-card-stock">{{ $productStock > 0 ? __('messages.X_in_stock', ['count' => $productStock]) : __('messages.OutOfStock') }}</span>
      @else
        <span class="ty-card-stock">{{ $p->brand?->name ?? '' }}</span>
      @endif

      @if(empty($hidePrices) && !$quoteOnly)
        <button type="button" class="ty-cart-btn js-add-to-cart {{ $isPreorderActive ? 'is-pre' : '' }}"
                @if(!$isAvailable) disabled @endif
                title="{{ $isPreorderActive ? __('messages.PreOrderNow') : __('messages.AddToCart') }}"
                aria-label="{{ $isPreorderActive ? __('messages.PreOrderNow') : __('messages.AddToCart') }}"
                data-out-of-stock="{{ $isAvailable ? '0' : '1' }}" data-is-preorder="{{ $isPreorderActive ? '1' : '0' }}"
                data-id="{{ $p->id }}" data-slug="{{ $productSlug }}" data-name="{{ e($p->name) }}"
                data-price="{{ number_format($minPrice, \App\utils\helpers::price_decimals(), '.', '') }}"
                data-image="{{ $imgUrl }}" data-gallery='@json($galleryUrls)' data-currency="{{ $currency }}" data-qty="1"
                data-product-id="{{ $p->id }}" data-product-image="{{ $imgUrl }}" data-variants='@json($variantPayload)'
                data-stock="{{ $isPreorderActive ? '' : ($productStock !== null ? $productStock : '') }}"
                data-added-label="{{ __('messages.Added') }}">
          @if($isPreorderActive)<x-store.icon name="clock" />@else<x-store.icon name="cart" />@endif
        </button>
      @elseif($quoteOnly)
        <button type="button" class="ty-btn ty-btn-outline ty-btn-sm js-request-quote" data-id="{{ $p->id }}" data-name="{{ e($p->name) }}">
          <x-store.icon name="mail" />{{ __('messages.RequestQuotation') }}
        </button>
      @else
        <a href="{{ $loginUrl ?? route('store.login.show') }}" class="ty-link-all">{{ __('messages.SignIn') }}</a>
      @endif
    </div>
    <div class="js-add-status"></div>
  </div>
</article>
