@php
  /** @var \App\Models\Product $p */
  $productSlug = $p->slug ?? (string) $p->id;
  $galleryFilenames = $p->productGalleryFilenames();
  $galleryUrls = collect($galleryFilenames)
    ->map(fn ($f) => product_image_url_or_null($f))
    ->filter()
    ->values()
    ->all();
  $primaryFile = $p->primaryProductImageFilename();
  $imgUrl = product_image_url($primaryFile);
  $descShort = \Illuminate\Support\Str::limit(strip_tags($p->note ?? ''), 600);
  $minPrice  = (float) ($p->display_price ?? ($p->price ?? 0));
  $variants  = $p->relationLoaded('variants') ? $p->variants : collect($p->variants ?? []);
  $variants  = collect($variants);
  $variantPayload = $variants->map(function($v) use ($currency) {
    $final = (float) ($v->display_price ?? ($v->price ?? 0));
    return [
      'id' => (int) ($v->id ?? 0),
      'name' => (string) ($v->name ?? ''),
      'price' => (float) ($v->price ?? 0),
      'display_price' => $final,
      'display_price_formatted' => store_money($final),
      'image' => product_image_url_or_null($v->image ?? null),
      'stock' => (int) max(0, $v->stock ?? $v->qty ?? 0),
    ];
  })->values();
  $productStock = $variants->isEmpty() ? (int) max(0, $p->stock ?? 0) : null;

  $isPreorder = (bool) ($p->is_preorder ?? false);
  $preorderAlways = (bool) ($p->preorder_always ?? false);
  $preorderDate = $p->preorder_available_date ? store_date($p->preorder_available_date) : null;

  // Service or classified-ad products are inquiry-only (Request a Quotation).
  $quoteOnly = (($p->type ?? '') === 'is_service') || (bool) ($p->is_classified ?? false);

  $allowOverselling = isset($s) ? (bool) ($s->allow_overselling ?? true) : true;
  $hidePrices = !Auth::guard('store')->check() && isset($s) && ($s->hide_prices_for_guests ?? false);

  $isPreorderActive = false;
  if ($isPreorder) {
    $outOfStock = $variants->isEmpty()
      ? ($productStock !== null && $productStock <= 0)
      : !$variantPayload->contains(fn($v) => ($v['stock'] ?? 0) > 0);
    // "Pure pre-order" always shows as pre-order; otherwise only once out of stock.
    if ($preorderAlways || $outOfStock) { $isPreorderActive = true; }
  }

  if ($isPreorderActive) {
    $isAvailable = true;
    $availabilityLabel = $preorderDate
      ? __('messages.PreorderAvailableOn', ['date' => $preorderDate])
      : __('messages.PreorderAvailable');
    $stockDotClass = 'stock-dot-warn';
  } elseif ($allowOverselling) {
    $isAvailable = true;
    $availabilityLabel = null;
    $stockDotClass = 'stock-dot-ok';
  } else {
    if ($variants->isEmpty()) {
      $isAvailable = $productStock !== null && $productStock > 0;
      $availabilityLabel = $productStock !== null
        ? ($productStock > 0 ? __('messages.X_in_stock', ['count' => $productStock]) : __('messages.OutOfStock'))
        : null;
    } else {
      $isAvailable = $variantPayload->contains(fn($v) => ($v['stock'] ?? 0) > 0);
      $availabilityLabel = $isAvailable ? __('messages.InStock') : __('messages.OutOfStock');
    }
    $stockDotClass = $isAvailable ? 'stock-dot-ok' : 'stock-dot-out';
  }
@endphp

<article class="product-card">
  <a href="{{ route('store.product.show', $p->id) }}" class="product-media"
     aria-label="{{ $p->name }}">
    <img src="{{ $imgUrl }}" alt="{{ $p->name }}" loading="lazy">

    {{-- Automatic state badge first, then the admin's own labels, stacked so
         several never sit on top of each other. --}}
    <div class="product-badges">
      @php
        $cardCompare = isset($p->compare_at_price) ? (float) $p->compare_at_price : null;
        $cardPct = ($cardCompare !== null && $cardCompare > $minPrice + 0.001) ? (int) round(($cardCompare - $minPrice) / $cardCompare * 100) : 0;
      @endphp
      @if($cardPct > 0)
        <span class="product-badge product-badge-sale">-{{ $cardPct }}%</span>
      @endif
      @if($isPreorderActive)
        <span class="product-badge product-badge-pre">{{ __('messages.PreOrder') }}</span>
      @elseif(!$isAvailable)
        <span class="product-badge product-badge-out">{{ __('messages.OutOfStock') }}</span>
      @endif
      @foreach(\App\Services\ProductLabelService::badges($p->labels ?? null) as $badge)
        <span class="product-badge {{ $badge['class'] }}">{{ $badge['text'] }}</span>
      @endforeach
    </div>

    <div class="product-actions">
      <button type="button" class="product-action-btn js-wishlist-toggle"
              title="{{ __('messages.AddToWishlist') }}"
              data-product-id="{{ $p->id }}"
              aria-pressed="false"
              aria-label="{{ __('messages.AddToWishlist') }}">
        <span class="sr-only">{{ __('messages.AddToWishlist') }}</span>
        <x-store.icon name="heart" class="w-4 h-4" />
      </button>
      <button type="button" class="product-action-btn js-quick-view"
              title="{{ __('messages.QuickView') }}"
              data-id="{{ $p->id }}"
              data-slug="{{ $productSlug }}"
              data-name="{{ e($p->name) }}"
              data-price="{{ number_format($minPrice, \App\utils\helpers::price_decimals(), '.', '') }}"
              data-image="{{ $imgUrl }}"
              data-gallery='@json($galleryUrls)'
              data-currency="{{ $currency }}"
              data-description="{{ e($descShort) }}"
              data-stock="{{ $isPreorderActive ? '' : ($productStock !== null ? $productStock : '') }}"
              data-delivery-date="{{ $preorderDate ?? '' }}"
              data-quote-only="{{ $quoteOnly ? '1' : '0' }}"
              data-variants='@json($variantPayload)'
              aria-label="{{ __('messages.QuickView') }}">
        <x-store.icon name="eye" class="w-4 h-4" />
      </button>
    </div>
  </a>

  <div class="product-body">
    <h3 class="product-title" title="{{ $p->name }}">
      <a href="{{ route('store.product.show', $p->id) }}" class="hover:text-accent-500">{{ $p->name }}</a>
    </h3>

    @php
      $compareAt = isset($p->compare_at_price) ? (float) $p->compare_at_price : null;
      $onFlash = $compareAt !== null && $compareAt > $minPrice + 0.001;
    @endphp
    @if(empty($hidePrices))
      <div class="price flex items-center gap-2 flex-wrap">
        <span>{{ store_money($minPrice) }}</span>
        @if($onFlash)
          <span class="text-sm text-fg-muted line-through">{{ store_money($compareAt) }}</span>
          <span class="chip chip-danger text-xs">-{{ round(($compareAt - $minPrice) / $compareAt * 100) }}%</span>
        @endif
      </div>
    @endif

    @if(isset($p->rating_avg) && $p->rating_avg !== null && (int) ($p->rating_count ?? 0) > 0)
      <div class="df-stars" aria-label="{{ $p->rating_avg }}/5">
        <span class="df-stars-icons">
          @for($i = 1; $i <= 5; $i++)
            <x-store.icon name="star-fill" class="{{ $i <= round((float) $p->rating_avg) ? '' : 'is-empty' }}" />
          @endfor
        </span>
        <b>{{ number_format((float) $p->rating_avg, 1) }}</b>
        <span>({{ (int) $p->rating_count }})</span>
      </div>
    @endif

    @if($availabilityLabel !== null && !$quoteOnly && ($s->show_stock ?? true))
      <div class="product-meta">
        <span class="stock-dot {{ $stockDotClass }}"></span>
        <span>{{ $availabilityLabel }}</span>
      </div>
    @endif

    @if($preorderDate)
      <div class="text-xs text-fg-muted mt-1 flex items-center gap-1">
        <x-store.icon name="truck" class="w-3.5 h-3.5" />{{ __('messages.EstimatedDelivery') }}: {{ $preorderDate }}
      </div>
    @endif

    @if($quoteOnly)
      <button type="button"
              class="btn btn-primary btn-sm btn-block btn-wrap mt-2 js-request-quote"
              data-id="{{ $p->id }}"
              data-name="{{ e($p->name) }}">
        <x-store.icon name="mail" class="w-4 h-4" />{{ __('messages.RequestQuotation') }}
      </button>
    @elseif(empty($hidePrices))
      <button type="button"
              class="btn {{ $isPreorderActive ? 'btn-warning' : 'btn-accent-soft' }} btn-sm btn-block mt-2 js-add-to-cart"
              @if(!$isAvailable) disabled @endif
              data-out-of-stock="{{ $isAvailable ? '0' : '1' }}"
              data-is-preorder="{{ $isPreorderActive ? '1' : '0' }}"
              data-id="{{ $p->id }}"
              data-slug="{{ $productSlug }}"
              data-name="{{ e($p->name) }}"
              data-price="{{ number_format($minPrice, \App\utils\helpers::price_decimals(), '.', '') }}"
              data-image="{{ $imgUrl }}"
              data-gallery='@json($galleryUrls)'
              data-currency="{{ $currency }}"
              data-qty="1"
              data-product-id="{{ $p->id }}"
              data-product-image="{{ $imgUrl }}"
              data-variants='@json($variantPayload)'
              data-stock="{{ $isPreorderActive ? '' : ($productStock !== null ? $productStock : '') }}"
              data-added-label="{{ __('messages.Added') }}">
        @if($isPreorderActive)
          <x-store.icon name="clock" class="w-4 h-4" />{{ __('messages.PreOrderNow') }}
        @else
          <x-store.icon name="cart" class="w-4 h-4" />{{ __('messages.AddToCart') }}
        @endif
      </button>
    @endif
    <div class="js-add-status text-xs text-fg-muted min-h-[1rem] mt-1"></div>
  </div>
</article>
