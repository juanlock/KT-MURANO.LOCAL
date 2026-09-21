@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  $dec = \App\utils\helpers::price_decimals();

  $rowVal = function ($p, $key) {
    switch ($key) {
      case 'sku': return $p->code ?: '—';
      case 'brand': return $p->brand->name ?? '—';
      case 'category': return $p->category->name ?? '—';
      case 'weight': return (is_numeric($p->weight) && $p->weight > 0) ? rtrim(rtrim(number_format($p->weight, 3, '.', ''), '0'), '.') : '—';
      case 'dimensions':
        $d = array_filter([$p->length, $p->width, $p->height], fn ($x) => is_numeric($x) && $x > 0);
        return count($d) === 3 ? ($p->length.' × '.$p->width.' × '.$p->height) : '—';
      case 'warranty': return ($p->warranty_period && $p->warranty_unit) ? ($p->warranty_period.' '.$p->warranty_unit) : '—';
    }
    return '—';
  };
  $rows = [
    'brand' => __('messages.Brand'),
    'category' => __('messages.Category'),
    'sku' => __('messages.SKU'),
    'weight' => __('messages.Weight'),
    'dimensions' => __('messages.Dimensions'),
    'warranty' => __('messages.Warranty'),
  ];
@endphp

<div class="container py-8">
  <div class="flex items-end justify-between mb-6">
    <div>
      <span class="section-kicker">{{ __('messages.Shop') }}</span>
      <h1 class="section-title mt-1">{{ __('messages.CompareProducts') }}</h1>
      <div class="text-sm text-fg-muted mt-1">{{ $products->count() }} {{ __('messages.items') }}</div>
    </div>
    <div class="flex gap-2">
      @if($products->count())
        <button type="button" id="cmpClear" class="btn btn-outline btn-sm">
          <x-store.icon name="trash" class="w-4 h-4" />{{ __('messages.ClearAll') }}
        </button>
      @endif
      <a href="{{ route('store.shop') }}" class="btn btn-outline btn-sm">
        <x-store.icon name="arrow-left" class="w-4 h-4" />{{ __('messages.ContinueShopping') }}
      </a>
    </div>
  </div>

  @if($products->count())
    <div class="overflow-x-auto">
      <table class="w-full border-collapse text-sm" style="min-width:640px;">
        <thead>
          <tr>
            <th class="text-left align-bottom p-3 w-40 text-fg-muted font-normal"></th>
            @foreach($products as $p)
              @php
                $pf = $p->productGalleryFilenames();
                $img = product_image_url(($pf && $pf[0]) ? $pf[0] : null);
                $price = (float) ($p->display_price ?? $p->price ?? 0);
                $vp = collect($p->relationLoaded('variants') ? $p->variants : [])->map(function ($v) use ($currency, $dec) {
                  $f = (float) ($v->display_price ?? $v->price ?? 0);
                  return ['id' => (int) $v->id, 'name' => (string) $v->name, 'price' => (float) $v->price, 'display_price' => $f,
                          'display_price_formatted' => store_money($f),
                          'image' => product_image_url_or_null($v->image ?? null), 'stock' => (int) max(0, $v->stock ?? 0)];
                })->values();
                $pStock = collect($p->relationLoaded('variants') ? $p->variants : [])->isEmpty() ? (int) max(0, $p->stock ?? 0) : null;
              @endphp
              <th class="p-3 align-top text-center" style="width:220px;">
                <div class="relative">
                  <button type="button" class="cmp-remove absolute -top-1 -right-1 text-fg-muted hover:text-danger" data-id="{{ $p->id }}" aria-label="{{ __('messages.Remove') }}">
                    <x-store.icon name="x" class="w-4 h-4" />
                  </button>
                  <a href="{{ route('store.product.show', $p->id) }}">
                    <img src="{{ $img }}" alt="{{ $p->name }}" class="w-full h-32 object-contain mb-2">
                  </a>
                  <a href="{{ route('store.product.show', $p->id) }}" class="font-medium text-fg-primary hover:text-accent-500 line-clamp-2">{{ $p->name }}</a>
                  <div class="text-lg font-bold mt-1">{{ store_money($price) }}</div>
                  <button type="button"
                          class="js-add-to-cart btn btn-primary btn-sm btn-block mt-2"
                          data-id="{{ $p->id }}"
                          data-name="{{ e($p->name) }}"
                          data-price="{{ number_format($price, $dec, '.', '') }}"
                          data-image="{{ $img }}"
                          data-currency="{{ $currency }}"
                          data-qty="1"
                          data-product-id="{{ $p->id }}"
                          data-product-image="{{ $img }}"
                          data-variants='@json($vp)'
                          data-stock="{{ $pStock !== null ? $pStock : '' }}"
                          data-added-label="{{ __('messages.Added') }}">
                    <x-store.icon name="cart" class="w-4 h-4" />{{ __('messages.AddToCart') }}
                  </button>
                </div>
              </th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @foreach($rows as $key => $label)
            <tr class="border-t border-line-subtle">
              <td class="p-3 text-fg-muted font-medium">{{ $label }}</td>
              @foreach($products as $p)
                <td class="p-3 text-center text-fg-secondary">{{ $rowVal($p, $key) }}</td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @else
    <div class="text-center py-20 border-2 border-dashed border-line-subtle rounded-xl">
      <x-store.icon name="refresh" class="w-10 h-10 mx-auto text-fg-muted" />
      <div class="mt-3 font-semibold text-fg-primary">{{ __('messages.NothingToCompare') }}</div>
      <div class="text-sm text-fg-muted mt-1">{{ __('messages.NothingToCompareHint') }}</div>
      <a href="{{ route('store.shop') }}" class="btn btn-primary btn-sm mt-4">
        <x-store.icon name="bag" class="w-4 h-4" />{{ __('messages.StartShopping') }}
      </a>
    </div>
  @endif
</div>

@include('store.partials.shop-modals-scripts', ['currency' => $currency])

<script>
(function(){
  var CMP_KEY = 'shop.compare.v1';
  function cmpGet(){ try { return JSON.parse(localStorage.getItem(CMP_KEY) || '[]'); } catch(e){ return []; } }
  function cmpSet(a){ try { localStorage.setItem(CMP_KEY, JSON.stringify(a)); } catch(e){} }
  var COMPARE_URL = @json(route('store.compare'));

  // Keep localStorage in sync with what the server actually rendered.
  var rendered = @json($products->pluck('id')->map(fn ($v) => (int) $v)->values());
  cmpSet(rendered);

  document.querySelectorAll('.cmp-remove').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = Number(btn.getAttribute('data-id'));
      var a = cmpGet().filter(function(x){ return Number(x) !== id; });
      cmpSet(a);
      window.location.href = a.length ? (COMPARE_URL + '?ids=' + a.join(',')) : COMPARE_URL;
    });
  });
  var clear = document.getElementById('cmpClear');
  if (clear){ clear.addEventListener('click', function(){ cmpSet([]); window.location.href = COMPARE_URL; }); }
})();
</script>
@endsection
