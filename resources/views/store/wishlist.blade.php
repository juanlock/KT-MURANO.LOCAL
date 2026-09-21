@extends('layouts.store')

@section('content')
@php $currency = store_currency()['symbol']; @endphp

<div class="container py-8">
  <div class="flex items-end justify-between mb-6">
    <div>
      <span class="section-kicker">{{ __('messages.Account') }}</span>
      <h1 class="section-title mt-1">{{ __('messages.MyWishlist') }}</h1>
      <div class="text-sm text-fg-muted mt-1">
        <span class="wishlist-count">{{ $products->count() }}</span> {{ __('messages.items') }}
      </div>
    </div>
    <a href="{{ route('store.shop') }}" class="btn btn-outline btn-sm">
      <x-store.icon name="arrow-left" class="w-4 h-4" />{{ __('messages.ContinueShopping') }}
    </a>
  </div>

  @if($products->count())
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 lg:gap-6">
      @foreach($products as $p)
        @include('store.partials.product-card', ['p' => $p, 'currency' => $currency])
      @endforeach
    </div>
  @else
    <div class="text-center py-20 border-2 border-dashed border-line-subtle rounded-xl">
      <x-store.icon name="heart" class="w-10 h-10 mx-auto text-fg-muted" />
      <div class="mt-3 font-semibold text-fg-primary">{{ __('messages.WishlistEmpty') }}</div>
      <div class="text-sm text-fg-muted mt-1">{{ __('messages.WishlistEmptyHint') }}</div>
      <a href="{{ route('store.shop') }}" class="btn btn-primary btn-sm mt-4">
        <x-store.icon name="bag" class="w-4 h-4" />{{ __('messages.StartShopping') }}
      </a>
    </div>
  @endif
</div>

@include('store.partials.shop-modals-scripts', ['currency' => $currency])
@endsection
